// zram-settings.js — Settings page JavaScript for ZRAM Card plugin

function toggleSizeMode() {
    var mode = document.getElementById('zram_size_mode').value;
    document.getElementById('zram_auto_info').style.display = mode === 'auto' ? 'inline' : 'none';
    document.getElementById('zram_custom_size').style.display = mode === 'custom' ? 'inline' : 'none';
}

function updateAutoSize() {
    var pct = document.getElementById('zram_percent_slider').value;
    var totalGb = (window.ZRAM_PAGE.MEM_KB / 1048576).toFixed(1);
    var autoGb  = (window.ZRAM_PAGE.MEM_KB * pct / 100 / 1048576).toFixed(1);
    document.getElementById('zram_percent_label').textContent = pct + '%';
    document.getElementById('zram_auto_info').textContent = pct + '% of ' + totalGb + 'GB = ' + autoGb + 'GB';
}

function syncFormValues() {
    var mode = document.getElementById('zram_size_mode').value;
    document.getElementById('form_zram_size').value = mode === 'auto' ? 'auto' : document.getElementById('zram_custom_size').value;
    document.getElementById('form_zram_percent').value = document.getElementById('zram_percent_slider').value;
    document.getElementById('form_zram_algo').value = document.getElementById('zram_algo_select').value;
}

// Read current Tier 1 form state and return query params for create_zram.
// Without this, CREATE used only saved-config values, so a slider tweak or
// algo change made between APPLY & SAVE clicks was silently dropped at
// creation time. See docs/specs/CREATE_ZRAM_LIVE_PARAMS.md.
function buildCreateZramParams() {
    var mode = document.getElementById('zram_size_mode').value;
    var algo = document.getElementById('zram_algo_select').value;
    var pct  = document.getElementById('zram_percent_slider').value;
    var size = document.getElementById('zram_custom_size').value;
    var p = 'size_mode=' + encodeURIComponent(mode) +
            '&algo='     + encodeURIComponent(algo);
    if (mode === 'auto')   p += '&percent=' + encodeURIComponent(pct);
    if (mode === 'custom') p += '&size='    + encodeURIComponent(size);
    return p;
}

function createZram() {
    zramAction('create_zram', buildCreateZramParams());
}

// Friendly labels for action identifiers. The wire identifier stays
// snake_case (matches the PHP handler shape and stays grep-friendly), but the
// log toast shows a human phrase instead of the raw id.
var ZRAM_ACTION_LABELS = {
    create_zram:      'Create ZRAM swap',
    remove_zram:      'Remove ZRAM swap',
    create_disk_swap: 'Create disk swap file',
    remove_disk_swap: 'Remove disk swap file',
    update_swappiness:'Update swappiness',
    clear_log:        'Clear log',
    view_log:         'View log',
    // Legacy aliases — left in so any in-flight UI session still gets a
    // friendly label until the next page reload picks up the new JS.
    create_ssd_swap:  'Create disk swap file',
    remove_ssd_swap:  'Remove disk swap file'
};

function zramAction(action, extra) {
    var CSRF = window.ZRAM_PAGE.CSRF;
    var API = window.ZRAM_PAGE.API;
    var params = 'action=' + action + '&csrf_token=' + encodeURIComponent(CSRF);
    if (extra) params += '&' + extra;
    var btn = event ? event.target : null;
    if (btn) btn.disabled = true;
    var label = ZRAM_ACTION_LABELS[action] || action;
    addLog('Running: ' + label + '...', 'cmd');

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
            var cls;
            if (d.classify === 'recommended')      cls = 'indicator-green';
            else if (d.classify === 'warn')        cls = 'indicator-orange';
            else if (d.classify === 'blocked')     cls = 'indicator-red';
            else                                   cls = 'indicator-green';

            var badge = '';
            if (d.classify === 'recommended')      badge = ' <span style="color:#7fba59;font-size:0.75em;">[Recommended]</span>';
            else if (d.classify === 'warn')        badge = ' <span style="color:#ff8c00;font-size:0.75em;">[Not Recommended]</span>';
            else if (d.classify === 'blocked')     badge = ' <span style="color:#cc3333;font-size:0.75em;">[Not Supported]</span>';

            var free = formatDriveSize(d.free_bytes);
            var rowCls = 'zram-drive-row' + (d.clickable === false ? ' zram-drive-row-blocked' : '');
            var clickAttr = d.clickable === false
                ? ''
                : ' onclick="selectDrive(this,\'' + d.mount.replace(/'/g, "\\'") + '\')"';
            var warnColor = d.classify === 'blocked' ? '#cc3333' : '#ff8c00';

            html += '<div class="' + rowCls + '"' + clickAttr + '>';
            html += '<div class="indicator ' + cls + '"></div>';
            html += '<div style="flex:1;">';
            html += '<div style="font-weight:bold;font-size:0.9em;">' + d.mount + badge + '</div>';
            html += '<div style="font-size:0.8em;opacity:0.6;">' + d.model + ' &middot; ' + d.transport.toUpperCase() + ' &middot; ' + free + ' free</div>';
            if (d.warning) html += '<div style="font-size:0.8em;color:' + warnColor + ';margin-top:2px;">' + d.warning + '</div>';
            html += '</div></div>';
        });
        list.innerHTML = html;
    });
}

