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
    // Legacy tab function — no-op since the unified activity feed replaced the tabs.
    // Retained so anyone calling it from a stale tab does not throw.
}

// --- Unified activity feed: cmd.log + debug.log merged into a single
//     chronological view with filter chips. See docs/specs/UNIFIED_ACTIVITY_LOG.md.
//     Filter map: All=everything · Commands=CMD,OUT · Events=INFO,DEBUG · Errors=ERROR ---

var ACTIVITY_FILTERS = {
    all:      null,
    commands: ['CMD', 'OUT'],
    events:   ['INFO', 'DEBUG'],
    errors:   ['ERROR']
};
var activityCurrentFilter = 'all';

function fetchActivity() {
    var CSRF = window.ZRAM_PAGE.CSRF;
    var API  = window.ZRAM_PAGE.API;
    $.get(API + '?action=view_activity&csrf_token=' + encodeURIComponent(CSRF), function(data) {
        var log = document.getElementById('activity-log');
        if (!log) return;
        log.textContent = '';
        if (!data || !data.entries || data.entries.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'activity-empty';
            empty.textContent = '(no activity yet)';
            log.appendChild(empty);
            return;
        }
        data.entries.forEach(function(e) { log.appendChild(buildActivityRow(e.ts, e.level, e.msg)); });
        applyActivityFilter(activityCurrentFilter);
        log.scrollTop = log.scrollHeight;
    });
}

function clearActivity() {
    if (!confirm('Clear the activity log? This wipes both cmd.log and debug.log.')) return;
    var CSRF = window.ZRAM_PAGE.CSRF;
    var API  = window.ZRAM_PAGE.API;
    $.get(API + '?action=clear_activity&csrf_token=' + encodeURIComponent(CSRF), function() {
        fetchActivity();
    });
}

function setActivityFilter(name) {
    if (!ACTIVITY_FILTERS.hasOwnProperty(name)) return;
    activityCurrentFilter = name;
    document.querySelectorAll('.activity-chip').forEach(function(c) {
        c.classList.toggle('active', c.dataset.filter === name);
    });
    applyActivityFilter(name);
}

function applyActivityFilter(name) {
    var allowed = ACTIVITY_FILTERS[name];
    document.querySelectorAll('.activity-row').forEach(function(row) {
        row.style.display = (allowed === null || allowed.indexOf(row.dataset.level) >= 0) ? '' : 'none';
    });
}

function buildActivityRow(ts, level, msg) {
    var row = document.createElement('div');
    row.className = 'activity-row';
    row.dataset.level = level;

    var tsEl = document.createElement('span');
    tsEl.className = 'activity-ts';
    tsEl.textContent = ts;

    var badge = document.createElement('span');
    badge.className = 'activity-badge activity-badge-' + level.toLowerCase();
    badge.textContent = level;

    var msgEl = document.createElement('span');
    msgEl.className = 'activity-msg';
    msgEl.textContent = msg;

    row.appendChild(tsEl);
    row.appendChild(badge);
    row.appendChild(msgEl);
    return row;
}

// Live action toasts: append a row to the visible feed AND persist to cmd.log
// so it survives a page reload. Type maps to the activity badge: 'cmd' = CMD,
// 'err' = ERROR, 'debug' = OUT, anything else = CMD (legacy callers).
function addLog(msg, type) {
    var log = document.getElementById('activity-log');
    if (log) {
        // Drop the (no activity yet) placeholder if present
        var empty = log.querySelector('.activity-empty');
        if (empty) empty.remove();
        var level = (type === 'err') ? 'ERROR' : (type === 'debug') ? 'OUT' : 'CMD';
        var ts = new Date().toLocaleTimeString('en-GB', {hour12: false});
        log.appendChild(buildActivityRow(ts, level, msg));
        applyActivityFilter(activityCurrentFilter);
        log.scrollTop = log.scrollHeight;
    }
    // Persist to cmd.log so the entry survives a page reload
    $.get(window.ZRAM_PAGE.API + '?action=append_cmd_log&msg=' + encodeURIComponent(msg) + '&type=' + encodeURIComponent(type || ''));
}

// Bootstrap activity feed on page load + wire chip clicks
function bootstrapActivity() {
    document.querySelectorAll('.activity-chip').forEach(function(c) {
        c.addEventListener('click', function() { setActivityFilter(c.dataset.filter); });
    });
    fetchActivity();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapActivity);
} else {
    bootstrapActivity();
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
