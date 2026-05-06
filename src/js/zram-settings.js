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

// --- Per-tier priority overrides (Advanced details panel) ---------------------
// Inputs start disabled. Expanding the <details> triggers a swal warning;
// clicking "I understand" sets a per-session unlock flag and enables the
// inputs + SAVE button. Cancel collapses the details. RESET TO DEFAULTS is
// always enabled — it writes the safe state (100/10) and can never invert.
// See docs/specs/PER_TIER_PRIORITY_OVERRIDE.md.

function unlockPriorityInputs() {
    var z = document.getElementById('zram_priority_input');
    var s = document.getElementById('ssd_priority_input');
    var b = document.getElementById('btn-save-priorities');
    if (z) z.disabled = false;
    if (s) s.disabled = false;
    if (b) b.disabled = false;
}

function lockPriorityInputs() {
    var z = document.getElementById('zram_priority_input');
    var s = document.getElementById('ssd_priority_input');
    var b = document.getElementById('btn-save-priorities');
    if (z) z.disabled = true;
    if (s) s.disabled = true;
    if (b) b.disabled = true;
}

function savePriorities() {
    var z = parseInt(document.getElementById('zram_priority_input').value, 10);
    var s = parseInt(document.getElementById('ssd_priority_input').value, 10);
    if (isNaN(z) || isNaN(s)) {
        if (typeof swal === 'function') {
            swal({ title: "Invalid", text: "Both priorities must be numbers.", type: "error" });
        }
        return;
    }
    if (z <= s) {
        if (typeof swal === 'function') {
            swal({
                title: "Invalid ordering",
                text: "Tier 1 (ZRAM) priority must be strictly greater than Tier 2 (Disk). Otherwise pages route to disk first and ZRAM is bypassed.",
                type: "error"
            });
        }
        return;
    }

    // Build the human-readable diff: "Tier 1: 100 → 80, Tier 2: 10 → 10".
    // Source the "old" values from the inputs' defaultValue (set by PHP on render).
    var zOld = parseInt(document.getElementById('zram_priority_input').defaultValue, 10);
    var sOld = parseInt(document.getElementById('ssd_priority_input').defaultValue, 10);
    var zChanged = !isNaN(zOld) && zOld !== z;
    var sChanged = !isNaN(sOld) && sOld !== s;
    if (!zChanged && !sChanged) {
        // No-op save — nothing to confirm or send. Surface a passive indicator and bail.
        showSavedIndicator(document.getElementById('btn-save-priorities'), 'No change', 'ok');
        return;
    }

    var diffLines = [];
    if (zChanged) diffLines.push('Tier 1 (ZRAM): ' + zOld + ' → ' + z);
    else          diffLines.push('Tier 1 (ZRAM): ' + z + ' (unchanged)');
    if (sChanged) diffLines.push('Tier 2 (Disk): ' + sOld + ' → ' + s);
    else          diffLines.push('Tier 2 (Disk): ' + s + ' (unchanged)');

    var doSave = function() {
        var CSRF = window.ZRAM_PAGE.CSRF;
        var API  = window.ZRAM_PAGE.API;
        var btn  = document.getElementById('btn-save-priorities');
        if (btn) btn.disabled = true;
        var params = 'action=update_priorities' +
                     '&zram=' + encodeURIComponent(z) +
                     '&ssd='  + encodeURIComponent(s) +
                     '&csrf_token=' + encodeURIComponent(CSRF);
        $.get(API + '?' + params, function(data) {
            if (data && data.success) {
                if (data.warnings && data.warnings.length > 0) {
                    if (typeof swal === 'function') {
                        swal({ title: "Saved with warnings", text: data.message, type: "warning" });
                    } else {
                        addLog(data.message, 'err');
                    }
                } else {
                    showSavedIndicator(btn, 'Saved ✓', 'ok');
                    addLog(data.message, 'cmd');
                }
                // Refresh defaultValue so a subsequent edit diffs against the new baseline
                document.getElementById('zram_priority_input').defaultValue = String(z);
                document.getElementById('ssd_priority_input').defaultValue  = String(s);
                fetchActivity();
            } else {
                var msg = (data && data.message) || 'Save failed';
                if (typeof swal === 'function') {
                    swal({ title: "Save rejected", text: msg, type: "error" });
                } else {
                    addLog('Priority save failed: ' + msg, 'err');
                }
            }
            if (btn) btn.disabled = false;
        }).fail(function() {
            if (btn) btn.disabled = false;
            addLog('Priority save request failed', 'err');
        });
    };

    // Final-confirm overlay: shows the exact change being applied. Not a
    // duplicate of the first-expand warning — that warning explained the
    // /risk/ in general; this one shows the /specific/ change about to land.
    if (typeof swal === 'function') {
        swal({
            title: "Apply priority change?",
            text: diffLines.join('\n') + '\n\nIf the device is currently active this will swapoff/swapon to re-prioritise. Tier 1 must remain greater than Tier 2.',
            type: "warning",
            showCancelButton: true,
            confirmButtonText: "Apply change",
            cancelButtonText: "Cancel",
            closeOnConfirm: true,
            closeOnCancel: true
        }, function(confirmed) {
            if (confirmed) doSave();
        });
    } else {
        // No swal available — fall back to native confirm
        if (confirm('Apply priority change?\n\n' + diffLines.join('\n'))) doSave();
    }
}

function resetPriorities() {
    var z = document.getElementById('zram_priority_input');
    var s = document.getElementById('ssd_priority_input');
    if (z) z.value = 100;
    if (s) s.value = 10;
    // Reset is always safe — it writes the documented defaults. No swal warning.
    var CSRF = window.ZRAM_PAGE.CSRF;
    var API  = window.ZRAM_PAGE.API;
    var params = 'action=update_priorities&zram=100&ssd=10&csrf_token=' + encodeURIComponent(CSRF);
    $.get(API + '?' + params, function(data) {
        if (data && data.success) {
            addLog('Priorities reset to defaults (100 / 10)', 'cmd');
            fetchActivity();
        } else {
            addLog('Reset failed: ' + ((data && data.message) || 'unknown'), 'err');
        }
    });
}

function bootstrapPriorityOverride() {
    var details = document.getElementById('zram-advanced-priorities');
    if (!details) return;
    details.addEventListener('toggle', function() {
        if (!details.open) return;
        if (window.ZRAM_PAGE.priorityUnlocked) {
            unlockPriorityInputs();
            return;
        }
        if (typeof swal !== 'function') {
            // No swal available — fall back to native confirm. Better than nothing.
            if (confirm("Edit tier priorities?\n\nTier ordering depends on these values being in the right relationship — Tier 1 must stay higher than Tier 2. Inverting them will route every page to disk first and bypass ZRAM entirely.\n\nProceed?")) {
                window.ZRAM_PAGE.priorityUnlocked = true;
                unlockPriorityInputs();
            } else {
                details.open = false;
            }
            return;
        }
        swal({
            title: "Edit tier priorities?",
            text: "Tier ordering depends on these values being in the right relationship — Tier 1 must stay higher than Tier 2. Inverting them will route every page to disk first and bypass ZRAM entirely. Defaults (100 / 10) are correct for almost every install.",
            type: "warning",
            showCancelButton: true,
            confirmButtonText: "I understand — let me edit",
            cancelButtonText: "Cancel",
            closeOnConfirm: true,
            closeOnCancel: true
        }, function(confirmed) {
            if (confirmed) {
                window.ZRAM_PAGE.priorityUnlocked = true;
                unlockPriorityInputs();
            } else {
                details.open = false;
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapPriorityOverride);
} else {
    bootstrapPriorityOverride();
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
