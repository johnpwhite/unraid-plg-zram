# Feature: Tier 2 Boot Retry (2026.05.06.09)

## Status
Approved

## Problem
Forum bug: on reboot, Tier 2 disk swap is skipped with a `WARN` log:

```
[WARN] Disk swap mount (/mnt/swap) not available yet. Skipping Tier 2.
```

Workaround the user discovered: open Settings → click `APPLY & SAVE`. That used to re-fire `zram_init.sh` as a side effect of the POST handler. The auto-save migration in `2026.05.06.05` removed the button, so the workaround is gone too.

### Why the race exists
On Unraid boot the order is approximately:
1. Plugin install scripts run (`/etc/rc.d/rc.unraid-plg-zram*`) — `zram_init.sh` fires here
2. The disk array starts (`/mnt/diskN`, `/mnt/cache`, …)
3. Unassigned Devices mounts (`/mnt/disks/*`, named pool mounts like `/mnt/swap`)

Step 3 happens AFTER step 1. If a user has Tier 2 on a UD-mounted disk or a non-array pool, the swap file is unreachable when `init.sh` runs.

Today's behaviour: log a WARN, skip Tier 2, never retry. Tier 2 stays inactive until the user manually re-runs `init.sh` (which APPLY & SAVE used to do). With APPLY & SAVE removed this is now visibly broken.

## Requirements
- [x] When the mount is not ready, schedule a background retry instead of logging WARN-and-quit
- [x] Retry every 5s for up to 5 minutes (60 attempts)
- [x] On success: activate Tier 2 with the configured priority (same code path as the synchronous activation), log success
- [x] On timeout: log a final WARN and exit; no retry leak
- [x] Refactor the activation logic into a shell function so the retry path uses identical semantics (priority lookup, label migration, swapon args)
- [x] No regression: synchronous activation path still fires when the mount IS ready at boot

## Design

### Shell function refactor

```bash
# Activate Tier 2 disk swap. Returns 0 on success, 1 on failure.
# Used by both the synchronous boot path and the background retry poller.
activate_disk_swap() {
    local path="$1"
    if [ ! -f "$path" ]; then return 1; fi
    if grep -q "$path" /proc/swaps 2>/dev/null; then return 0; fi  # already active

    # Migrate legacy label if needed (offline only)
    if command -v swaplabel >/dev/null 2>&1; then
        local cur
        cur=$(swaplabel "$path" 2>/dev/null | awk '/LABEL:/{print $2}')
        if [ "$cur" = "$SSD_LEGACY_LABEL" ]; then
            swaplabel -L "$SSD_LABEL" "$path" 2>/dev/null \
                && zlog "Relabeled $path from $SSD_LEGACY_LABEL to $SSD_LABEL" "INFO"
        fi
    fi

    local prio
    prio=$(cfg_val "ssd_swap_priority"); [ -z "$prio" ] && prio="10"
    case "$prio" in (*[!0-9]*|"") prio="10" ;; esac
    [ "$prio" -gt 32767 ] 2>/dev/null && prio="10"
    zlog "Activating disk swap: $path (priority=$prio)" "INFO"
    $SWAPON "$path" -p "$prio" 2>&1
}
```

### Background retry

```bash
if [ "$SSD_ENABLED" = "yes" ] && [ -n "$SSD_PATH" ]; then
    if [ -f "$SSD_PATH" ]; then
        activate_disk_swap "$SSD_PATH" || zlog "Failed to activate disk swap" "ERROR"
    else
        SSD_MOUNT=$(cfg_val "ssd_swap_mount")
        zlog "Disk swap mount ($SSD_MOUNT) not ready — scheduling background retry (60 x 5s)" "INFO"
        (
            for i in $(seq 1 60); do
                sleep 5
                # Quick path: file became visible AND not yet active
                if [ -f "$SSD_PATH" ]; then
                    if grep -q "$SSD_PATH" /proc/swaps 2>/dev/null; then
                        # Someone (the user, another process) activated it already
                        zlog "Tier 2 active by external trigger after ${i}*5s wait" "INFO"
                        exit 0
                    fi
                    if activate_disk_swap "$SSD_PATH"; then
                        zlog "Tier 2 activated after ${i}*5s wait" "INFO"
                        exit 0
                    fi
                fi
            done
            zlog "Disk swap mount ($SSD_MOUNT) never appeared — Tier 2 stays inactive. Check that the mount comes up on boot." "WARN"
        ) > /dev/null 2>&1 &
        disown
    fi
fi
```

