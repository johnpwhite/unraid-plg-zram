// zram-card.js — Dashboard chart and live stats (tiered: ZRAM + SSD)

(function () {
    let chartInstance = null;
    const historyLimit = 300;
    const historyData = { labels: [], saved: [], load: [] };
    let lastTotalTicks = null;
    let lastTime = null;

    function formatBytes(bytes, dec = 2) {
        bytes = parseFloat(bytes);
        if (isNaN(bytes) || bytes <= 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = Math.max(0, Math.floor(Math.log(bytes) / Math.log(k)));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dec)) + ' ' + sizes[i];
    }

    function initChart() {
        const canvas = document.getElementById('zramChart');
        if (!canvas || typeof Chart === 'undefined') return;
        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: historyData.labels,
                datasets: [
                    {
                        label: 'RAM Saved', data: historyData.saved,
                        borderColor: '#7fba59', backgroundColor: 'rgba(127,186,89,0.1)',
                        borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0, yAxisID: 'y'
                    },
                    {
                        label: 'CPU Load', data: historyData.load,
                        borderColor: '#e57373', backgroundColor: 'rgba(229,115,115,0.1)',
                        borderWidth: 1.5, fill: false, tension: 0.4, pointRadius: 2, yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index', intersect: false,
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.yAxisID === 'y'
                                    ? 'Saved: ' + formatBytes(ctx.parsed.y)
                                    : 'Load: ' + ctx.parsed.y.toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: { display: false },
                    y: {
                        type: 'linear', display: true, position: 'left',
                        beginAtZero: true, grace: '10%', suggestedMax: 1048576,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#888', font: { size: 10 }, maxTicksLimit: 6,
                            callback: v => formatBytes(v, v >= 1048576 ? 1 : 0) }
                    },
                    y1: {
                        type: 'linear', display: true, position: 'right',
                        min: 0, suggestedMax: 10,
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#e57373', font: { size: 10 }, maxTicksLimit: 4,
                            callback: v => v + '%' }
                    }
                },
                animation: { duration: 0 }
            }
        });
    }

    let historyLoaded = false;
    async function updateStats() {
        try {
            const config = window.ZRAM_CONFIG || { url: '/plugins/unraid-zram-card/zram_status.php', pollInterval: 3000 };
            const resp = await fetch(config.url);
            if (!resp.ok) throw new Error('Status fetch failed');
            const data = await resp.json();
            const aggs = data.aggregates;

            // Update stat elements
            const el = id => document.getElementById(id);
            if (el('zram-saved')) el('zram-saved').textContent = formatBytes(aggs.memory_saved);
            if (el('zram-ratio')) el('zram-ratio').textContent = aggs.compression_ratio + 'x';
            if (el('zram-used')) el('zram-used').textContent = formatBytes(aggs.total_used);
            if (el('zram-swappiness')) el('zram-swappiness').textContent = aggs.swappiness;

            // CPU load from ticks
            let currentTicks = 0;
            if (data.zram_device) currentTicks = parseInt(data.zram_device.total_ticks) || 0;
            const now = Date.now();
            let loadPct = 0;
            if (lastTotalTicks !== null && lastTime !== null) {
                const dt = now - lastTime;
                if (dt > 0) loadPct = ((currentTicks - lastTotalTicks) / dt) * 100;
            }
            if (isNaN(loadPct) || loadPct < 0) loadPct = 0;
            lastTotalTicks = currentTicks;
            lastTime = now;

            if (el('zram-load')) {
                el('zram-load').textContent = loadPct.toFixed(1) + '%';
                el('zram-load').title = 'Ticks: ' + currentTicks;
            }

            // Update SSD row if present
            const ssdRow = el('zram-ssd-row');
            if (ssdRow && data.ssd_swap) {
                const s = data.ssd_swap;
                const tierEl = ssdRow.querySelector('div:first-child');
                if (tierEl) {
                    if (s.active && s.used > 0) {
                        tierEl.style.color = '#e57373';
                        tierEl.textContent = 'SSD (' + formatBytes(s.used, 0) + ')';
                    } else if (s.active) {
                        tierEl.style.color = '#00a4d8';
                        tierEl.textContent = 'SSD (idle)';
                    } else {
                        tierEl.style.color = '#666';
                        tierEl.textContent = 'SSD (off)';
                    }
                }
            }

            // Update device list dynamically
            const list = el('zram-device-list');
            if (list && data.zram_device) {
                const dev = data.zram_device;
                // Only rebuild if device changed (keep static otherwise for perf)
            }

            // Chart data
            if (!historyLoaded && data.history && data.history.length > 0) {
                data.history.forEach(item => {
                    historyData.labels.push(item.t);
                    historyData.saved.push(item.s);
                    historyData.load.push(item.l);
                });
                historyLoaded = true;
            } else {
                historyData.labels.push(new Date().toLocaleTimeString());
                historyData.saved.push(aggs.memory_saved);
                historyData.load.push(loadPct);
            }

            while (historyData.labels.length > historyLimit) {
                historyData.labels.shift();
                historyData.saved.shift();
                historyData.load.shift();
            }

            if (chartInstance) chartInstance.update();
            else initChart();

            // Pulse refresh icon
            const icon = el('zram-refresh-icon');
            if (icon) {
                icon.classList.remove('zram-pulse');
                void icon.offsetWidth;
                icon.classList.add('zram-pulse');
            }
        } catch (err) {
            console.error('ZRAM stats error:', err);
        }
    }

    if (document.readyState === 'complete') updateStats();
    else window.addEventListener('load', updateStats);

    const config = window.ZRAM_CONFIG || { pollInterval: 3000 };
    setInterval(updateStats, config.pollInterval);
})();