function selectDrive(el, mount) {
    if (el.classList.contains('zram-drive-row-blocked')) return;
    document.querySelectorAll('.zram-drive-row').forEach(function(r) { r.classList.remove('selected'); });
    el.classList.add('selected');
    window.ZRAM_PAGE.selectedMount = mount;
    document.getElementById('btn-create-disk').disabled = false;
}

function createDiskSwap() {
    if (!window.ZRAM_PAGE.selectedMount) return;
    var size = document.getElementById('ssd_swap_size').value;
    zramAction('create_disk_swap', 'mount=' + encodeURIComponent(window.ZRAM_PAGE.selectedMount) + '&size=' + encodeURIComponent(size));
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

// --- Auto-save: every form field with data-autosave="true" persists on blur
//     (text/number) or change (select/checkbox/range). See spec
//     docs/specs/SETTINGS_AUTO_SAVE.md. The originalValue dance suppresses
//     synthetic browser-autofill changes that fire before user interaction. ---
// Tier 1 size is two coordinated controls (auto/custom dropdown + custom text
// input) backed by a single settings key (zram_size). Custom save logic so a
// user picking "auto" commits "auto" immediately, and editing the custom input
// commits the typed size on blur.
function saveZramSize() {
    var modeEl   = document.getElementById('zram_size_mode');
    var customEl = document.getElementById('zram_custom_size');
    if (!modeEl) return;
    var mode = modeEl.value;
    if (mode === 'auto') {
        zramAutoSave('zram_size', 'auto', modeEl);
    } else {
        var v = (customEl && customEl.value || '').trim();
        if (/^\d+\s*[GMT]$/i.test(v)) {
            zramAutoSave('zram_size', v.toUpperCase().replace(/\s+/g,''), customEl);
        } else if (v) {
            // Surface the validation error inline so the user knows the save was rejected
            showSavedIndicator(customEl, '! Use e.g. 16G', 'err');
        }
    }
}

function bootstrapAutoSave() {
    document.querySelectorAll('[data-autosave]').forEach(function(el) {
        if (el.type === 'checkbox') {
            el.dataset.originalValue = el.checked ? 'yes' : 'no';
        } else {
            el.dataset.originalValue = el.value;
        }

        var ev;
        if (el.type === 'checkbox' || el.tagName === 'SELECT' || el.type === 'range') {
            ev = 'change';
        } else {
            ev = 'blur';
        }

        el.addEventListener(ev, function() {
            var key = el.name || el.id;
            var current = el.type === 'checkbox' ? (el.checked ? 'yes' : 'no') : el.value;
            if (current === el.dataset.originalValue) return;
            el.dataset.originalValue = current;
            zramAutoSave(key, current, el);
        });
    });
}

function zramAutoSave(key, value, el) {
    var CSRF = window.ZRAM_PAGE.CSRF;
    var API  = window.ZRAM_PAGE.API;
    var params = 'action=update_setting&key=' + encodeURIComponent(key) +
                 '&value=' + encodeURIComponent(value) +
                 '&csrf_token=' + encodeURIComponent(CSRF);

    $.get(API + '?' + params, function(data) {
        if (data && data.success) {
            showSavedIndicator(el, 'Saved ✓', 'ok');
        } else {
            showSavedIndicator(el, '! ' + ((data && data.message) || 'Save failed'), 'err');
        }
    }).fail(function() {
        showSavedIndicator(el, 'Save failed', 'err');
    });
}

function showSavedIndicator(el, text, kind) {
    // Anchor to the input itself (or its wrapping label for checkboxes), and
    // remove any previous indicator for that anchor so we never stack.
    var anchor = el.closest('label') || el;
    var anchorId = el.id || el.name;
    var existing = anchor.parentNode.querySelector(
        '.zram-saved-indicator[data-anchor="' + CSS.escape(anchorId) + '"]'
    );
    if (existing) existing.remove();

    var span = document.createElement('span');
    span.className = 'zram-saved-indicator' + (kind === 'err' ? ' zram-saved-indicator-err' : '');
    span.dataset.anchor = anchorId;
    span.textContent = text;
    anchor.parentNode.insertBefore(span, anchor.nextSibling);

    var holdMs  = kind === 'err' ? 2500 : 1000;
    var totalMs = holdMs + 500;
    setTimeout(function() { span.classList.add('fade'); }, holdMs);
    setTimeout(function() { if (span.parentNode) span.parentNode.removeChild(span); }, totalMs);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapAutoSave);
} else {
    bootstrapAutoSave();
}

$(function() {
    $.get(window.ZRAM_PAGE.API + '?action=view_cmd_log&csrf_token=' + encodeURIComponent(window.ZRAM_PAGE.CSRF), function(logs) {
        if (!logs || logs.length === 0) addLog('Console ready.');
        else logs.forEach(renderLog);
    });
    loadDrives();
});
