# Feature: Dashboard Tier 2 Visibility + Chart Redesign (2026.05.06.10)

## Status
Approved

## Problem
User report (forum, "seamon"): "I have a use case where I just want to use Tier 2 and not Tier 1. In this scenario, it seems to be working but Dashboard is useless and doesn't show anything."

Three independent dashboard bugs surface only when Tier 1 is absent — but they also leave the both-tiers case visually incomplete (no chart series for disk usage):

1. **Device list disappears entirely** — `ZramCard.php` wraps the table inside `<?php if ($ourDev): ?>`. The disk row living inside that conditional is unreachable when ZRAM is off.
2. **Chart never plots disk usage** — collector has captured `s` (Tier 2 used bytes) since `2026.05.05.01` per `TIER_OBSERVABILITY.md`, but the chart's three datasets ignore the field.
3. **Chips are ZRAM-only** — Uncompressed / Compressed / Ratio / Load all show 0 in Tier-2-only mode. Useless. Swappiness is the only meaningful chip in that mode.

## Requirements
- [x] Move the disk row OUT of the `if ($ourDev)` block — independent visibility based on `$ssdPath`
- [x] Render the device-list header row when *either* tier is configured (was: only when Tier 1 active)
- [x] Add a 4th chart dataset for disk used (`#00a4d8` cyan, layered front of compressed)
- [x] Wire chart JS to the `s` field already in history entries
- [x] Add a `Disk` chip alongside the existing five — populated from `data.ssd_swap.used`
- [x] Three render modes for chips:
    - **Both tiers**: 6 chips (Uncompressed · Compressed · Ratio · Load · Disk · Swappiness)
    - **Tier 1 only**: 5 chips (no Disk)
    - **Tier 2 only**: 2 chips (Disk · Swappiness — larger, freed grid space)
- [x] Hide chart datasets that match an inactive tier (no series for ZRAM in Tier-2-only mode; no series for Disk in Tier-1-only mode)
- [x] No regression: smoke `assertion 8` (inactive-state render) still emits the graceful fallback

## Design

### Chip render modes (ZramCard.php)
Detect mode at render:
```php
$tier1 = ($devCount > 0);
$tier2 = ($ssdActive === true);
```
- `$tier1 && $tier2` → all six chips (existing layout, append Disk before Swappiness)
- `$tier1 && !$tier2` → existing five chips unchanged
- `!$tier1 && $tier2` → only Disk + Swappiness (chip cells are larger because the grid is `repeat(auto-fit,minmax(80px,1fr))` and there are fewer cells filling it)
- `!$tier1 && !$tier2` → no chips, no chart, fallback copy "No swap configured"

The Tier 2 used / size value is sourced server-side at render via `swapon --bytes --noheadings --show=NAME,SIZE,USED` filtered to `$ssdPath` (same call shape as the settings page already does).

### Chart layering (zram-card.js)
Existing three datasets retained. New Disk dataset:
```js
{
    label: 'Disk',
    data: historyData.disk,
    borderColor: '#00a4d8',
    backgroundColor: 'rgba(0,164,216,0.50)',
    borderWidth: 1.4,
    fill: true,
    tension: 0.4,
    pointRadius: 0,
    yAxisID: 'y',
    order: 2,        // same plane as Compressed; layered front
    hidden: !tier2   // dynamic — see "Conditional dataset visibility" below
}
```

Z-order back → front:
1. Uncompressed (`order:3`, fill 0.32) — largest envelope, faintest
2. Disk (`order:2`, fill 0.50)
3. Compressed (`order:2`, fill 0.45)
4. CPU Load (`order:1`, line only, right axis)

Setting Disk and Compressed both at `order:2` means Chart.js draws them in array order; we append Disk *after* Compressed in the dataset array so it overlays. The opacity ladder (0.32 / 0.45 / 0.50) lets the eye separate them without a hard z-stack.

### Conditional dataset visibility
Render-time PHP exposes the active flags via `window.ZRAM_CONFIG`:
```js
window.ZRAM_CONFIG = {
    url: '...',
    pollInterval: <int>,
    tier1Active: <bool>,
    tier2Active: <bool>
};
```

