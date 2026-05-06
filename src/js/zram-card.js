// zram-card.js — Dashboard chart and live stats (tiered: ZRAM + SSD)

function formatBytes(bytes, dec = 2) {
    bytes = parseFloat(bytes);
    if (isNaN(bytes) || bytes <= 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = Math.max(0, Math.floor(Math.log(bytes) / Math.log(k)));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dec)) + ' ' + sizes[i];
}

// Format a byte value for axis ticks, rounded to the nearest `step` in its
// natural unit. For graph labels — stat cards still use formatBytes() for
// precision. If the scaled value is smaller than step, drop to the next
// smaller unit so we don't lose resolution (e.g. 1 KB stays readable).
function formatBytesRound(bytes, step = 5) {
    bytes = parseFloat(bytes);
    if (isNaN(bytes) || bytes <= 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = Math.max(0, Math.floor(Math.log(bytes) / Math.log(k)));
    let scaled = bytes / Math.pow(k, i);
    while (scaled < step && i > 0) {
        i--;
        scaled = bytes / Math.pow(k, i);
    }
    const rounded = Math.round(scaled / step) * step;
    return rounded + ' ' + sizes[i];
}

// Filter a raw history array to only new-schema entries {t,o,u,l}.
// Legacy entries {t,s,l} from pre-2026.04.17 collectors are dropped so the
// chart doesn't render bogus zero points. Returns a fresh array.
function filterHistory(raw) {
    if (!Array.isArray(raw)) return [];
    return raw.filter(item => item && item.o !== undefined && item.u !== undefined);
}

// Node.js export for Vitest — no-op in the browser (module is undefined there).
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { formatBytes, formatBytesRound, filterHistory };
}

