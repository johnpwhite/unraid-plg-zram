# Feature: Dashboard Uncompressed/Compressed Stacked Graph

## Status
Approved

## Problem
Today's dashboard shows only "RAM Saved" as a single filled line on the chart and in the stat cards. It hides the two magnitudes that produce the saving — how much memory the OS thinks it swapped out (uncompressed) versus how much physical RAM the compressed data actually occupies. A user cannot see at a glance whether ZRAM is under pressure, nor judge whether the compression ratio is holding up.

## Requirements
- [ ] Graph shows uncompressed size (back, dark slate fill) and compressed size (front, cyan fill) on the left axis, with CPU load line (red) on the right axis drawn on top.
- [ ] Stat cards are kept in colour-sync with the graph.
- [ ] Existing history survives the upgrade gracefully (no 500s, no chart crash).
- [ ] No backend API schema break — `zram_status.php` already exposes `total_original` and `total_used` in `aggregates`.

## Design

### User Experience
The chart's two filled areas convey the compression benefit visually: the gap between the dark back layer and the cyan front layer *is* the RAM saved. CPU load stays as a line on top so spikes remain visible without obscuring the fills.

### Backend
- `zram_collector.php`: history entry schema changes from `{t, s, l}` to `{t, o, u, l}` where `o`=uncompressed (`total_original`), `u`=compressed (`total_used`), `l`=load%. Savings are derived as `o - u` on the frontend — no need to store.
- `zram_status.php`: no change. Already returns `aggregates.total_original` and `aggregates.total_used`.

### Frontend
- `ZramCard.php`:
  - Remove the "RAM Saved" card.
  - Add "Uncompressed" card (dark slate `#546b7f`) showing `total_original`.
  - Rename "Actual Used" → "Compressed" (keeps cyan `#00a4d8`).
  - Ratio / Load / Swappiness cards unchanged.
- `zram-card.js`:
  - Chart datasets reordered: `Uncompressed` (dark slate fill) → `Compressed` (cyan fill) → `CPU Load` (red line, right axis).
  - `updateStats` writes `zram-uncompressed` and `zram-compressed` spans (replacing `zram-saved` / `zram-used`).
  - Initial history backfill: entries without `o`/`u` keys are skipped so legacy points don't appear as zeros at the left edge of the chart.

## Settings
None. No configuration changes.

## Edge Cases
- **Old history file** written by the previous collector: frontend filters entries missing `o` or `u`. Collector writes in the new format from first tick, so the chart fills with new data within the first history window (~5–15 minutes).
- **ZRAM inactive**: `total_original` and `total_used` are both 0. Both fills collapse to the baseline; graph still renders.
- **`total_used > total_original`** (should not happen, but theoretically on tiny devices with high per-page overhead): front fill sits above back fill — visually honest, no clamp.

## Verification
1. Publish to Factory and deploy.
2. Load Dashboard. Confirm the 5 cards: Uncompressed · Compressed · Ratio · Load · Swappiness with the colours above.
3. Confirm the chart has two stacked filled areas (dark slate behind, cyan in front) with a red CPU line on top.
4. Hover a point — tooltip should label Uncompressed, Compressed, and Load.
5. Restart the collector: `ssh root@<server> rm /tmp/unraid-zram-card/history.json` then reload — chart rebuilds with new-schema entries.
