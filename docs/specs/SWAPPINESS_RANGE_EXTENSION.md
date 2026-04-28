# Feature: Honest swap-tier guidance — extended swappiness range and accurate Tier 2 copy

## Status
Approved

## Problem

The plugin clamps `vm.swappiness` to `0–100` and defaults to `100`, both of which are
artifacts of pre-kernel-5.8 Linux behaviour. Since kernel 5.8 (August 2020), the
range is `0–200`, and Unraid 7.2 ships kernel `6.12.54-Unraid` — well past that
boundary. Capping at 100 strictly under-uses zram on every Unraid version this
plugin supports.

The 5.8 extension was made *specifically for zram/zswap users*: under the old
range, value 100 meant "swap IO cost equals file-cache IO cost". With compressed
RAM swap, swap is genuinely *cheaper* than evicting page cache, so values
101–200 model the real cost relationship. This plugin is the textbook use case
for the extended range.

## Requirements

- [ ] Settings UI input accepts `0–200` (HTML attribute + server-side clamp)
- [ ] AJAX action endpoint `update_swappiness` accepts `0–200`
- [ ] Default swappiness for fresh installs is `150` (not `100`)
- [ ] Existing users with `swappiness=100` (or any other explicit value) keep
      their setting on upgrade — no forced migration
- [ ] Settings UI shows tier guidance so users understand what values mean
- [ ] Plugin label updated from "Swappiness (0-100)" to "Swappiness (0-200)"

## Design

### User Experience

- Settings page swappiness field gains a wider numeric range (0–200) and a
  short helper line below the input listing the canonical tiers:
  `60 default, 100 equal, 150 zram-recommended, 180+ aggressive`
- Existing users notice nothing on upgrade (their saved value is preserved).
- Fresh installs run at 150 from boot, which is the recommended setting for a
  zram-priority swap topology.

### Backend

Three files enforce the 100 cap; all three move to 200:

| File | Concern |
| :--- | :--- |
| `src/UnraidZramCard.page` | settings POST handler (`min(100, …)`) |
| `src/UnraidZramCard.page` | HTML `<input max="100">` |
| `src/zram_actions.php` | AJAX action `update_swappiness` filter range |

Two files hold the default for fresh installs / missing config keys:

| File | Concern |
| :--- | :--- |
| `src/zram_config.php` | `ZRAM_DEFAULTS['swappiness']` |
| `src/zram_init.sh` | `if [ -z "$SWAPPINESS" ]; then SWAPPINESS=…; fi` |

All fallbacks (POST handler `?? 150`, AJAX filter rejection `$val = 150`,
config defaults, init-script empty branch) use the same value: `150`. These
fallbacks only fire on malformed input or first-install — never during normal
form submission by an existing user — so there is no migration risk from
unifying them.

### Frontend

No JavaScript changes. The only swappiness JS reference is a read-only
display element (`zram-swappiness`). No quick-set slider exists.

## Settings

Affected setting: `swappiness` (existing).

| Property | Old | New |
| :--- | :--- | :--- |
| Range | 0–100 | 0–200 |
| Default (fresh install) | 100 | 150 |
| Default (existing config preserved) | n/a | n/a (no migration) |

## Edge Cases

- **Existing user upgrades from 100 cap:** Their saved value (whatever it is,
  100 or other) is preserved. No surprise jump to 150.
- **User with no saved config (default-of-default case):** They jump from
  100 → 150 on upgrade. This matches the spec — they were on "default" before
  and should be on "default" after.
- **Malformed POST (missing field, non-numeric):** Falls back to `150` in the
  clamp expression. Field is always sent by the form, so this only fires for
  hand-crafted requests.
- **AJAX action with out-of-range value (e.g. 250):** `filter_input` rejects;
  fallback is `150`. The endpoint is always called with an explicit value by
  the UI, so this only fires for direct API calls.
- **Older kernel (< 5.8) running this plugin:** Not a real concern — Unraid
  7.2 (the supported target) is on kernel 6.12. If somehow run on an older
  kernel, `sysctl vm.swappiness=150` would be silently clamped to 100 by the
  kernel itself, which is acceptable degradation.