(function () {
    // Bail early in non-dashboard contexts (tests, other Unraid pages) so we
    // don't start polling timers that have nothing to render.
    if (typeof document === 'undefined' || !document.getElementById('zramChart')) {
        return;
    }
    let chartInstance = null;
    const historyLimit = 300;
    // 'disk' is the Tier 2 used-bytes series, sourced from history.s on load
    // and from data.ssd_swap.used on each live tick. See spec
    // docs/specs/DASHBOARD_TIER2_VISIBILITY.md.
    const historyData = { labels: [], original: [], used: [], load: [], disk: [] };
    let lastTotalTicks = null;
    let lastTime = null;

    // Render an HTML tooltip floating on document.body. Unlike Chart.js's default
    // canvas-drawn tooltip, this can extend beyond the chart's tiny 70px height.
    // Positions the tooltip above the caret, flipping below if there's no room.
    function externalTooltip(context) {
        let el = document.getElementById('zram-chart-tooltip');
        if (!el) {
            el = document.createElement('div');
            el.id = 'zram-chart-tooltip';
            el.style.cssText = [
                'position:absolute', 'pointer-events:none',
                'background:rgba(0,0,0,0.85)', 'color:#fff',
                'border-radius:4px', 'padding:6px 8px',
                'font-size:11px', 'line-height:1.45',
                'white-space:nowrap', 'z-index:10000',
                'transition:opacity 0.1s', 'opacity:0',
                'box-shadow:0 2px 8px rgba(0,0,0,0.4)'
            ].join(';');
            document.body.appendChild(el);
        }
        const tt = context.tooltip;
        if (!tt || tt.opacity === 0) { el.style.opacity = '0'; return; }

        // Rebuild the tooltip body using safe DOM nodes — no innerHTML, all inputs
        // treated as text even though they're numerically derived
        while (el.firstChild) el.removeChild(el.firstChild);

        if (tt.title && tt.title.length) {
            const title = document.createElement('div');
            title.style.cssText = 'font-weight:bold;margin-bottom:3px;';
            title.textContent = String(tt.title[0]);
            el.appendChild(title);
        }
        (tt.dataPoints || []).forEach(p => {
            const row = document.createElement('div');
            const swatch = document.createElement('span');
            swatch.style.cssText = 'display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:1px;';
            swatch.style.background = p.dataset.borderColor;
            row.appendChild(swatch);
            const val = p.dataset.yAxisID === 'y1'
                ? p.parsed.y.toFixed(1) + '%'
                : formatBytes(p.parsed.y);
            row.appendChild(document.createTextNode(String(p.dataset.label) + ': ' + val));
            el.appendChild(row);
        });

        const rect = context.chart.canvas.getBoundingClientRect();
        const pageX = rect.left + window.pageXOffset + tt.caretX;
        const pageY = rect.top + window.pageYOffset + tt.caretY;
        el.style.opacity = '1';
        const ttH = el.offsetHeight;
        const ttW = el.offsetWidth;
        // Vertical: flip below caret if placing above would clip viewport top
        if (rect.top + tt.caretY - ttH - 10 < 0) {
            el.style.top = (pageY + 10) + 'px';
        } else {
            el.style.top = (pageY - ttH - 10) + 'px';
        }
        // Horizontal: clamp to the chart's own bounds so the tooltip can't
        // overflow into adjacent stat cards when hovering near an edge.
        const chartLeftPage  = rect.left  + window.pageXOffset;
        const chartRightPage = rect.right + window.pageXOffset;
        let left = pageX - ttW / 2;
        if (left + ttW > chartRightPage) left = chartRightPage - ttW;
        if (left < chartLeftPage) left = chartLeftPage;
        el.style.left = left + 'px';
    }

    function initChart() {
        const canvas = document.getElementById('zramChart');
        if (!canvas || typeof Chart === 'undefined') return;
        // Tier visibility flags from PHP render. Datasets matching an inactive
        // tier are present in the data array (so tooltip-index math stays
        // consistent across configurations) but hidden from the canvas.
        const cfg = window.ZRAM_CONFIG || {};
        const tier1 = cfg.tier1Active !== false;  // default true if undefined (legacy)
        const tier2 = cfg.tier2Active === true;
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
                        yAxisID: 'y', order: 3, hidden: !tier1
                    },
                    // Mid layer: compressed size (actual RAM occupied)
                    {
                        label: 'Compressed', data: historyData.used,
                        borderColor: '#7fba59', backgroundColor: 'rgba(127,186,89,0.45)',
                        borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0,
                        yAxisID: 'y', order: 2, hidden: !tier1
                    },
                    // Front layer: Tier 2 disk used — cyan, reads as "storage" against the warm RAM palette
                    {
                        label: 'Disk', data: historyData.disk,
                        borderColor: '#00a4d8', backgroundColor: 'rgba(0,164,216,0.50)',
                        borderWidth: 1.4, fill: true, tension: 0.4, pointRadius: 0,
                        yAxisID: 'y', order: 2, hidden: !tier2
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
                        // External HTML tooltip — the canvas is only 70px tall, so canvas-drawn
                        // tooltips with 4 lines get clipped. Render as a floating div on the body
                        // so the tooltip can extend anywhere on the page.
                        enabled: false,
                        mode: 'index', intersect: false,
                        external: externalTooltip
                    }
                },
                scales: {
                    x: { display: false },
                    y: {
                        type: 'linear', display: true, position: 'left',
                        beginAtZero: true, grace: '10%', suggestedMax: 1048576,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#888', font: { size: 10 }, maxTicksLimit: 6,
                            callback: v => formatBytesRound(v, 5) }
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

            // Update stat elements (each guarded — chip may be absent in
            // single-tier render modes per DASHBOARD_TIER2_VISIBILITY.md)
            const el = id => document.getElementById(id);
            if (el('zram-uncompressed')) el('zram-uncompressed').textContent = formatBytes(aggs.total_original);
            if (el('zram-compressed')) el('zram-compressed').textContent = formatBytes(aggs.total_used);
            if (el('zram-ratio')) el('zram-ratio').textContent = aggs.compression_ratio + 'x';
            if (el('zram-swappiness')) el('zram-swappiness').textContent = aggs.swappiness;
            // Disk Used chip — only present when Tier 2 is active
            const ssdUsedNow = (data.ssd_swap && typeof data.ssd_swap.used === 'number') ? data.ssd_swap.used : 0;
            if (el('zram-disk')) el('zram-disk').textContent = formatBytes(ssdUsedNow);

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
                        tierEl.textContent = 'Disk (' + formatBytes(s.used, 0) + ')';
                    } else if (s.active) {
                        tierEl.style.color = '#00a4d8';
                        tierEl.textContent = 'Disk (idle)';
                    } else {
                        tierEl.style.color = '#666';
                        tierEl.textContent = 'Disk (off)';
                    }
                }
            }

            // Chart data — initial backfill, filtering out legacy entries missing o/u.
            // entry.s (Tier 2 used) is forward/backward compatible: pre-2026.05.05.01
            // entries don't have it, so we coerce to 0 — those entries plot a flat
            // disk series until newer entries take over.
            if (!historyLoaded && data.history && data.history.length > 0) {
                filterHistory(data.history).forEach(item => {
                    historyData.labels.push(item.t);
                    historyData.original.push(item.o);
                    historyData.used.push(item.u);
                    historyData.load.push(item.l);
                    historyData.disk.push(typeof item.s === 'number' ? item.s : 0);
                });
                historyLoaded = true;
            } else {
                historyData.labels.push(new Date().toLocaleTimeString());
                historyData.original.push(aggs.total_original);
                historyData.used.push(aggs.total_used);
                historyData.load.push(loadPct);
                historyData.disk.push(ssdUsedNow);
            }

            while (historyData.labels.length > historyLimit) {
                historyData.labels.shift();
                historyData.original.shift();
                historyData.used.shift();
                historyData.load.shift();
                historyData.disk.shift();
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
