# Feature: Per-Tier Priority Override (2026.05.06.08)

## Status
Approved

## Problem
Forum question: "Can we customise priority?" The `2026.05.05.01` answer was inline copy explaining the defaults (`Tier 1 = 100, Tier 2 = 10 — used first / overflow only`) but no editable control. User followed up: ship the control, gated behind a clear warning.

The catch: Tier ordering is *load-bearing* for the entire plugin's value proposition. If a user inverts the priorities — Tier 2 (disk) higher than Tier 1 (ZRAM) — every page goes to disk first, ZRAM never fills, and the user blames the plugin for being slow. We need the knob, but we can't let casual misconfiguration silently destroy the design.

## Requirements
- [x] Numeric priority inputs for Tier 1 and Tier 2, default 100 / 10 respectively
- [x] Hidden behind `<details>Advanced — override tier priorities</details>` (closed by default)
- [x] First-time expand triggers a `swal` modal explaining the risk; requires "I understand" click to unlock the inputs
- [x] Validation: save rejected if Tier 1 priority ≤ Tier 2 priority — the kernel honours higher-priority entries first, so this rule preserves the "ZRAM first" semantic
- [x] Range clamp: each priority 0–32767 (kernel max)
- [x] "Reset to defaults" button always visible alongside the inputs (one click → 100 / 10)
- [x] New config keys `zram_priority` and `ssd_swap_priority`
- [x] `zram_init.sh` consumes the new keys on swapon (Tier 1 swapon `-p $ZRAM_PRIORITY`, Tier 2 swapon `-p $SSD_PRIORITY`)
- [x] `zram_actions.php` create paths use the configured priority instead of hard-coded `100`/`10`
- [x] Existing inline explainers ("used first" / "overflow only") render the *configured* priority, not just the literal `100`/`10`

## Design

### Config keys

```php
'zram_priority'      => '100',  // ZRAM swap-on priority (Tier 1)
'ssd_swap_priority'  => '10',   // Disk swap-on priority (Tier 2)
```

Defaults preserved: anyone upgrading without touching the Advanced panel sees no behaviour change.

### Validation rule

Saving requires `zram_priority > ssd_swap_priority`. Equal priorities make the kernel round-robin between tiers (page interleaving — defeats the point of having two tiers); inverted priorities make the slow tier preferred. Either configuration is silently destructive; we reject before persisting.

A new PHP action `update_priorities` accepts both values atomically:

```
GET zram_actions.php?action=update_priorities&zram=<n>&ssd=<m>&csrf_token=<t>
→ {success: true|false, message: '...', zram_priority: 100, ssd_swap_priority: 10}
```

If validation fails, neither value is written. The single-key `update_setting` action does NOT accept `zram_priority` or `ssd_swap_priority` — they must go through this paired endpoint so the comparison rule can't be bypassed.

### Side effect: swapoff/swapon to apply live

Changing a swap entry's priority requires `swapoff` then `swapon -p <new>`. We do this **only when the relevant device is currently active**. Off-state changes simply persist to config; they take effect on next CREATE / next reboot via `zram_init.sh`.

Live re-prioritisation needs an evacuation safety check (same as REMOVE — make sure the swap data fits elsewhere before turning it off). Reuse `zram_evacuation_safe()`.

If the safety check fails, the priority change is rejected with a clear error; config is NOT written.

### UI

```html
<details class="zram-advanced">
    <summary><i class="fa fa-flask"></i> Advanced — override tier priorities</summary>
    <div class="zram-advanced-body">
        <div class="zram-advanced-warning">
            <strong>Read this first.</strong> Tier ordering depends on these values.
            ZRAM (Tier 1) must stay higher than Disk (Tier 2) — if you invert them,
            every page goes to disk first and ZRAM is bypassed entirely. The defaults
            (100 / 10) are designed for that ordering and are what almost every install
            should use.
        </div>
        <dl>
            <dt>Tier 1 (ZRAM) priority:</dt>
            <dd><input type="number" id="zram_priority_input" min="1" max="32767" value="<?= ... ?>" disabled></dd>
            <dt>Tier 2 (Disk) priority:</dt>
            <dd><input type="number" id="ssd_priority_input" min="0" max="32767" value="<?= ... ?>" disabled></dd>
            <dt></dt>
            <dd>
                <button type="button" class="zram-btn" onclick="savePriorities()" disabled id="btn-save-priorities">SAVE</button>
                <button type="button" class="zram-btn" onclick="resetPriorities()">RESET TO DEFAULTS</button>
            </dd>
        </dl>
    </div>
</details>
```

The two inputs and the SAVE button start `disabled`. RESET is always enabled (writes 100/10 directly).

### First-expand swal