## Verification

### L1 (static)
- PHPStan & ESLint pass unchanged.

### L2 (PHPUnit + Vitest)
- `tests/php/ConfigTest.php` default-fallback assertion updated to `'150'`.
- New test case: explicitly setting `swappiness => '180'` round-trips
  correctly through `zram_config_write` / `zram_config_read`. Guards against
  any future regression that clamps at the storage layer.
- Test fixtures (`tests/bootstrap.php`, `tests/fixtures/settings.ini`,
  `tests/smoke.sh`) updated to `'150'` for default-state alignment.

### L3 (SSH smoke)
- `smoke.sh` already asserts swappiness setting; verify `/proc/sys/vm/swappiness`
  reads `150` after fresh install (no prior config).

### L4 (visual review)
- Settings page screenshot confirms the new label "(0-200)" and helper text
  renders cleanly within the existing settings panel layout.

### Manual
- Set value to `200` via the UI → confirm `cat /proc/sys/vm/swappiness`
  returns `200` on the live server.
- Set value to `175` via the AJAX action endpoint → confirm round-trip.
- Save settings with no swappiness change → confirm no incidental migration
  for an existing user with `swappiness=100`.

## Tier 2 copy honesty (bundled scope extension)

The Tier 2 drive picker contained three pieces of misleading copy that
discourage legitimate use cases — particularly users with HDD-only cache
pools. These are bundled here because they share a theme with the swappiness
work: giving users accurate tuning information rather than fear-driven
defaults.

### The "parity interference" claim

`zram_drives.php:105` warned: *"HDD swap causes severe performance
degradation and may interfere with parity operations."*

The first half is defensible (HDDs do ~100 random IOPS; swap is random IO
by nature). The second half is **not supported by any direct mechanism**
given the plugin's drive filter:

- Parity drives are not mounted filesystems and never appear in
  `/proc/mounts` — the picker can't surface them regardless of filter rules.
- Array data disks (`/mnt/disk\d+`) are explicitly excluded at line 36.
- Allowed mounts are cache pool (`/mnt/cache`) and Unassigned Devices
  (`/mnt/disks/*`), which are wholly separate from the array's parity
  computation.

The only way HDD cache-swap could "interfere with parity" is indirect
system-wide IO contention during a concurrent parity check — same impact
as any other heavy workload on the cache, not a parity-specific risk. The
warning is replaced with honest copy that calls out the real symptom
(random-IO slowness) and acknowledges the legitimate framing that bad
swap beats OOM.

### "No eligible SSD/NVMe drives found"

`zram-settings.js:57` empty-state implied SSD/NVMe was the eligibility
criterion, but the picker actually accepts HDDs (with a warning). For an
HDD-only host, the message is misleading. Replaced with copy that
describes the actual eligibility rule (writable mount under `/mnt/cache`
or `/mnt/disks`).

### "Tier 2: SSD Swap File"

`UnraidZramCard.page:184` and downstream docs (`README.public.md`,
`TIERED_SWAP_MANAGER.md`) labelled Tier 2 as "SSD Swap File", which
excludes HDD users from the mental model and is internally inconsistent
with the section's `fa-hdd-o` icon. Renamed to "Disk Swap File" which is
accurate for any allowed mount class (NVMe, SATA SSD, HDD, USB-attached).

### Out of Tier-2-rename scope

Internal config keys (`ssd_swap_*`), the `mkswap` label
(`ZRAM_CARD_SSD`), and the `SSD_LABEL` shell constant are stable
identifiers. Renaming them would require config migration for existing
users with no functional benefit. Left as-is.

## Out of scope

- Changing the swap-device priority scheme (zram=100 / SSD=10) — that's a
  separate spec (`TIERED_SWAP_MANAGER.md`).
- Per-cgroup swappiness (kernel feature, not exposed by this plugin).
- Auto-tuning swappiness based on observed memory pressure.
- Renaming internal `ssd_swap_*` config keys / `ZRAM_CARD_SSD` label.
