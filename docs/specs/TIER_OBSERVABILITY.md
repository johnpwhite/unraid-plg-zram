# Feature: Tier observability — per-tier used readouts and inline explainers for swappiness/priority

## Status
Approved

## Problem

Forum follow-up to the 2026.05.03.01 release. A user asked three things:

1. *"Custom swappiness for Tier 2 (max value clamped to Tier 1 swappiness)."*
2. *"How much data is in Tier 1 vs how much data is in Tier 2."*
3. *"What is priority? It's set to 100 for Tier 1 and 10 for Tier 2. How does
   that differ from swappiness? Can we customize that as well?"*

Asks 1 and 3 are conceptual: a "Tier 2 swappiness" knob doesn't exist at the
kernel level (`vm.swappiness` is system-wide, not per-device), and exposing
priority editing is a footgun that breaks the load-bearing 100/10 ordering.
The right response is **explanation in the UI**, not new editable controls.

Ask 2 is the actually-substantive one. The settings page shows ZRAM
allocated size and algorithm but **never shows used bytes** for either tier;
a user who wants to know "did Tier 2 ever get touched? if so, how much?"
has no way to see it without dropping to a shell and reading `/proc/swaps`.
The dashboard card has a partial answer (the JS-side update writes
`Disk (X MB used)` into the disk row when SSD swap is active and non-empty),
but that's only on the main dashboard, not on the plugin's own settings
page where most users do their tuning.

The existing JSON status endpoint (`zram_status.php`) already exposes per-
tier used bytes (`ssd_swap.used`, plus zramctl's `data`/`compr` for
Tier 1). The data is one read away — what's missing is the surfacing.

## Requirements

- [ ] Tier 1 status row on the settings page shows live "used / disksize"
      when the device is active.
- [ ] Tier 2 status row on the settings page shows live "used / size" when
      the disk swap file is active.
- [ ] Swappiness input has inline help text clarifying it is a global
      kernel setting, not per-tier — addresses Ask 1's misconception
      without building a misleading control.
- [ ] Each tier's status row includes a one-line explainer of what
      priority means in this design ("priority 100 — used first" and
      "priority 10 — overflow only when Tier 1 is full"). Addresses
      Ask 3 without exposing an editable priority control.
- [ ] Collector history schema gains an `s` field (Tier 2 used in bytes)
      so the dashboard can plot Tier 2 spillover historically in a future
      release. Backward-compatible: missing `s` is read as 0.
- [ ] PHPUnit + Vitest + smoke regression guards for all of the above.

## Design

### User Experience

A user opens the settings page during normal operation:

- **Tier 1 active, no spillover** — sees "Active: /dev/zram0  ·  1.2 GB / 8 GB
  used  ·  zstd  ·  priority 100 (used first)". Tier 2 status row says
  "Idle (priority 10 — overflow only)".
- **Tier 2 has been touched** — Tier 2 row shows "Active  ·  340 MB / 16 GB
  used  ·  priority 10 (overflow only)". The 340 MB tells them their
  Tier 1 has overflowed at some point and that increasing zram size or
  swappiness might help.
- **Looking at swappiness** — sees the existing `60 / 100 / 150 / 180+`
  tier guidance line, plus a new sentence: "Global kernel setting —
  applies to swap as a whole. Tier order is controlled by priority,
  not swappiness."

No new controls. No editable priority. No fake "Tier 2 swappiness". The
underlying Linux semantics are now visible, not hidden behind controls
that imply behaviours the kernel doesn't support.

### Backend

**`src/zram_collector.php`**

The history record shape today is `{t, o, u, l}` (timestamp, original
uncompressed, used compressed, load %). Add `s` (Tier 2 used in bytes,
0 when SSD swap is unconfigured or inactive).

Sourcing: `swapon --bytes --noheadings --show=NAME,USED` filtered to the
configured `ssd_swap_path`. Same parser shape already lives in
`zram_status.php:90-103`; the collector reuses the approach without
sharing code (the collector is intentionally dependency-light for daemon
reliability).

**`src/UnraidZramCard.page`**

The page already calls `zramctl` for Tier 1 (lines 47-53) but only reads
`DISKSIZE,ALGORITHM`. Extend the call to also include `DATA,TOTAL` so
the live-used number is available without a second shell-out. For
Tier 2, the page already reads `/proc/swaps` for the active flag
(lines 80-83) — extend that block to also extract the `Used` column
when the line is found.

The status row markup grows by one line under each tier with the format
`X MB / Y GB used` when active. The existing tile of secondary metadata
(`8 GB · zstd · priority 100`) is unchanged in shape; the priority value
is appended with a short prose suffix.

The swappiness `<dd>` block grows by one short paragraph below the
existing tier guidance.

