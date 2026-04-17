// zram-card.js — Dashboard chart and live stats (tiered: ZRAM + SSD)

(function () {
    let chartInstance = null;
    const historyLimit = 300;
    const historyData = { labels: [], original: [], used: [], load: [] };
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
                    // Back layer: uncompressed size — muted amber, the "cost" before ZRAM
                    {
                        label: 'Uncompressed', data: historyData.original,
                        borderColor: '#c46b36', backgroundColor: 'rgba(196,107,54,0.32)',
                        borderWidth: 1.2, fill: true, tension: 0.4, pointRadius: 0,
                        yAxisID: 'y', order: 3
                    },
                    // Front layer: compressed size (actual RAM occupied)
                    {
                        label: 'Compressed', data: historyData.used,
                        borderColor: '#7fba59', backgroundColor: 'rgba(127,186,89,0.35)',
                        borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0,
                        yAxisID: 'y', order: 2
                    },
                    // CPU load: line on top, right axis
                    {
                        label: 'CPU Load', data: historyData.load,
                        borderColor: '#e57373', backgroundColor: 'rgba(229,115,115,0.1)',
                        borderWidth: 1.5, fill: false, tension: 0.4, pointRadius: 2,
                        yAxisID: 'y1', order: 1
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
                                if (ctx.dataset.yAxisID === 'y1') {
                                    return 'Load: ' + ctx.parsed.y.toFixed(1) + '%';
                                }
                                return ctx.dataset.label + ': ' + formatBytes(ctx.parsed.y);
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
            if (el('zram-uncompressed')) el('zram-uncompressed').textContent = formatBytes(aggs.total_original);
            if (el('zram-compressed')) el('zram-compressed').textContent = formatBytes(aggs.total_used);
            if (el('zram-ratio')) el('zram-ratio').textContent = aggs.compression_ratio + 'x';
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

            // Chart data — initial backfill, filtering out legacy entries missing o/u
            if (!historyLoaded && data.history && data.history.length > 0) {
                data.history.forEach(item => {
                    if (item.o === undefined || item.u === undefined) return;
                    historyData.labels.push(item.t);
                    historyData.original.push(item.o);
                    historyData.used.push(item.u);
                    historyData.load.push(item.l);
                });
                historyLoaded = true;
            } else {
                historyData.labels.push(new Date().toLocaleTimeString());
                historyData.original.push(aggs.total_original);
                historyData.used.push(aggs.total_used);
                historyData.load.push(loadPct);
            }

            while (historyData.labels.length > historyLimit) {
                historyData.labels.shift();
                historyData.original.shift();
                historyData.used.shift();
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
