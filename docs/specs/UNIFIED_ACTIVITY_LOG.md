# Feature: Unified Activity Log (2026.05.06.07)

## Status
Approved

## Problem
The console pane has two tabs — "Command History" (cmd.log) and "System Debug Log" (debug.log) — and the relationship between them is unclear. User reported confusion: changing swappiness fires `sysctl` (a real command), but the user couldn't tell which log surfaced it. In fact `zram_run()` writes to BOTH (cmd.log gets the operator-friendly `cmd -> Success`, debug.log gets the diagnostic `CMD: cmd | Status: 0 | Output: ...`), but the two-tab UX hides that.

Additional gap: the in-page console pane starts empty on each load and only fills with entries from the current session. Historical entries on disk in cmd.log are invisible to the UI even though they exist.

## Requirements
- [x] Single Activity feed replaces the two tabs
- [x] Filter chips at the top: `All` · `Commands` · `Events` · `Errors` (default: All)
- [x] Each entry tagged with a small badge showing its type (`CMD` / `INFO` / `ERROR` / `DEBUG` / `OUT`)
- [x] On page load, render historical entries from both cmd.log and debug.log merged chronologically
- [x] Live action toasts (`addLog`) continue to append to the feed in real time
- [x] Single CLEAR button replaces the two clear actions; clears both files
- [x] Server merges + dedupes — `CMD: ...` lines from debug.log are dropped (already in cmd.log with friendlier formatting)
- [x] Backward-compat: existing `view_log`, `clear_log`, `clear_cmd_log` actions retained for any external caller

## Design

### New PHP action: `view_activity`

Returns a JSON envelope with the merged feed:

```
GET zram_actions.php?action=view_activity&csrf_token=<csrf>
→ {
    "entries": [
        {"ts": "13:00:20", "level": "CMD",   "msg": "sysctl -q vm.swappiness='160' -> Success"},
        {"ts": "13:01:21", "level": "INFO",  "msg": "Setting vm.swappiness=150"},
        {"ts": "13:02:25", "level": "CMD",   "msg": "swapoff '/dev/zram0' -> Success"},
        ...
    ]
}
```

**Source mapping**:

| File | Format | Translation |
| :--- | :--- | :--- |
| cmd.log | JSON lines `{time, msg, type}` | level = `ERROR` if `type=='err'`, `OUT` if `type=='debug'`, else `CMD` |
| debug.log | Plain text `[YYYY-MM-DD HH:MM:SS] [LEVEL] msg` | `[INFO]` → `INFO`; `[ERROR]` → `ERROR`; `[DEBUG]` → `DEBUG`; lines beginning with `CMD: ` skipped (dupes of cmd.log) |

**Sorting**: cmd.log timestamps are HH:MM:SS only (no date) so we coerce to today and merge. Same-day assumption is good enough for the operator-visible window; entries older than the last log rotation cycle (collector rotates debug.log at 1MB) are gone.

**Truncation**: cap at 500 entries returned (most recent), in case both logs fill up. The console pane only shows ~30 at a time anyway.

### New PHP action: `clear_activity`

Truncates both `cmd.log` and `debug.log` in one shot. Logs a "Logs cleared by user" entry to debug.log immediately after so the feed isn't empty.

### Frontend changes

**Markup** — replaces tabs + two log divs with a single feed pane:

```html
<div class="zram-console-pane" id="zram-console-pane">
    <div class="zram-activity-header">
        <div class="zram-activity-chips">
            <button class="activity-chip active" data-filter="all">All</button>
            <button class="activity-chip" data-filter="commands">Commands</button>
            <button class="activity-chip" data-filter="events">Events</button>
            <button class="activity-chip" data-filter="errors">Errors</button>
        </div>
    </div>
    <div id="activity-log"></div>
    <div class="zram-activity-footer">
        <button class="zram-btn" onclick="fetchActivity()"><i class="fa fa-refresh"></i> REFRESH</button>
        <button class="zram-btn zram-btn-danger" onclick="clearActivity()"><i class="fa fa-trash"></i> CLEAR</button>
    </div>
</div>
```