### Frontend

**`src/js/zram-card.js`**

`filterHistory` keeps the `{o, u}` requirement (those are mandatory; an
entry without them is from the pre-2026.04.17 schema and gets dropped),
but treats `s` as optional. Older entries from a 2026.05.03 collector
have no `s`; the chart will read them as 0 spillover, which is the
correct visual signal ("we don't know, assume zero").

The chart datasets are unchanged in *this* release. Adding a Tier 2
series to a 70 px-tall chart is a UX call to make separately — the
schema is captured now so a future release can visualise it without
needing a collector restart on every server.

### Boot init

No change. `zram_init.sh` does not write history; it only seeds devices.
Existing history files survive the upgrade because `filterHistory` keeps
new-schema entries even when they lack `s`.

## Settings

No new keys.

## Edge Cases

- **SSD swap configured but file missing.** `swapon --show` returns no
  matching row. Collector writes `s=0`. Settings page Tier 2 row falls
  back to the existing "File exists, not active" wording (filesize used
  for size; nothing for used).
- **SSD swap unconfigured (`ssd_swap_path=""`).** Collector writes `s=0`.
  Settings page renders the empty-state drive picker as today; no
  Tier 2 status row to update.
- **Pre-upgrade history.json has no `s` field.** New dashboard JS reads
  these entries through `filterHistory`, which keeps them. If a future
  chart series consumes `s`, the missing value is treated as 0. No
  migration step.
- **Race during read.** Collector reads `/proc/swaps` and SSD swap stats
  on every tick (~3 s); the page reads them once per render. A swapoff
  between collector reads simply makes `s=0` for the next entry; no
  inconsistency since `s` is always a current-state snapshot.

## Verification

### Unit (PHPUnit)

`tests/php/TierObservabilityTest.php` — three textual guard assertions:

1. `zram_status.php` exposes `ssd_swap` with a `used` numeric field
   (regression guard so a future refactor can't silently drop it).
2. Settings page source surfaces Tier 1 used in the active branch
   (must reference both `DATA` or the `data` JSON key from zramctl,
   *and* render it within the active-state block).
3. Settings page source contains the swappiness clarifier line ("Global
   kernel setting" or equivalent canonical phrase).

### Unit (Vitest)

`tests/js/zram-card.test.js` — extend `filterHistory` block:

- Entry with `{t, o, u, l, s}` is kept and all five fields preserved.
- Entry with `{t, o, u, l}` (no `s`) is kept (forward-compat).
- Entry with `s` but missing `o` or `u` is dropped (legacy or partial).

### Smoke (live server)

Extend `tests/smoke.sh` assertion 6:

- After confirming new-schema entries are present, assert that at least
  one entry contains `s` once enough collector ticks have run. Implemented
  as a soft check (warn but pass on cold-boot history that hasn't yet
  rolled over) so flakiness on a freshly restarted collector doesn't
  regress the smoke gate.

Add a new smoke assertion for the settings page text:

- Render `UnraidZramCard.page` via PHP CLI (existing pattern in assertion
  3) and grep for the swappiness clarifier and a priority explainer.
  Hard fail if either string is missing — they're the only UX signal
  for Asks 1 and 3.

### Manual scenarios

1. **No tier 2 ever** — open settings page, see Tier 1 used / size, see
   Tier 2 row absent or in empty-state. No console errors.
2. **Tier 1 + tier 2 active, tier 2 untouched** — both rows show used.
   Tier 2 used reads 0 MB.
3. **Tier 2 has spillover** — fill zram (`stress-ng --vm 2 --vm-bytes
   80%`), watch Tier 2 used climb. Settings page reflects within one
   page reload; collector history gains non-zero `s` entries.
4. **Pre-upgrade history file** — verify rendered chart still draws
   without console errors after upgrade. (Existing legacy-handling
   covers this; new code adds no requirement.)

## Out of scope

- **Editable priority control.** Discussed in Ask 3; rejected. The 100/10
  ordering is load-bearing and an editable knob is a footgun. Priority
  is documented in the explainer text; users who want different values
  can run `swapon -p N` from a terminal.
- **Per-tier swappiness control.** Discussed in Ask 1; the kernel does
  not support it. The clarifier line addresses the misconception
  directly.
- **Tier 2 chart series.** Schema is forward-compatible; the chart UI
  decision (separate axis vs. stacked vs. third dataset on shared axis)
  is a UX concern best made when the data has a few weeks of real-server
  history to study.
- **Cgroup-level swappiness or `memory.swap.high` exposure.** Real
  per-cgroup swap controls exist, but exposing them from a server-wide
  plugin would surprise more users than it helps. Out of scope; out of
  the plugin's mandate.