```js
details.addEventListener('toggle', function() {
    if (!details.open) return;
    if (window.ZRAM_PAGE.priorityUnlocked) return;
    swal({
        title: "Edit tier priorities?",
        text: "Tier ordering depends on these values being in the right relationship — Tier 1 must stay higher than Tier 2. Inverting them will route every page to disk first and bypass ZRAM entirely. Defaults (100 / 10) are correct for almost every install.",
        type: "warning",
        showCancelButton: true,
        confirmButtonText: "I understand — let me edit",
        cancelButtonText: "Cancel"
    }, function(confirmed) {
        if (confirmed) {
            window.ZRAM_PAGE.priorityUnlocked = true;
            unlockPriorityInputs();
        } else {
            details.open = false;
        }
    });
});
```

Once unlocked, the lock persists for the session. Refresh re-locks (the user re-acknowledges every session — important friction).

### Validation feedback

- Save with `zram <= ssd` → swal error "Tier 1 priority must be greater than Tier 2"; nothing persisted.
- Save with both fields valid and the relevant device active → server runs swapoff/swapon. If swapoff fails the safety check, swal error from server; nothing persisted.
- Save success → green inline `Saved ✓` next to the SAVE button (reusing the auto-save indicator); also fires `fetchActivity()` so the change shows up in the activity feed immediately.

### Inline explainer text uses configured values

Today the page renders:
```
priority 100 — used first
priority 10 — overflow only
```
Hard-coded literals. Update to use `<?= $settings['zram_priority'] ?>` and `<?= $settings['ssd_swap_priority'] ?>` so the text reflects whatever is configured.

## Settings
Two new config keys, both with safe defaults. No migration — pre-upgrade installs that don't touch the Advanced panel get the defaults written on next config write (handled by `array_merge(ZRAM_DEFAULTS, $loaded)` in `zram_config_read`).

## Edge Cases
- **User unlocks, edits, then closes details without saving** — input values stay in DOM but config is unchanged. Reopening still requires the unlock again (per-session). No data corruption.
- **Both tiers active and user changes priorities** — server does swapoff Tier 1, swapon Tier 1 with new priority, then same for Tier 2. Each step is guarded by `zram_evacuation_safe`. If Tier 1's swapoff fails, neither value is persisted (atomic).
- **Tier 2 inactive** — only Tier 1 needs the live swapoff/swapon dance. Tier 2 priority just persists for next swapon.
- **User sets priorities equal (100/100)** — validation rejects: must be strictly greater.
- **User sets Tier 2 = 0** — kernel allows priority 0; we accept. Tier 1 must still be > 0 (i.e. ≥ 1) so the existing min=1 holds.
- **User sets Tier 1 priority < 1** — input min=1 plus server-side `max(1, intval())` clamps. Anything else gets rejected.
- **Existing swapon already at the configured priority** — no-op on the live device; still writes the config (cheap).
- **`update_setting` whitelist** — `zram_priority` / `ssd_swap_priority` are intentionally NOT in the auto-save whitelist. Any attempt to PUT them through the single-key endpoint returns "Invalid setting key". Forces use of the paired endpoint where the comparison rule lives.

## Verification

### L2 (PHPUnit) — new file `tests/php/PerTierPriorityOverrideTest.php`
- `update_priorities` action exists in zram_actions.php with paired-key shape
- Validation rejects `zram <= ssd`
- Validation clamps each to 0–32767 (Tier 1 min 1)
- `update_setting` whitelist excludes the priority keys (defence in depth)
- Config defaults include `zram_priority=100` and `ssd_swap_priority=10`
- `zram_init.sh` references both `$ZRAM_PRIORITY` (or `cfg_val "zram_priority"`) and `$SSD_PRIORITY` instead of literals
- create_zram in zram_actions.php uses configured zram priority on swapon
- create_disk_swap uses configured ssd priority on swapon
- Page renders the priority values in the inline explainer text (not literal 100/10)
- Page contains `<details class="zram-advanced">` with SAVE + RESET buttons

### L3 (smoke.sh)
Existing assertions stay green. Optional: add a soft check that the rendered settings page contains `zram-advanced` and `RESET TO DEFAULTS` strings.

### Manual (post-deploy)
- Settings page Advanced panel collapsed by default
- Click to expand → swal warning fires; cancel → details collapses again; "I understand" → inputs enable
- Try to save with Tier 1=10 Tier 2=100 → swal error
- Try Tier 1=100 Tier 2=10 (default) → success indicator
- With Tier 1 active, change Tier 1 priority to 80 → swapoff/swapon happens live, `swapon --show=NAME,PRIO` reflects new priority
- Refresh page → defaults locked again, must re-acknowledge to edit; saved values still show in inputs
- Reset to Defaults button → instantly writes 100/10; no warning needed (it's the safe state)
