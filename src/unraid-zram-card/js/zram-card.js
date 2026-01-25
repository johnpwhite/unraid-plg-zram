// zram-card.js

(function() {
    let chartInstance = null;
    const historyLimit = 30; // Keep last 30 data points
    const historyData = {
        labels: [],
        saved: []
    };

    // Helper: Format Bytes
    function formatBytes(bytes, decimals = 2) {
        bytes = parseFloat(bytes);
        if (isNaN(bytes) || bytes <= 0) return '0 B';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = Math.floor(Math.log(bytes) / Math.log(k));
        if (i < 0) i = 0; // Ensure we don't index negative for small values
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }

    // Initialize Chart
    function initChart() {
        const canvas = document.getElementById('zramChart');
        if (!canvas) return;

        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded yet.');
            return;
        }

        const ctx = canvas.getContext('2d');

        // Unraid theme colors (approximate)
        const accentColor = '#7fba59'; // Greenish
        const gridColor = 'rgba(255, 255, 255, 0.05)';
        const textColor = '#888';

        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: historyData.labels,
                datasets: [{
                    label: 'RAM Saved',
                    data: historyData.saved,
                    borderColor: accentColor,
                    backgroundColor: 'rgba(127, 186, 89, 0.1)',
                    borderWidth: 1.5,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return 'Saved: ' + formatBytes(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: { display: false },
                    y: {
                        beginAtZero: true,
                        grace: '10%', // Add headroom at the top
                        suggestedMax: 1048576, // 1MB minimum scale
                        grid: { color: gridColor },
                        ticks: {
                            color: textColor,
                            font: { size: 10 },
                            maxTicksLimit: 6,
                            callback: function(value) {
                                // Use 1 decimal for better alignment on larger scales
                                return formatBytes(value, value >= 1048576 ? 1 : 0);
                            }
                        }
                    }
                },
                animation: { duration: 0 }
            }
        });
    }

    // Fetch and Update
    async function updateStats() {
        try {
            const config = window.ZRAM_CONFIG || { url: '/plugins/unraid-zram-card/zram_status.php', pollInterval: 3000 };
            const response = await fetch(config.url);
            if (!response.ok) throw new Error('Network response was not ok');
            const data = await response.json();

            // 1. Update Aggregates
            const aggs = data.aggregates;
            const elSaved = document.getElementById('zram-saved');
            const elRatio = document.getElementById('zram-ratio');
            const elUsed = document.getElementById('zram-used');
            
            if (elSaved) elSaved.textContent = formatBytes(aggs.memory_saved);
            if (elRatio) elRatio.textContent = aggs.compression_ratio + 'x';
            if (elUsed) elUsed.textContent = formatBytes(aggs.total_used);
            
            // Subtitle status
            const statusText = aggs.disk_size_total > 0 ? `Active (${data.devices.length} devs)` : 'Inactive';
            const sub = document.querySelector('.zram-subtitle');
            if (sub) sub.textContent = statusText;

            // 2. Update Device List (Div-based)
            const listContainer = document.getElementById('zram-device-list');
            if (listContainer) {
                if (!data.devices || data.devices.length === 0) {
                    listContainer.innerHTML = '<div style="text-align: center; opacity: 0.5; padding: 3px; font-size: 0.8em;">No ZRAM devices active.</div>';
                } else {
                    let html = `
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 4px; opacity: 0.5; font-size: 0.75em; margin-bottom: 1px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <div style="text-align: left;">Dev</div>
                            <div style="text-align: right;">Size</div>
                            <div style="text-align: right;">Used</div>
                            <div style="text-align: right;">Comp</div>
                        </div>`;
                    
                    data.devices.forEach(dev => {
                        html += `
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 4px; font-size: 0.8em; padding: 1px 0;">
                                <div style="text-align: left; font-weight: bold;">${dev.name}</div>
                                <div style="text-align: right; opacity: 0.7;">${formatBytes(dev.disksize)}</div>
                                <div style="text-align: right; opacity: 0.7;">${formatBytes(dev.total)}</div>
                                <div style="text-align: right; opacity: 0.7;">${dev.algorithm}</div>
                            </div>`;
                    });
                    listContainer.innerHTML = html;
                }
            }

            // 3. Update Chart
            if (chartInstance) {
                const now = new Date().toLocaleTimeString();
                historyData.labels.push(now);
                historyData.saved.push(aggs.memory_saved);

                if (historyData.labels.length > historyLimit) {
                    historyData.labels.shift();
                    historyData.saved.shift();
                }

                chartInstance.update();
            } else {
                initChart();
            }

        } catch (error) {
            console.error('Error fetching ZRAM stats:', error);
        }
    }

    // Initialize
    if (document.readyState === 'complete') {
        updateStats();
    } else {
        window.addEventListener('load', updateStats);
    }
    
    const config = window.ZRAM_CONFIG || { pollInterval: 3000 };
    setInterval(updateStats, config.pollInterval);

})();