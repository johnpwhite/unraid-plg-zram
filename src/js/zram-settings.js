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
    create_zram:        'Create ZRAM swap',
    remove_zram:        'Remove ZRAM swap',
    create_disk_swap:   'Create disk swap file',
    remove_disk_swap:   'Remove disk swap file',
    activate_disk_swap: 'Activate disk swap file',
    update_swappiness:  'Update swappiness',
    clear_log:          'Clear log',
    view_log:           'View log',
    // Legacy aliases — left in so any in-flight UI session still gets a
    // friendly label until the next page reload picks up the new JS.
    create_ssd_swap:    'Create disk swap file',
    remove_ssd_swap:    'Remove disk swap file',
    activate_ssd_swap:  'Activate disk swap file'
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
                : ' onclick="selectDrive(this,\'' + d.mount.replace(/'/g, "\\'") + '\',\'' + (d.backing || 'file') + '\')"';
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

function selectDrive(el, mount, backing) {
    if (el.classList.contains('zram-drive-row-blocked')) return;
    document.querySelectorAll('.zram-drive-row').forEach(function(r) { r.classList.remove('selected'); });
    el.classList.add('selected');
    window.ZRAM_PAGE.selectedMount = mount;
    // Carry the picker's backing mode ('loop' for btrfs-RAID / opt-in ZFS, else
    // 'file') through to the create request. Without this the server defaults to
    // 'file' and swapon fails on pools the kernel won't host a swap file on.
    window.ZRAM_PAGE.selectedBacking = backing || 'file';
    document.getElementById('btn-create-disk').disabled = false;
}

function createDiskSwap() {
    if (!window.ZRAM_PAGE.selectedMount) return;
    var size = document.getElementById('ssd_swap_size').value;
    var backing = window.ZRAM_PAGE.selectedBacking || 'file';
    zramAction('create_disk_swap', 'mount=' + encodeURIComponent(window.ZRAM_PAGE.selectedMount) + '&size=' + encodeURIComponent(size) + '&backing=' + encodeURIComponent(backing));
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
        // No-op save — nothing to confirm or send. Flash the button so the
        // user has feedback that the click registered, then bail.
        showSavedOnButton(document.getElementById('btn-save-priorities'), 'NO CHANGE', 'ok', 1200);
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
        // Lock the whole settings area first (before the button's own disable, so
        // the lock doesn't mis-record btn as pre-disabled), release in .always().
        zramSaveBegin();
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
                    if (btn) btn.disabled = false;
                } else {
                    // showSavedOnButton handles re-enabling the button after the flash.
                    showSavedOnButton(btn, 'SAVED ✓', 'ok');
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
                if (btn) btn.disabled = false;
            }
        }).fail(function() {
            if (btn) btn.disabled = false;
            addLog('Priority save request failed', 'err');
        }).always(function() {
            zramSaveEnd();
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

// ─── Page-wide settings lock during saves ──────────────────────────────────
// While ANY settings save is in flight (a data-autosave control, the OOM apply,
// or a priorities save) every interactive control in the settings cards is
// locked, so the user can't fire a second change that races the in-flight
// request (e.g. the OOM save's trailing loadOomItems() re-render clobbering an
// un-saved edit). Reference-counted: overlapping saves only unlock once the
// LAST one finishes. Scoped to `.zram-cards`, which wraps exactly the four
// settings cards and excludes the Activity console.
var _zramSaveLocks = 0;

function setSettingsLocked(locked) {
    var root = document.querySelector('.zram-cards');
    if (!root) return;
    if (locked) {
        // Disable + TAG only controls that are currently enabled. Controls already
        // disabled — by initial state (CREATE SWAP FILE until a pool is chosen, the
        // priority SAVE button, the picker Add button, the auto-managed dependency
        // dropdowns) OR by a re-render during the lock — are left untouched.
        root.querySelectorAll('input, select, textarea, button').forEach(function(el) {
            if (!el.disabled) { el.disabled = true; el.setAttribute('data-lock-disabled', '1'); }
        });
    } else {
        // Re-enable ONLY the controls this lock disabled. "Restore what I changed"
        // is idempotent under DOM churn: controls swapped in by a mid-lock re-render
        // carry no tag and keep whatever state they were rendered with.
        root.querySelectorAll('[data-lock-disabled]').forEach(function(el) {
            el.disabled = false;
            el.removeAttribute('data-lock-disabled');
        });
    }
    // Non-form clickables (sortable <th>, the Default-section <tr> toggle, preset
    // links, <details> summaries) are blocked via a class → pointer-events:none.
    root.classList.toggle('zram-settings-locked', locked);
}

function zramSaveBegin() {
    _zramSaveLocks++;
    if (_zramSaveLocks === 1) setSettingsLocked(true);
}

function zramSaveEnd() {
    _zramSaveLocks = Math.max(0, _zramSaveLocks - 1);
    if (_zramSaveLocks === 0) setSettingsLocked(false);
}

function zramAutoSave(key, value, el) {
    var CSRF = window.ZRAM_PAGE.CSRF;
    var API  = window.ZRAM_PAGE.API;
    var params = 'action=update_setting&key=' + encodeURIComponent(key) +
                 '&value=' + encodeURIComponent(value) +
                 '&csrf_token=' + encodeURIComponent(CSRF);

    zramSaveBegin();
    $.get(API + '?' + params, function(data) {
        if (data && data.success) {
            showSavedIndicator(el, 'Saved ✓', 'ok');
        } else {
            showSavedIndicator(el, '! ' + ((data && data.message) || 'Save failed'), 'err');
        }
    }).fail(function() {
        showSavedIndicator(el, 'Save failed', 'err');
    }).always(function() {
        zramSaveEnd();
    });
}

// In-button save feedback for action buttons that live in a flex row alongside
// other buttons (priority SAVE next to RESET TO DEFAULTS). The sibling-span
// indicator pushes layout around in flex containers; swapping the button's
// own text for ~1.5s gives the same affordance with no layout shift.
function showSavedOnButton(btn, text, kind, holdMs) {
    if (!btn) return;
    if (!btn.dataset.originalText) {
        btn.dataset.originalText = btn.textContent;
    }
    btn.textContent = text;
    btn.classList.remove('zram-btn-ok-flash', 'zram-btn-err-flash');
    btn.classList.add(kind === 'err' ? 'zram-btn-err-flash' : 'zram-btn-ok-flash');
    btn.disabled = true;
    var ms = (typeof holdMs === 'number') ? holdMs : 1500;
    setTimeout(function() {
        if (btn.dataset.originalText !== undefined) {
            btn.textContent = btn.dataset.originalText;
            delete btn.dataset.originalText;
        }
        btn.classList.remove('zram-btn-ok-flash', 'zram-btn-err-flash');
        btn.disabled = false;
    }, ms);
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

// ─── OOM Protection Card ──────────────────────────────────────────────────

var OOM_LEVEL_SCORES = {
    protected: -1000,
    high:      -500,
    normal:    0,
    low:       500,
    killfirst: 1000
};

// Friendly labels for concise activity-log lines.
var OOM_LEVEL_LABELS = {
    protected: 'Sacrifice last',
    high:      'Prefer to keep',
    normal:    'Default',
    low:       'Sacrifice early',
    killfirst: 'Sacrifice first'
};

var _oomItems = [];  // cache of loaded items
var _oomSortCol = 'type';       // active sort column key
var _oomSortDir = 'asc';        // 'asc' or 'desc'
var _oomDefaultsOpen = false;   // collapsed by default
var _oomSearchQuery = '';       // current search string

// Dependency auto-protection: system-managed managers added when the user
// protects any container or VM (negative oom_score_adj). When the last
// protected container/VM is un-protected, auto-added managers are removed
// again (unless the user manually touched them first).
var _oomAutoDeps = [];  // names the SYSTEM auto-added as proc: managers
var OOM_DEPS = {
    docker: ['dockerd', 'containerd'],
    vm:     ['libvirtd', 'virtqemud']
};

/**
 * Reconcile dependency auto-protection. Called after any level change,
 * commit, or preset to keep docker/VM manager processes protected while
 * any container/VM has a negative (protective) oom_score_adj level.
 *
 * Returns true if it mutated _oomItems or _oomAutoDeps (caller should
 * re-render and schedule a save).
 */
function reconcileOomDeps() {
    var changed = false;

    // 1. Build the set of manager names currently needed.
    var needed = {};
    ['docker', 'vm'].forEach(function(type) {
        var hasProtected = _oomItems.some(function(item) {
            return item.type === type && (OOM_LEVEL_SCORES[item.level] || 0) < 0;
        });
        if (hasProtected) {
            OOM_DEPS[type].forEach(function(mgr) { needed[mgr] = true; });
        }
    });

    // 2. Ensure each needed manager is in _oomItems as a protected proc item.
    Object.keys(needed).forEach(function(mgr) {
        var existing = null;
        for (var i = 0; i < _oomItems.length; i++) {
            if (_oomItems[i].type === 'proc' && _oomItems[i].name === mgr) {
                existing = _oomItems[i]; break;
            }
        }
        var isAuto = _oomAutoDeps.indexOf(mgr) !== -1;
        if (!existing) {
            // Auto-add a new proc item for this manager.
            // Look for a _svcSuggested candidate for live mem/state info
            // (window.bootstrapOomServicePicker populates those on load).
            var candidate = null;
            if (typeof _svcSuggestedForReconcile !== 'undefined') {
                candidate = _svcSuggestedForReconcile.find(function(x) { return x.name === mgr; });
            }
            var isRunning = candidate ? !!candidate.running : false;
            var memBytes  = (candidate && candidate.mem_bytes) ? candidate.mem_bytes : 0;
            _oomItems.push({
                id:           'proc:' + mgr,
                type:         'proc',
                name:         mgr,
                state:        isRunning ? 'running' : 'idle',
                mem_bytes:    memBytes,
                mem_kind:     memBytes ? 'used' : 'none',
                oom_score:    0,
                oom_score_adj: 0,
                level:        'protected',
                configured:   true,
                present:      isRunning
            });
            // Also ensure it is in #zram-oom-proc-patterns
            var hPat = document.getElementById('zram-oom-proc-patterns');
            if (hPat) {
                var pats = hPat.value ? hPat.value.split(',').map(function(n) { return n.trim(); }).filter(Boolean) : [];
                if (pats.indexOf(mgr) === -1) { pats.push(mgr); hPat.value = pats.join(','); }
            }
            if (!isAuto) _oomAutoDeps.push(mgr);
            changed = true;
        } else if (isAuto) {
            // Re-assert protected + configured (user may have cleared it without taking it over)
            if (existing.level !== 'protected' || !existing.configured) {
                existing.level      = 'protected';
                existing.configured = true;
                changed = true;
            }
        }
        // else: item exists and is NOT auto — user owns it, leave untouched.
    });

    // 3. Remove auto-added managers that are no longer needed.
    var toRemove = _oomAutoDeps.filter(function(mgr) { return !needed[mgr]; });
    toRemove.forEach(function(mgr) {
        // Remove from _oomItems
        for (var i = _oomItems.length - 1; i >= 0; i--) {
            if (_oomItems[i].type === 'proc' && _oomItems[i].name === mgr) {
                _oomItems.splice(i, 1); break;
            }
        }
        // Remove from #zram-oom-proc-patterns
        var hPat = document.getElementById('zram-oom-proc-patterns');
        if (hPat) {
            var pats = hPat.value ? hPat.value.split(',').map(function(n) { return n.trim(); }).filter(Boolean) : [];
            var idx = pats.indexOf(mgr);
            if (idx !== -1) { pats.splice(idx, 1); hPat.value = pats.join(','); }
        }
        // Remove from _oomAutoDeps
        var adIdx = _oomAutoDeps.indexOf(mgr);
        if (adIdx !== -1) _oomAutoDeps.splice(adIdx, 1);
        changed = true;
    });

    // 4. Sync the hidden auto-deps input
    var hAuto = document.getElementById('zram-oom-auto-deps');
    if (hAuto) hAuto.value = _oomAutoDeps.join(',');

    // 5. Log concise messages for added/removed sets
    if (toRemove.length > 0) {
        addLog('Released auto-protected dependencies: ' + toRemove.join(', '));
    }
    var autoAdded = toRemove.length === 0 && changed
        ? Object.keys(needed).filter(function(mgr) {
            return _oomAutoDeps.indexOf(mgr) !== -1;
          })
        : [];
    if (autoAdded.length > 0) {
        addLog('Auto-protected dependencies: ' + autoAdded.join(', '));
    }

    return changed;
}

function fmtOomMem(bytes) {
    if (!bytes || bytes <= 0) return '—';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576)    return (bytes / 1048576).toFixed(0) + ' MB';
    return (bytes / 1024).toFixed(0) + ' KB';
}

function fmtOomMemWithKind(bytes, kind) {
    if (kind === 'nolimit') return '<span class="zram-oom-mem-muted">no limit set</span>';
    if (kind === 'none' || !kind || !bytes || bytes <= 0) return '<span class="zram-oom-mem-muted">—</span>';
    var fmt;
    if (bytes >= 1073741824) fmt = (bytes / 1073741824).toFixed(1) + ' GB';
    else if (bytes >= 1048576) fmt = (bytes / 1048576).toFixed(0) + ' MB';
    else fmt = (bytes / 1024).toFixed(0) + ' KB';
    if (kind === 'configured') return fmt + ' <span class="zram-oom-mem-cfg">cfg</span>';
    return fmt; // 'used' — just the number
}

function loadOomItems() {
    // Initialize _oomAutoDeps from the hidden input on first load
    var hAuto = document.getElementById('zram-oom-auto-deps');
    if (hAuto && hAuto.value) {
        _oomAutoDeps = hAuto.value.split(',').map(function(n) { return n.trim(); }).filter(Boolean);
    } else {
        _oomAutoDeps = [];
    }

    var CSRF   = window.ZRAM_PAGE.CSRF;
    var OOM_API = (window.ZRAM_OOM_CONFIG || {}).OOM_API || '/plugins/unraid-zram-card/zram_oom.php';
    $.get(OOM_API + '?action=list_items&csrf_token=' + encodeURIComponent(CSRF), function(data) {
        if (!data.items) return;
        _oomItems = data.items;
        // Run reconcile once on bootstrap — if it changed something, save + re-render;
        // otherwise just render (no save on every page load for no-op reconcile).
        var reconciled = reconcileOomDeps();
        renderOomTable(_oomItems);
        if (reconciled) scheduleOomSave();
    }).fail(function() {
        var tb = document.getElementById('zram-oom-tbody');
        if (tb) tb.innerHTML = '<tr><td colspan="5" style="color:#cc3333;padding:12px 8px;">Failed to load items — is virsh/docker available?</td></tr>';
    });
}

function buildRow(item) {
    var absentClass = item.present ? '' : ' zram-oom-row-absent';
    var badgeClass  = 'zram-oom-badge-' + escHtml(item.type);
    // Auto-managed dependency: a proc: manager auto-protected because a VM or
    // container depends on it. The "Auto" status is now baked into the level
    // select's displayed value (see buildLevelSelect) rather than a separate tag.
    var isAuto      = (item.type === 'proc' && _oomAutoDeps.indexOf(item.name) !== -1);
    var levelSel    = buildLevelSelect(item.id, item.level, isAuto);
    var stateLabel  = item.state + (item.present ? '' : ' (absent)');
    var memCell     = fmtOomMemWithKind(item.mem_bytes, item.mem_kind);
    return '<tr class="zram-oom-row' + absentClass + '" data-item-id="' + escHtml(item.id) + '">'
        + '<td style="padding:5px 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(item.name) + '">' + escHtml(item.name) + '</td>'
        + '<td style="padding:5px 8px;"><span class="zram-oom-badge ' + badgeClass + '">' + escHtml(item.type) + '</span></td>'
        + '<td style="padding:5px 8px;">' + escHtml(stateLabel) + '</td>'
        + '<td style="text-align:right;padding:5px 8px;font-variant-numeric:tabular-nums;">' + memCell + '</td>'
        + '<td style="padding:5px 8px;">' + levelSel + '</td>'
        + '</tr>';
}

function renderOomTable(items) {
    var tb = document.getElementById('zram-oom-tbody');
    if (!tb) return;
    if (!items || items.length === 0) {
        tb.innerHTML = '<tr><td colspan="5" style="opacity:0.5;font-style:italic;padding:12px 8px;">No VMs, containers, or services found.</td></tr>';
        updateOomSortHeaders();
        updateOomAutoNote();
        return;
    }

    var configured = items.filter(function(i) { return i.configured; });
    var defaults   = items.filter(function(i) { return !i.configured; });

    // Apply filter
    var filteredConfigured = oomFilterItems(configured);
    var filteredDefaults   = oomFilterItems(defaults);

    // The Default section's expanded state is the user's manual toggle
    // (_oomDefaultsOpen), which must persist across re-renders such as sorting.
    // A search that matches default items force-opens the section WITHOUT
    // overwriting the user's choice, so clearing the search restores it.
    var defaultsOpen = _oomDefaultsOpen || (!!_oomSearchQuery && filteredDefaults.length > 0);

    // Apply sort
    filteredConfigured = oomSortItems(filteredConfigured);
    filteredDefaults   = oomSortItems(filteredDefaults);

    var html = '';

    // Configured section
    if (filteredConfigured.length === 0 && !_oomSearchQuery) {
        html += '<tr><td colspan="5" style="opacity:0.6;font-style:italic;padding:10px 8px;">Nothing is protected yet — everything is at Default. Open the list below to choose what to protect.</td></tr>';
    } else if (filteredConfigured.length > 0 || _oomSearchQuery) {
        html += '<tr class="zram-oom-section-header zram-oom-section-configured"><td colspan="5">Configured — ' + filteredConfigured.length + ' item' + (filteredConfigured.length === 1 ? '' : 's') + '</td></tr>';
        filteredConfigured.forEach(function(item) { html += buildRow(item); });
    }

    // Default section
    if (defaults.length > 0) {
        var arrow = defaultsOpen ? '&#9650;' : '&#9660;';
        html += '<tr class="zram-oom-section-header zram-oom-section-default" id="zram-oom-defaults-toggle" onclick="toggleOomDefaults()">'
             + '<td colspan="5">Default — ' + filteredDefaults.length + ' item' + (filteredDefaults.length === 1 ? '' : 's') + ' at default ' + arrow + '</td></tr>';
        filteredDefaults.forEach(function(item) {
            var rowHtml = buildRow(item);
            if (!defaultsOpen) {
                rowHtml = rowHtml.replace('<tr class="zram-oom-row', '<tr data-default-row="1" style="display:none;" class="zram-oom-row');
            } else {
                rowHtml = rowHtml.replace('<tr class="zram-oom-row', '<tr data-default-row="1" class="zram-oom-row');
            }
            html += rowHtml;
        });
    }

    tb.innerHTML = html;
    updateOomSortHeaders();
    updateOomAutoNote();
}

// Explain the greyed (disabled) auto-managed dependency rows. Shown only when
// the system is currently auto-protecting one or more managers; lists them and
// states they're locked here because a protected VM/container depends on them.
function updateOomAutoNote() {
    var el = document.getElementById('zram-oom-auto-note');
    if (!el) return;
    if (!_oomAutoDeps || _oomAutoDeps.length === 0) {
        el.style.display = 'none';
        el.textContent = '';
        return;
    }
    el.style.display = 'block';
    el.textContent = 'Locked rows are automatically added as a protected VM or container depends on it.';
}

function toggleOomDefaults() {
    _oomDefaultsOpen = !_oomDefaultsOpen;
    var tb = document.getElementById('zram-oom-tbody');
    if (!tb) return;
    var rows = tb.querySelectorAll('[data-default-row]');
    rows.forEach(function(r) { r.style.display = _oomDefaultsOpen ? '' : 'none'; });
    var header = document.getElementById('zram-oom-defaults-toggle');
    if (header) {
        var count = rows.length;
        header.querySelector('td').innerHTML = 'Default — ' + count + ' item' + (count === 1 ? '' : 's') + ' at default ' + (_oomDefaultsOpen ? '&#9650;' : '&#9660;');
    }
}

var OOM_TYPE_ORDER = { vm: 0, docker: 1, proc: 2 };

function oomSortItems(items) {
    var col = _oomSortCol;
    var dir = _oomSortDir;
    var nameCmp = function(a, b) {
        var na = a.name.toLowerCase(), nb = b.name.toLowerCase();
        return na < nb ? -1 : (na > nb ? 1 : 0);
    };
    return items.slice().sort(function(a, b) {
        var r = 0;
        if (col === 'name') {
            r = nameCmp(a, b);
        } else if (col === 'type') {
            var ta = OOM_TYPE_ORDER[a.type] !== undefined ? OOM_TYPE_ORDER[a.type] : 99;
            var tb2 = OOM_TYPE_ORDER[b.type] !== undefined ? OOM_TYPE_ORDER[b.type] : 99;
            r = ta - tb2;
        } else if (col === 'state') {
            var sa = (a.state || '').toLowerCase(), sb = (b.state || '').toLowerCase();
            r = sa < sb ? -1 : (sa > sb ? 1 : 0);
        } else if (col === 'mem_bytes') {
            r = (a.mem_bytes || 0) - (b.mem_bytes || 0);
        } else if (col === 'level') {
            r = (OOM_LEVEL_SCORES[a.level] || 0) - (OOM_LEVEL_SCORES[b.level] || 0);
        }
        // Direction applies to the PRIMARY key only.
        if (dir === 'desc') r = -r;
        // Always tiebreak by item name (ascending) so the order is stable and
        // predictable on every column and in either direction.
        if (r === 0 && col !== 'name') r = nameCmp(a, b);
        return r;
    });
}

function oomFilterItems(items) {
    if (!_oomSearchQuery) return items;
    var q = _oomSearchQuery.toLowerCase();
    return items.filter(function(i) { return i.name.toLowerCase().indexOf(q) !== -1; });
}

function oomSortBy(col) {
    if (_oomSortCol === col) {
        _oomSortDir = _oomSortDir === 'asc' ? 'desc' : 'asc';
    } else {
        _oomSortCol = col;
        _oomSortDir = 'asc';
    }
    renderOomTable(_oomItems);
}

function oomSearchInput(val) {
    _oomSearchQuery = val.trim();
    renderOomTable(_oomItems);
}

function updateOomSortHeaders() {
    var table = document.getElementById('zram-oom-table');
    if (!table) return;
    var ths = table.querySelectorAll('thead th');
    var cols = ['name', 'type', 'state', 'mem_bytes', 'level'];
    ths.forEach(function(th, i) {
        // Remove existing indicator
        var existing = th.querySelector('.zram-oom-sort-indicator');
        if (existing) existing.parentNode.removeChild(existing);
        if (cols[i] === _oomSortCol) {
            var span = document.createElement('span');
            span.className = 'zram-oom-sort-indicator';
            span.innerHTML = _oomSortDir === 'asc' ? '&#9650;' : '&#9660;';
            th.appendChild(span);
        }
    });
}

function buildLevelSelect(id, currentLevel, isAuto) {
    var levels = [
        ['protected', 'Sacrifice last',  'oom_score_adj −1000'],
        ['high',      'Prefer to keep',  'oom_score_adj −500'],
        ['normal',    'Default',         'oom_score_adj 0'],
        ['low',       'Sacrifice early', 'oom_score_adj +500'],
        ['killfirst', 'Sacrifice first', 'oom_score_adj +1000']
    ];
    // Auto-managed dependency rows are DISABLED: the system owns their level
    // (a protected VM/container depends on this manager), so the dropdown is
    // greyed and non-interactive. A disabled <select> still keeps its .value,
    // so collectOomLevels persists 'protected' and the guard still counts it
    // as protective — only the interaction is removed. The bottom note
    // (updateOomAutoNote) explains why these rows are locked.
    var html = '<select class="zram-oom-level-select' + (isAuto ? ' zram-oom-level-auto' : '')
        + '" data-item-id="' + escHtml(id) + '"'
        + (isAuto ? ' disabled title="Protected automatically — a VM or container depends on this. See the note below the table."' : '')
        + ' onchange="oomGuardCheck(); maybeWarnOomRisk(this.value); oomLevelChanged(this.getAttribute(\'data-item-id\'), this.value)">';
    levels.forEach(function(l) {
        var sel = l[0] === currentLevel;
        html += '<option value="' + l[0] + '" title="' + escHtml(l[2]) + '"' + (sel ? ' selected' : '') + '>' + escHtml(l[1]) + '</option>';
    });
    html += '</select>';
    return html;
}

// A level change re-categorises the row immediately: a non-default level is
// "configured" (Configured section); choosing Default moves it into the
// collapsible Default section. Re-render is deferred so the change event
// finishes first.
function oomLevelChanged(id, level) {
    var prevLevel = null;
    for (var i = 0; i < _oomItems.length; i++) {
        if (_oomItems[i].id === id) {
            prevLevel = _oomItems[i].level;
            _oomItems[i].level = level;
            break;
        }
    }
    // Over-protection guard: at least one item must have score >= 0
    var allProtected = _oomItems.length > 0 && _oomItems.every(function(item) {
        return (OOM_LEVEL_SCORES[item.level] || 0) < 0;
    });
    if (allProtected) {
        // Revert
        for (var j = 0; j < _oomItems.length; j++) {
            if (_oomItems[j].id === id) {
                _oomItems[j].level = prevLevel;
                break;
            }
        }
        setTimeout(function() { renderOomTable(_oomItems); }, 0);
        if (typeof swal === 'function') {
            swal('Leave something to sacrifice', 'At least one item must stay at Default, Sacrifice early, or Sacrifice first — otherwise the server has nothing to shut down if memory runs out, and could crash.', 'error');
        }
        return;
    }
    for (var k = 0; k < _oomItems.length; k++) {
        if (_oomItems[k].id === id) {
            _oomItems[k].configured = (level !== 'normal');
            break;
        }
    }
    // Manual-override: if the user changes a level for a proc item that was
    // auto-added, remove it from _oomAutoDeps so reconcile won't re-assert it.
    if (id.indexOf('proc:') === 0) {
        var mgrName = id.slice(5);
        var mgrIdx  = _oomAutoDeps.indexOf(mgrName);
        if (mgrIdx !== -1) _oomAutoDeps.splice(mgrIdx, 1);
    }
    // Reconcile dependency auto-protection after this level change
    reconcileOomDeps();
    setTimeout(function() { renderOomTable(_oomItems); }, 0);
    addLog('Memory protection: ' + id.replace(/^(vm|docker|proc):/, '') + ' → ' + (OOM_LEVEL_LABELS[level] || level));
    scheduleOomSave();
}

function escHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function collectOomLevels() {
    var levels = {};
    var selects = document.querySelectorAll('.zram-oom-level-select');
    selects.forEach(function(sel) {
        var id = sel.getAttribute('data-item-id');
        // Persist only non-default choices. 'normal' is the default, so storing
        // it bloats the config and makes every item look "configured".
        if (id && sel.value !== 'normal') levels[id] = sel.value;
    });
    return levels;
}

function serializeOomLevels(levels) {
    var parts = [];
    Object.keys(levels).forEach(function(id) {
        parts.push(id + '=' + levels[id]);
    });
    return parts.join(',');
}

function oomGuardCheck() {
    // Client-side mirror of the over-protection guard: at least one score >= 0
    var warn = document.getElementById('zram-oom-warning');
    if (!warn) return;
    var selects = document.querySelectorAll('.zram-oom-level-select');
    var allProtected = true;
    selects.forEach(function(sel) {
        var score = OOM_LEVEL_SCORES[sel.value];
        if (score >= 0) allProtected = false;
    });
    warn.style.display = allProtected && selects.length > 0 ? 'block' : 'none';
}

function maybeWarnOomRisk(selectedLevel) {
    // Show a one-time swal explaining the over-protection risk
    // when the user first picks a protective level (protected or high).
    if (selectedLevel !== 'protected' && selectedLevel !== 'high') return;
    if (localStorage.getItem('zram_oom_protect_warned')) return;
    localStorage.setItem('zram_oom_protect_warned', '1');
    if (typeof swal !== 'function') return;
    swal({
        title: 'Too much protected?',
        text:  '"Protect" and "Prefer to keep" tell the server to kill other things first. If you mark EVERYTHING that way, nothing is left to shut down when memory runs out — and the server could crash. Leave at least one item at Default, Sacrifice early, or Sacrifice first.',
        type:  'warning'
    });
}

function autoSaveOom() {
    var levels      = collectOomLevels();
    var levelsStr   = serializeOomLevels(levels);
    var oomGroupChk = document.getElementById('zram-oom-group-chk');
    var oomGroup    = oomGroupChk && oomGroupChk.checked ? 'yes' : 'no';
    var memMinChk   = document.getElementById('zram-vm-memory-min-chk');
    var memMin      = memMinChk && memMinChk.checked ? 'yes' : 'no';
    var procPat     = (document.getElementById('zram-oom-proc-patterns') || {}).value || '';

    var autoDepsVal = (document.getElementById('zram-oom-auto-deps') || {}).value || '';
    var CSRF    = window.ZRAM_PAGE.CSRF;
    var OOM_API = (window.ZRAM_OOM_CONFIG || {}).OOM_API || '/plugins/unraid-zram-card/zram_oom.php';
    var params  = 'action=apply_oom'
        + '&csrf_token=' + encodeURIComponent(CSRF)
        + '&levels='     + encodeURIComponent(levelsStr)
        + '&oom_group='  + encodeURIComponent(oomGroup)
        + '&vm_memory_min=' + encodeURIComponent(memMin)
        + '&oom_proc_patterns=' + encodeURIComponent(procPat)
        + '&oom_auto_deps=' + encodeURIComponent(autoDepsVal);

    var fb = document.getElementById('zram-oom-feedback');
    if (fb) { fb.style.display='block'; fb.style.color=''; fb.textContent='Saving…'; }
    // Lock all settings controls while the apply is in flight (see setSettingsLocked).
    zramSaveBegin();
    // Auto-save is silent on success: the specific change is already logged by
    // the caller (oomLevelChanged / commit / toggle). We deliberately do NOT
    // replay the server's full per-item apply ($logs) — that re-applies every
    // item and would spam the activity log on every change. Only errors log.
    $.get(OOM_API + '?' + params, function(data) {
        if (data.success) {
            if (fb) { fb.style.display='block'; fb.style.color='#7fba59'; fb.textContent='Saved ✓'; }
            setTimeout(function() { if (fb) fb.style.display='none'; }, 2500);
            loadOomItems();
        } else {
            addLog('Memory protection save error: ' + data.message, 'err');
            if (fb) { fb.style.display='block'; fb.style.color='#ff6666'; fb.textContent='Error: ' + data.message; }
        }
    }).fail(function(xhr) {
        addLog('Memory protection save failed (HTTP ' + xhr.status + ')', 'err');
    }).always(function() {
        zramSaveEnd();
    });
}

var _oomSaveTimer = null;
function scheduleOomSave() {
    if (_oomSaveTimer) clearTimeout(_oomSaveTimer);
    _oomSaveTimer = setTimeout(function() { _oomSaveTimer = null; autoSaveOom(); }, 350);
}

function applyOomLevels() {
    autoSaveOom();
}

function resetOomLevels() {
    swal({
        title: 'Reset everything to Default?',
        text:  'Every VM, container, and service goes back to Default — nothing specially protected, nothing sacrificed first. The plugin also stops managing these priorities until you set them again.',
        type:  'warning',
        showCancelButton: true,
        confirmButtonText: 'Reset all',
        cancelButtonText:  'Cancel'
    }, function(confirmed) {
        if (!confirmed) return;
        var CSRF    = window.ZRAM_PAGE.CSRF;
        var OOM_API = (window.ZRAM_OOM_CONFIG || {}).OOM_API || '/plugins/unraid-zram-card/zram_oom.php';
        addLog('Resetting all OOM levels to Normal...', 'cmd');
        $.get(OOM_API + '?action=reset_oom&csrf_token=' + encodeURIComponent(CSRF), function(data) {
            if (data.logs) data.logs.forEach(function(l) { addLog(l); });
            addLog(data.success ? 'Reset complete: ' + data.message : 'ERROR: ' + data.message, data.success ? '' : 'err');
            if (data.success) loadOomItems();
        }).fail(function(xhr) {
            addLog('OOM reset request failed (HTTP ' + xhr.status + ')', 'err');
        });
    });
}

function applyOomPreset(preset) {
    if (preset === 'protect_vms_sacrifice_containers') {
        swal({
            title: 'Apply preset?',
            text:  'Protect all VMs (shut down last) and sacrifice all containers (shut down first if memory runs out). Background services stay at Default.',
            type:  'info',
            showCancelButton: true,
            confirmButtonText: 'Apply preset'
        }, function(confirmed) {
            if (!confirmed) return;
            var selects = document.querySelectorAll('.zram-oom-level-select');
            selects.forEach(function(sel) {
                var id = sel.getAttribute('data-item-id') || '';
                if (id.indexOf('vm:') === 0)     sel.value = 'protected';
                if (id.indexOf('docker:') === 0)  sel.value = 'killfirst';
                // proc stays normal
            });
            oomGuardCheck();
            // Sync _oomItems from select values and save
            _oomItems.forEach(function(item) {
                var sel = document.querySelector('.zram-oom-level-select[data-item-id="' + escHtml(item.id) + '"]');
                if (sel) {
                    item.level = sel.value;
                    item.configured = (sel.value !== 'normal');
                }
            });
            reconcileOomDeps();
            renderOomTable(_oomItems);
            scheduleOomSave();
        });
    }
}

// =========================================================
// OOM SERVICE PICKER — chip-autocomplete for proc patterns
// =========================================================
// Interaction model: staging → commit.
// _svcStaged  = chips staged in the picker box (temporary, NOT yet in _oomItems).
// _svcSuggested / _svcOther = backend candidates for the dropdown.
// The chip box starts EMPTY on each page load (it's a staging area, not a mirror
// of saved patterns). "Add to list" commits staged chips into _oomItems and syncs
// #zram-oom-proc-patterns. The dropdown stays OPEN after each pick so the user
// can pick several in a row.

// Module-level reference to the picker's suggested list, populated once
// list_service_candidates returns. Used by reconcileOomDeps() so auto-added
// manager rows can show live mem/state info.
var _svcSuggestedForReconcile = [];

(function() {
    var _svcSuggested  = [];   // [{name,desc,mem_bytes,instances,running,critical}]
    var _svcOther      = [];   // [{name,mem_bytes,instances}]
    var _svcStaged     = [];   // names staged in the chip box (pending "Add to list")
    var _svcVisible    = [];   // items visible in dropdown (for keyboard nav)
    var _svcDdIdx      = -1;
    var _svcCloseTimer = null;
    var _svcLoaded     = false;

    function _fmtMem(bytes) {
        if (!bytes) return '';
        var mb = bytes / 1048576;
        if (mb >= 1024) return (mb / 1024).toFixed(1) + ' GB';
        return Math.round(mb) + ' MB';
    }

    // Sync ONLY what's committed (already in _oomItems as proc items) into the
    // hidden input. Called by _commitStagingChips after committing, and on
    // _removeChip when a proc item is removed from the table directly via the
    // table (future path). The hidden input is the persistence source for
    // applyOomLevels(); the staging chips do NOT write to it until committed.
    function _syncHiddenFromItems() {
        var h = document.getElementById('zram-oom-proc-patterns');
        if (!h) return;
        // Collect all proc: ids from _oomItems
        var names = [];
        _oomItems.forEach(function(item) {
            if (item.type === 'proc' && item.name) names.push(item.name);
        });
        // Also include any names already in the hidden input that aren't in
        // _oomItems (safety: e.g. offline patterns that weren't discovered)
        var existing = h.value ? h.value.split(',') : [];
        existing.forEach(function(n) {
            n = n.trim();
            if (n && names.indexOf(n) === -1) names.push(n);
        });
        h.value = names.join(',');
    }

    function _chipHtml(name, memBytes, critical) {
        var memBadge = memBytes ? '<span class="zram-svc-chip-mem">' + _fmtMem(memBytes) + '</span>' : '';
        var crit     = critical ? '<span class="zram-svc-dd-crit" title="Critical service">⚡</span>' : '';
        return '<span class="zram-svc-chip' + (critical ? ' zram-svc-chip-crit' : '') + '" data-svc-name="' + name + '">'
            + crit + '<span>' + name + '</span>' + memBadge
            + '<button type="button" class="zram-svc-chip-x" data-svc-remove="' + name + '" title="Remove">×</button>'
            + '</span>';
    }

    function _renderChips() {
        // Chips live in their own box (separate from the filter input), so the
        // input never shrinks as chips are added.
        var box = document.getElementById('zram-svc-chip-box');
        if (!box) return;
        box.innerHTML = '';
        _svcStaged.forEach(function(name) {
            var s = _svcSuggested.find(function(x) { return x.name === name; });
            var memBytes = s ? s.mem_bytes : 0;
            var critical = s ? s.critical : false;
            var el = document.createElement('span');
            el.innerHTML = _chipHtml(name, memBytes, critical);
            if (el.firstChild) box.appendChild(el.firstChild);
        });
        box.style.display = _svcStaged.length ? 'flex' : 'none';
        // Update the "Add to list" button enabled state
        var commitBtn = document.getElementById('zram-svc-commit-btn');
        if (commitBtn) commitBtn.disabled = _svcStaged.length === 0;
    }

    function _buildDropdown() {
        var dd    = document.getElementById('zram-svc-dropdown');
        var input = document.getElementById('zram-oom-svc-search');
        if (!dd || !input) return;
        var q = input.value.toLowerCase().trim();
        // Exclude names already staged OR already in _oomItems as proc items
        var excludeSet = {};
        _svcStaged.forEach(function(n) { excludeSet[n] = true; });
        _oomItems.forEach(function(item) {
            if (item.type === 'proc') excludeSet[item.name] = true;
        });

        // .filter() returns a fresh array, so sorting it does NOT reorder the
        // _svcSuggested source. Critical (⚡) services first; stable sort keeps
        // the curated order within the critical / non-critical groups.
        var suggested = _svcSuggested.filter(function(p) {
            return !excludeSet[p.name] && (q === '' || p.name.toLowerCase().indexOf(q) !== -1 || (p.desc && p.desc.toLowerCase().indexOf(q) !== -1));
        }).sort(function(a, b) {
            return (b.critical ? 1 : 0) - (a.critical ? 1 : 0);
        });
        var other = _svcOther.filter(function(p) {
            return !excludeSet[p.name] && (q === '' || p.name.toLowerCase().indexOf(q) !== -1);
        });

        _svcVisible = suggested.concat(other);
        _svcDdIdx   = -1;

        var html = '';
        if (suggested.length === 0 && other.length === 0) {
            // Free-text entry hint
            if (q !== '') {
                html += '<div class="zram-svc-dd-item" data-svc-freetext="' + q + '">'
                    + '<span class="zram-svc-dd-name">' + q + '</span>'
                    + '<span class="zram-svc-dd-desc">Add custom pattern</span>'
                    + '</div>';
            } else {
                html += '<div class="zram-svc-dd-empty">No matching services</div>';
            }
        } else {
            if (suggested.length > 0) {
                html += '<div class="zram-svc-dd-group">Suggested services</div>';
                suggested.forEach(function(p) { html += _ddItemHtml(p, true); });
            }
            if (other.length > 0) {
                if (suggested.length > 0) html += '<hr class="zram-svc-dd-divider">';
                if (q === '') html += '<div class="zram-svc-dd-group">Other running</div>';
                other.forEach(function(p) { html += _ddItemHtml(p, false); });
            }
            html += '<div class="zram-svc-dd-note">Kernel threads (kworker, btrfs-*, zfs-*) are excluded — they cannot be OOM-killed</div>';
        }
        dd.innerHTML = html;
        _updateActiveIdx(dd);
    }

    function _ddItemHtml(p, isSuggested) {
        var crit = (isSuggested && p.critical) ? '<span class="zram-svc-dd-crit" title="Critical">⚡</span>' : '';
        var desc = isSuggested && p.desc ? '<span class="zram-svc-dd-desc">' + p.desc + '</span>' : '';
        var mem  = p.mem_bytes ? '<span class="zram-svc-dd-mem">' + _fmtMem(p.mem_bytes) + '</span>' : (p.running === false ? '<span class="zram-svc-dd-mem" style="opacity:0.35">offline</span>' : '');
        return '<div class="zram-svc-dd-item" data-svc-name="' + p.name + '">'
            + '<span class="zram-svc-dd-name">' + p.name + '</span>' + crit + desc + mem
            + '</div>';
    }

    function _updateActiveIdx(dd) {
        dd = dd || document.getElementById('zram-svc-dropdown');
        if (!dd) return;
        dd.querySelectorAll('.zram-svc-dd-item').forEach(function(el, i) {
            el.classList.toggle('zram-svc-dd-active', i === _svcDdIdx);
        });
    }

    function _openDd() {
        if (_svcCloseTimer) { clearTimeout(_svcCloseTimer); _svcCloseTimer = null; }
        var dd = document.getElementById('zram-svc-dropdown');
        if (dd) { _buildDropdown(); dd.classList.add('open'); }
    }

    function _closeDd() {
        _svcCloseTimer = setTimeout(function() {
            var dd = document.getElementById('zram-svc-dropdown');
            if (dd) dd.classList.remove('open');
            _svcDdIdx = -1;
        }, 180);
    }

    // Stage a chip (adds to staging box; does NOT write to hidden input or table).
    // Keeps the dropdown open so the user can pick multiple in a row.
    function _stageChip(name) {
        if (!name || _svcStaged.indexOf(name) !== -1) return;
        // Also skip names already present in the table as proc items
        var alreadyInTable = _oomItems.some(function(item) {
            return item.type === 'proc' && item.name === name;
        });
        if (alreadyInTable) return;
        _svcStaged.push(name);
        _renderChips();
        var input = document.getElementById('zram-oom-svc-search');
        if (input) { input.value = ''; }
        // Keep dropdown open (rebuild to remove the just-staged item from the list)
        _openDd();
    }

    function _unstageChip(name) {
        var idx = _svcStaged.indexOf(name);
        if (idx !== -1) { _svcStaged.splice(idx, 1); }
        _renderChips();
        _buildDropdown();
    }

    // Commit all staged chips into _oomItems + sync hidden input + clear staging.
    // Exposed as window.commitOomStagingChips for the "Add to list" button onclick.
    function _commitStagingChips() {
        if (_svcStaged.length === 0) return;
        var addedNames = [];
        _svcStaged.slice().forEach(function(name) {
            // Skip if already in table
            var exists = _oomItems.some(function(item) { return item.type === 'proc' && item.name === name; });
            if (exists) return;
            // Look up candidate for live state/mem info
            var candidate = _svcSuggested.find(function(x) { return x.name === name; });
            if (!candidate) candidate = _svcOther.find(function(x) { return x.name === name; });
            var isRunning  = candidate ? !!candidate.running : false;
            var memBytes   = (candidate && candidate.mem_bytes) ? candidate.mem_bytes : 0;
            var isCritical = !!(candidate && candidate.critical);
            // Critical (⚡) services are the recommended-protect set — add them
            // already set to "Sacrifice last"; everything else starts at Default.
            var lvl = isCritical ? 'protected' : 'normal';
            _oomItems.push({
                id:          'proc:' + name,
                type:        'proc',
                name:        name,
                state:       isRunning ? 'running' : 'idle',
                mem_bytes:   memBytes,
                mem_kind:    memBytes ? 'used' : 'none',
                oom_score:   0,
                oom_score_adj: 0,
                level:       lvl,
                configured:  (lvl !== 'normal'),
                present:     isRunning
            });
            addedNames.push(isCritical ? name + ' → Protect' : name);
        });

        // Sync hidden input (merge staged names in, preserving any previously saved)
        var _hPat = document.getElementById('zram-oom-proc-patterns');
        if (_hPat) {
            var existing = _hPat.value ? _hPat.value.split(',').map(function(n) { return n.trim(); }).filter(Boolean) : [];
            _svcStaged.forEach(function(name) {
                if (existing.indexOf(name) === -1) existing.push(name);
            });
            _hPat.value = existing.join(',');
        }

        // Clear staging
        _svcStaged = [];
        _renderChips();

        // Close dropdown
        var dd = document.getElementById('zram-svc-dropdown');
        if (dd) { dd.classList.remove('open'); _svcDdIdx = -1; }

        if (addedNames.length) {
            // Expand Default section so any newly added default rows are visible
            // (critical ⚡ services land in the Configured section automatically).
            _oomDefaultsOpen = true;
            // Reconcile dependency auto-protection before saving
            reconcileOomDeps();
            renderOomTable(_oomItems);
            addLog('Added to protection: ' + addedNames.join(', '));
            scheduleOomSave();
        }
    }
    window.commitOomStagingChips = _commitStagingChips;

    function _onKeydown(e) {
        var dd = document.getElementById('zram-svc-dropdown');
        var input = document.getElementById('zram-oom-svc-search');
        if (!dd) return;
        var isOpen = dd.classList.contains('open');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!isOpen) { _openDd(); return; }
            _svcDdIdx = Math.min(_svcDdIdx + 1, _svcVisible.length - 1);
            _updateActiveIdx(dd);
            var active = dd.querySelector('.zram-svc-dd-active');
            if (active) active.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            _svcDdIdx = Math.max(_svcDdIdx - 1, -1);
            _updateActiveIdx(dd);
            var activeUp = dd.querySelector('.zram-svc-dd-active');
            if (activeUp) activeUp.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (_svcDdIdx >= 0 && _svcVisible[_svcDdIdx]) {
                _stageChip(_svcVisible[_svcDdIdx].name);
            } else if (input && input.value.trim() !== '') {
                // Free-text entry: stage the typed name
                _stageChip(input.value.trim());
            }
        } else if (e.key === 'Escape') {
            if (dd) dd.classList.remove('open');
            _svcDdIdx = -1;
        } else if (e.key === 'Backspace' && input && input.value === '') {
            if (_svcStaged.length > 0) {
                _unstageChip(_svcStaged[_svcStaged.length - 1]);
            }
        }
    }

    function _onDdClick(e) {
        // Stop the click reaching the document outside-click handler: _stageChip
        // rebuilds the dropdown (innerHTML), orphaning e.target, which would make
        // that handler see the click as "outside" and wrongly close the list.
        e.stopPropagation();
        var item = e.target.closest('[data-svc-name]');
        var ft   = e.target.closest('[data-svc-freetext]');
        if (item && item.closest('#zram-svc-dropdown')) {
            if (_svcCloseTimer) { clearTimeout(_svcCloseTimer); _svcCloseTimer = null; }
            _stageChip(item.getAttribute('data-svc-name'));
        } else if (ft) {
            if (_svcCloseTimer) { clearTimeout(_svcCloseTimer); _svcCloseTimer = null; }
            _stageChip(ft.getAttribute('data-svc-freetext'));
        }
    }

    function _onRemoveClick(e) {
        var btn = e.target.closest('[data-svc-remove]');
        if (btn) { _unstageChip(btn.getAttribute('data-svc-remove')); }
    }

    window.bootstrapOomServicePicker = function() {
        var box   = document.getElementById('zram-svc-chip-box');
        var input = document.getElementById('zram-oom-svc-search');
        var dd    = document.getElementById('zram-svc-dropdown');
        if (!box || !input || !dd) return;

        // Staging chip box starts EMPTY — saved proc patterns are already
        // loaded into the table via loadOomItems() → renderOomTable().
        // Do NOT pre-populate _svcStaged from #zram-oom-proc-patterns.

        // Wire up events
        input.addEventListener('focus', _openDd);
        input.addEventListener('click', _openDd); // reopen even when input already has focus
        input.addEventListener('blur',  _closeDd);
        input.addEventListener('input', function() { _buildDropdown(); });
        input.addEventListener('keydown', _onKeydown);
        box.addEventListener('click', function(e) {
            e.stopPropagation(); // chip-remove clicks must not reach the outside-click closer
            _onRemoveClick(e);
        });
        dd.addEventListener('mousedown', function(e) { e.preventDefault(); }); // prevent blur before click
        dd.addEventListener('click', _onDdClick);

        // Close dropdown on outside click. Check the whole picker container (the
        // input is a sibling of the chip-box now, not inside it), or clicking the
        // input would open then immediately close the list.
        var picker = box.closest('.zram-svc-picker');
        document.addEventListener('click', function(e) {
            if (picker && !picker.contains(e.target)) {
                var commitBtn = document.getElementById('zram-svc-commit-btn');
                if (commitBtn && commitBtn.contains(e.target)) return;
                if (dd.classList.contains('open')) {
                    dd.classList.remove('open');
                    _svcDdIdx = -1;
                }
            }
        });

        // Fetch candidates from backend
        var CSRF    = (window.ZRAM_PAGE || {}).CSRF || '';
        var OOM_API = ((window.ZRAM_OOM_CONFIG || {}).OOM_API) || '/plugins/unraid-zram-card/zram_oom.php';
        $.get(OOM_API + '?action=list_service_candidates&csrf_token=' + encodeURIComponent(CSRF), function(data) {
            if (!data || !data.success) return;
            _svcSuggested = data.suggested || [];
            _svcOther     = data.other     || [];
            _svcLoaded    = true;
            // Expose suggested list to module scope so reconcileOomDeps() can use
            // live mem/state when auto-adding manager rows.
            _svcSuggestedForReconcile = _svcSuggested;
            // Refresh staged chip memory badges with live data (if any were staged before load)
            _renderChips();
        });
    };
})();

function bootstrapOomCard() {
    var wrap = document.getElementById('zram-oom-table-wrap');
    if (!wrap) return;
    loadOomItems();
    bootstrapOomServicePicker();
    // Wire toggle auto-save
    var groupChk = document.getElementById('zram-oom-group-chk');
    if (groupChk) groupChk.addEventListener('change', function() {
        addLog('Kill containers cleanly: ' + (groupChk.checked ? 'on' : 'off'));
        scheduleOomSave();
    });
    var memMinChk = document.getElementById('zram-vm-memory-min-chk');
    if (memMinChk) memMinChk.addEventListener('change', function() {
        addLog('Reserve protected-VM memory: ' + (memMinChk.checked ? 'on' : 'off'));
        scheduleOomSave();
    });
    // Close the Presets dropdown when clicking anywhere outside it.
    document.addEventListener('click', function(e) {
        document.querySelectorAll('details.zram-preset-dd[open]').forEach(function(d) {
            if (!d.contains(e.target)) d.open = false;
        });
    });
}

document.addEventListener('DOMContentLoaded', bootstrapOomCard);