**Filter mapping**:

| Chip | Levels shown |
| :--- | :--- |
| All | (everything) |
| Commands | `CMD`, `OUT` |
| Events | `INFO`, `DEBUG` |
| Errors | `ERROR` |

**Per-entry render**:

```html
<div class="activity-row" data-level="CMD">
  <span class="activity-ts">13:00:20</span>
  <span class="activity-badge activity-badge-cmd">CMD</span>
  <span class="activity-msg">sysctl -q vm.swappiness='160' -> Success</span>
</div>
```

CSS uses dataset-driven badge colours (CMD = blue, INFO = grey, ERROR = red, DEBUG = dim, OUT = orange).

**Filter behaviour**: chip click toggles `display: none` on rows whose `data-level` isn't in the active set. Pure JS, no re-fetch.

**Live updates**: `addLog(msg, type)` continues to fire on every action. Now it constructs an `.activity-row` directly and prepends/appends to `#activity-log` with the same DOM shape as historical entries. Also POSTs to `append_cmd_log` so the entry persists for next page load.

### Removed surface

- `switchTab(tab)` — no more tabs to toggle
- `fetchDebugLog()` — replaced by `fetchActivity()`
- `clearDebugLog()` / `clearCmdLog()` — replaced by single `clearActivity()`
- `#console-log` and `#debug-log-view` divs — replaced by `#activity-log`

The PHP actions that backed these (`view_log`, `clear_log`, `clear_cmd_log`) stay intact for backward compat — smoke.sh and any external callers don't break.

## Settings
None.

## Edge Cases
- **Both logs empty** — feed shows a small `(no activity yet)` placeholder
- **Cmd.log timestamp older than debug.log** but cmd.log only stores HH:MM:SS — we use today's date for ordering. If cmd.log had entries from yesterday and debug.log has fresh ones, ordering is wrong but the entries still render. Acceptable; collector log rotation keeps both files under 1MB.
- **CMD: line duplication** — debug.log's `CMD: foo | Status: 0 | Output: ...` is the diagnostic version of cmd.log's `foo -> Success`. We skip the debug.log version to avoid showing the same event twice. The `CMD-output` lines from cmd.log (with `type=debug`, prefixed `> ...`) survive and show under the Commands filter as `OUT` rows for stdout/stderr context.
- **clear_activity called while collector is writing** — `file_put_contents(file, "")` is atomic; collector's next append goes after the truncate.
- **Live addLog fires before fetchActivity completes** — order doesn't matter; both append to the same `#activity-log` div. Worst case the live entry appears momentarily before historical entries — fetchActivity overwrites the contents.
- **Filter set to Errors when no errors exist** — empty feed under that filter, with an `(no errors)` placeholder.

## Verification

### L2 (PHPUnit) — new file `tests/php/UnifiedActivityLogTest.php`
- `view_activity` action handler exists in `zram_actions.php`
- Source merge handles both cmd.log and debug.log formats
- `CMD:` lines from debug.log are skipped (dedup guard)
- `clear_activity` truncates both files
- Page contains the filter chips with `data-filter` attributes
- Page contains `#activity-log` div
- Page no longer contains `#console-log`, `#debug-log-view`, or the two-tab markup
- JS contains `fetchActivity`, `setActivityFilter`, `clearActivity`
- JS no longer contains `switchTab`, `fetchDebugLog`, `clearDebugLog`, `clearCmdLog` (or marks them deleted)

### L3 (smoke.sh)
Existing assertion 3 calls `clear_cmd_log` via POST — that still works (legacy action retained). Other assertions unaffected.

### Manual (post-deploy)
- Open Settings, hard-refresh — Activity feed populates with entries from both logs (last 500)
- Trigger a swappiness change — new `CMD` entry appears in feed live
- Click Commands chip — only CMD/OUT rows visible
- Click Errors chip — only ERROR rows visible (likely empty on a healthy install)
- Click All chip — full feed restored
- Click CLEAR — feed empties; trigger any action, new entry appears alone
- Refresh page — Activity persists (was empty before because cmd.log render-on-load wasn't wired)