initChart() reads those flags and sets `hidden: !tier1Active` on the ZRAM datasets, `hidden: !tier2Active` on the Disk dataset. Hidden datasets stay in the data array (so the tooltip-index logic doesn't break) but Chart.js doesn't render them. Switching tier configuration requires a page reload to apply — fine, this is a settings-driven change, not a runtime toggle.

### Disk dataset history population
History entries already have `s` (Tier 2 used bytes) when written by post-`2026.05.05.01` collectors. The legacy filter `filterHistory` (added in `2026.04.17.02`) drops pre-schema entries; the new code reads `entry.s ?? 0` so older-but-valid entries (no `s` field, but `o`/`u` present) plot a flat zero on the disk series — graceful degradation.

Live polling appends `data.ssd_swap.used ?? 0` to `historyData.disk` on every tick.

### Why cyan (`#00a4d8`)
- Cool tone reads as storage / disk in standard dashboard convention (Grafana, Datadog, Netdata)
- Already used elsewhere in this plugin for the Disk indicator (`ZramCard.php:230`) — reinforces the visual link
- Distinguishable from the existing warm palette (amber/green/red) without competing
- Sufficient luminance contrast against the dark card background for both line + filled area

### Stat card layout (chips)
Existing `auto-fit minmax(80px,1fr)` grid stays. Tier-2-only mode automatically gets two larger cells because there are fewer of them; no separate CSS branch needed.

Disk chip markup:
```html
<div style="background:rgba(0,0,0,0.1);padding:6px;border-radius:4px;text-align:center;">
    <span id="zram-disk" style="font-size:1.1em;font-weight:bold;display:block;color:#00a4d8;">0 B</span>
    <span style="font-size:0.75em;opacity:0.7;">Disk Used</span>
</div>
```

### Device-list refactor
Three independent rows. Header always visible when *any* tier is configured. ZRAM row only when `$ourDev`, Disk row only when `$ssdPath`. Existing live-update path for the disk row (`#zram-ssd-row`) keeps working.

## Settings
None — pure UX rework, no new config keys.

## Edge Cases
- **Tier 2 file exists but is offline** (`$ssdPath && !$ssdActive`) — disk row shows but with `(off)` colour from existing JS update path. Chart's Disk dataset stays hidden because we gate on `tier2Active = $ssdActive` (file present *and* in `/proc/swaps`), not just config.
- **Both tiers absent** — zram-content div renders the "No swap configured" copy and skips the chart entirely. The `$enabled` short-circuit at the top of the function still applies.
- **Page loaded with Tier 1 only, then user adds Tier 2** — chart's hidden flag stays until next page load. Acceptable; settings changes are a reload-tier event.
- **History pre-`s`-field entries** — `entry.s ?? 0` plots zero on disk series. No NaN/undefined leaks to Chart.js.
- **Swappiness chip in Tier-2-only mode** — still useful (it controls when the kernel pages out at all). Kept.

## Verification

### L2 (PHPUnit)
- New test `tests/php/DashboardTier2VisibilityTest.php`:
    - ZramCard.php contains the three-mode chip-render branches (anchor on a comment)
    - Disk row is reachable when only `$ssdPath` is set (no `$ourDev`)
    - "No swap configured" fallback string present
    - Chart JS has a Disk dataset with `borderColor: '#00a4d8'`
    - JS reads `entry.s ?? 0` for forward/backward compat
    - JS pushes `data.ssd_swap.used` to `historyData.disk` on poll

### L3 (smoke.sh)
- Existing assertion 8 (inactive-state render) still green: when `$ourDev` is empty AND `$ssdPath` is empty, render emits the graceful fallback. Update assertion's expected text to match the new "No swap configured" copy.

### Manual (post-deploy)
- With Tier 1 + Tier 2 both active: 6 chips, 4 chart datasets. Tooltip shows all four.
- Stop Tier 1 (REMOVE button), keep Tier 2 active: chips compress to Disk + Swappiness, chart shows only Disk + Load. Refresh page to apply.
- Stop both: card shows "No swap configured", no chips, no chart.
- Existing tooltip clipping behaviour still correct under the new dataset count.