### Why background, not block

Blocking `init.sh` for up to 5 minutes at boot would delay the collector launch (which lives at the bottom of the script) and any other plugin work. Tier 1 is already up by this point so backgrounding the Tier 2 wait is the only option that doesn't degrade Tier 1's startup time.

### Why 5 minutes / 5 seconds

- 5 second poll interval: cheap, catches mount events within a small window
- 5 minute cap: enough for a typical Unraid boot to complete (array + UD usually settle in 60–120 seconds even with slow drives), but not so long that a logically-broken mount keeps the daemon alive forever

### Compatibility with the existing relabel migration

The legacy `ZRAM_CARD_SSD` → `ZRAM_CARD_DISK` label migration must run with the swap **offline**. Since the function checks `/proc/swaps` and skips if active, the migration only fires when we're about to swapon a previously-offline file. The retry path inherits this without duplication.

## Settings
None.

## Edge Cases
- **Mount appears, then disappears between checks** — `activate_disk_swap` returns failure (file gone or swapon errors), the loop continues retrying until either the file reappears or the timeout hits.
- **User manually activates Tier 2 mid-retry** (via REMOVE then CREATE in the UI) — the retry's `grep -q "$SSD_PATH" /proc/swaps` check sees the active swap and exits cleanly, no double-swapon.
- **Plugin re-run while a previous retry is still polling** — the second invocation either (a) sees the file ready and activates synchronously, or (b) launches a second poller. Two pollers racing is fine: both call `swapon` which is idempotent (the second call sees the swap already active and is a no-op or reports "already active").
- **Mount appears but the swap file inside it is corrupt** — `activate_disk_swap` returns failure, retry loop continues until cap, final WARN logs that Tier 2 stayed inactive. User can manually CREATE a new swap file from the UI when they notice.
- **Logs grow during the 5-minute wait** — every retry iteration is silent unless it activates or hits the cap. No log spam.

## UI follow-ups bundled in this release
While testing the priority panel from `2026.05.06.08`, two issues surfaced:

1. **SAVE and RESET TO DEFAULTS stacked vertically** — buttons were each on their own DOM line because `<dd>` whitespace defaulted them to block flow within the dl grid cell. Wrapped both in a `<span class="zram-priority-buttons">` with `display: inline-flex; gap: 10px; flex-wrap: wrap` so they sit side by side and wrap together if the column is narrow.

2. **No final confirmation showing the diff** — the first-expand swal explains the *risk*, but the SAVE button needs to show the *specific change* about to land (e.g. "Tier 1: 100 → 80, Tier 2: 10 → 10"). Added a second swal between SAVE click and AJAX dispatch that:
    - Reads `defaultValue` from each input as the "old" baseline
    - Builds a `Tier 1: <old> → <new>` line per tier (or `<value> (unchanged)` if not edited)
    - Shows them in the modal text with the consequence reminder ("If the device is currently active this will swapoff/swapon to re-prioritise")
    - On confirm: dispatches the AJAX. On cancel: nothing happens.
    - On success: refreshes `defaultValue` so a subsequent edit diffs against the new baseline.
    - No-op save (no values changed): skipped entirely with a passive `No change` indicator. Saves the user a confirmation click for an empty change.

## Verification

### L2 (PHPUnit)
- New test in `Tier2BootRetryTest.php`:
    - `activate_disk_swap` shell function defined in init.sh
    - Background retry block present (anchor on `seq 1 60` or equivalent)
    - WARN line for the timeout case is distinct from the original "skipping" WARN
    - Function still applies the priority + legacy-label migration

### L3 (smoke.sh)
Existing assertions unaffected (smoke runs against an already-mounted system; the retry path doesn't fire when the mount IS ready).

### Manual (post-deploy)
- Reboot the test server
- Watch `/tmp/unraid-zram-card/debug.log` — first entry should be the "scheduling background retry" line; ~5–60s later the "Tier 2 activated after Nx5s wait" line
- `swapon --show` should list the disk swap entry within ~1 minute of boot
- Force the bad case: `swapoff /mnt/swap/.swap/zram-card.swap` then re-run `zram_init.sh` after temporarily moving the swap file aside; the retry should hit the cap and emit the final WARN
