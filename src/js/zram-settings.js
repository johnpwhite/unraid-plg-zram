// zram-settings.js — Settings page JavaScript for ZRAM Card plugin

function toggleSizeMode() {
    var mode = document.getElementById('zram_size_mode').value;
    document.getElementById('zram_auto_info').style.display = mode === 'auto' ? 'inline' : 'none';
    document.getElementById('zram_custom_size').style.display = mode === 'custom' ? 'inline' : 'none';
}

function updateAutoSize() {
    var pct = document.getElementById('zram_percent_slider').value;
    var mb = Math.round(window.ZRAM_PAGE.MEM_KB * pct / 100 / 1024);
    var gb = (window.ZRAM_PAGE.MEM_KB / 1048576).toFixed(1);
    document.getElementById('zram_percent_label').textContent = pct + '%';
    document.getElementById('zram_auto_info').textContent = pct + '% of ' + gb + 'GB = ' + mb + 'MB';
}

function syncFormValues() {
    var mode = document.getElementById('zram_size_mode').value;
    document.getElementById('form_zram_size').value = mode === 'auto' ? 'auto' : document.getElementById('zram_custom_size').value;
    document.getElementById('form_zram_percent').value = document.getElementById('zram_percent_slider').value;
    document.getElementById('form_zram_algo').value = document.getElementById('zram_algo_select').value;
}

function zramAction(action, extra) {
    var CSRF = window.ZRAM_PAGE.CSRF;
    var API = window.ZRAM_PAGE.API;
    var params = 'action=' + action + '&csrf_token=' + encodeURIComponent(CSRF);
    if (extra) params += '&' + extra;
    var btn = event ? event.target : null;
    if (btn) btn.disabled = true;
    addLog('Running: ' + action + '...', 'cmd');

    $.get(API + '?' + params, function(data) {
        if (data.logs) data.logs.forEach(function(l) {
            if (typeof l === 'string') addLog(l);
            else addLog(l.cmd + ' -> ' + (l.status === 0 ? 'OK' : 'FAIL'), l.status === 0 ? '' : 'err');
        });
        if (data.success) {
            addLog('Done: ' + data.message);
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            addLog('ERROR: ' + data.message, 'err');
            if (btn) btn.disabled = false;
        }
    }).fail(function(xhr) {
        addLog('Request failed (HTTP ' + xhr.status + ')', 'err');
        if (btn) btn.disabled = false;
    });
}

function loadDrives() {
    var CSRF = window.ZRAM_PAGE.CSRF;
    $.get('/plugins/unraid-zram-card/zram_drives.php?csrf_token=' + encodeURIComponent(CSRF), function(data) {
        var list = document.getElementById('drive-list');
        if (!list) return;
        if (!data.drives || data.drives.length === 0) {
            var empty = document.createElement('div');
            empty.style.cssText = 'opacity:0.5;font-size:0.9em;padding:8px;';
            empty.textContent = 'No eligible drives found. Tier 2 needs a writable mount under /mnt/cache or /mnt/disks (Unassigned Devices).';
            list.replaceChildren(empty);
            return;
        }
        var html = '';
        data.drives.forEach(function(d) {
            var cls = d.classify === 'recommended' ? 'indicator-green' : d.classify === 'warn' ? 'indicator-orange' : 'indicator-green';
            var badge = d.classify === 'recommended' ? ' <span style="color:#7fba59;font-size:0.75em;">[Recommended]</span>' : '';
            if (d.classify === 'warn') badge = ' <span style="color:#ff8c00;font-size:0.75em;">[Not Recommended]</span>';
            var free = formatDriveSize(d.free_bytes);
            html += '<div class="zram-drive-row" onclick="selectDrive(this,\'' + d.mount.replace(/'/g, "\\'") + '\')">';
            html += '<div class="indicator ' + cls + '"></div>';
            html += '<div style="flex:1;">';
            html += '<div style="font-weight:bold;font-size:0.9em;">' + d.mount + badge + '</div>';
            html += '<div style="font-size:0.8em;opacity:0.6;">' + d.model + ' &middot; ' + d.transport.toUpperCase() + ' &middot; ' + free + ' free</div>';
            if (d.warning) html += '<div style="font-size:0.8em;color:#ff8c00;margin-top:2px;">' + d.warning + '</div>';
            html += '</div></div>';
        });
        list.innerHTML = html;
    });
}

function selectDrive(el, mount) {
    document.querySelectorAll('.zram-drive-row').forEach(function(r) { r.classList.remove('selected'); });
    el.classList.add('selected');
    window.ZRAM_PAGE.selectedMount = mount;
    document.getElementById('btn-create-ssd').disabled = false;
}

function createSsdSwap() {
    if (!window.ZRAM_PAGE.selectedMount) return;
    var size = document.getElementById('ssd_swap_size').value;
    zramAction('create_ssd_swap', 'mount=' + encodeURIComponent(window.ZRAM_PAGE.selectedMount) + '&size=' + encodeURIComponent(size));
}

function formatDriveSize(bytes) {
    if (bytes <= 0) return '0 B';
    var u = ['B','KB','MB','GB','TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
}

function switchTab(tab) {
    $('.zram-tab').removeClass('active');
    $('#tab-' + tab).addClass('active');
    if (tab === 'cmd') {
        $('#console-log').show(); $('#debug-log-view').hide();
        $('#btn-clear-cons').show(); $('#btn-clear-debug, #btn-refresh-debug').hide();
    } else {
        $('#console-log').hide(); $('#debug-log-view').show();
        $('#btn-clear-cons').hide(); $('#btn-clear-debug, #btn-refresh-debug').show();
        fetchDebugLog();
    }
}

function fetchDebugLog() {
    var v = document.getElementById('debug-log-view');
    v.innerText = 'Loading...';
    $.get(window.ZRAM_PAGE.API + '?action=view_log&csrf_token=' + encodeURIComponent(window.ZRAM_PAGE.CSRF), function(data) { v.innerText = data; v.scrollTop = v.scrollHeight; });
}

function clearDebugLog() {
    if (!confirm('Clear the system debug log?')) return;
    $.get(window.ZRAM_PAGE.API + '?action=clear_log&csrf_token=' + encodeURIComponent(window.ZRAM_PAGE.CSRF), function() { fetchDebugLog(); });
}

function clearCmdLog() {
    $.get(window.ZRAM_PAGE.API + '?action=clear_cmd_log&csrf_token=' + encodeURIComponent(window.ZRAM_PAGE.CSRF), function() {
        document.getElementById('console-log').innerHTML = '';
        addLog('Console cleared.');
    });
}

function renderLog(entry) {
    var log = document.getElementById('console-log');
    if (!log) return;
    var div = document.createElement('div');
    div.className = 'log-entry' + (entry.type ? ' log-' + entry.type : '');
    div.innerText = '[' + entry.time + '] ' + entry.msg;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function addLog(msg, type) {
    renderLog({ time: new Date().toLocaleTimeString(), msg: msg, type: type || '' });
    $.get(window.ZRAM_PAGE.API + '?action=append_cmd_log&msg=' + encodeURIComponent(msg) + '&type=' + encodeURIComponent(type || ''));
}

$(function() {
    $.get(window.ZRAM_PAGE.API + '?action=view_cmd_log&csrf_token=' + encodeURIComponent(window.ZRAM_PAGE.CSRF), function(logs) {
        if (!logs || logs.length === 0) addLog('Console ready.');
        else logs.forEach(renderLog);
    });
    loadDrives();
});
