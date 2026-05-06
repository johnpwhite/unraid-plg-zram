# Feature: Tier 2 Picker + Log/UX Polish (2026.05.06.01)

## Status
Approved

## Problem
Three rough edges on top of `2026.05.05.02`:

1. **Auto-size info line mixed units** — `50% of 62.6GB = 32055MB`. Both numbers are about RAM allocation; mixing GB on the LHS and MB on the RHS forces the user to mentally convert.
2. **"SSD" still leaks into logs** — the swap file label is `ZRAM_CARD_SSD`; `mkswap`/`swapon` echo it, kernel re-emits it in syslog, our `cmd.log` captures the literal label. After the 2026.04.28 rename pass nothing in our user-facing strings says SSD, but the label itself remained.
3. **Drive picker is incomplete and unsafe**:
    - ZFS pools are silently filtered out by `if (strpos($dev, '/dev/') !== 0) continue;` because ZFS reports the pool name (not a `/dev/...` path) in `/proc/mounts`. User saw their `/mnt/storage` ZFS pool missing from the picker.
    - Multi-device btrfs cache pools show the right warning text but the row is still clickable, so a user can pick it, hit CREATE, and the kernel rejects with `swapon: Invalid argument` — confusing failure mode.
4. **Settings card vertical spacing is loose** — `dl dt { line-height: 2.2 }` produces airy rows. The user wants tighter rows now that the auto-line layout fix already pulled the info hint inline.

## Requirements
- [x] Auto-size info line uses GB on both sides: `50% of 62.6GB = 31.3GB`
- [x] Disk swap files get labelled `ZRAM_CARD_DISK` (was `ZRAM_CARD_SSD`); existing files migrated on next plugin start via `swaplabel` while inactive
- [x] HDD warning string says "no faster disk available" (was "no SSD available")
- [x] Drive picker lists ZFS pools and classifies them as **blocked** — visible but not clickable
- [x] Drive picker classifies multi-device btrfs as **blocked** (was `warn` + clickable, now `blocked` + non-clickable)
- [x] Blocked rows render with `[Not Supported]` badge, red indicator, reduced opacity, `cursor: not-allowed`, no hover highlight
- [x] `selectDrive()` early-returns when called on a blocked row (defence in depth — onclick is also unbound)
- [x] `dl dt` line-height drops from 2.2 → 1.6 to tighten card rows

## Design

### Auto-size GB conversion
- PHP: rename `$autoSizeMB` → `$autoSizeGB`, divide by `1048576` instead of `1024`, round to one decimal
- JS `updateAutoSize()`: same arithmetic shape — `(MEM_KB * pct / 100 / 1048576).toFixed(1) + 'GB'`
- No backend behaviour change; this is a pure render formatting tweak

### Label rename + migration
- `ZRAM_SSD_LABEL` constant value: `'ZRAM_CARD_SSD'` → `'ZRAM_CARD_DISK'`
- Constant **name** preserved (internal identifier policy from 2026.04.28.02)
- New constant `ZRAM_LEGACY_SSD_LABEL = 'ZRAM_CARD_SSD'` (kept for migration code paths and any future label-aware detection)
- `zram_init.sh` gains a relabel step before `swapon`: if `swaplabel` reports the legacy label and the file is inactive, run `swaplabel -L ZRAM_CARD_DISK $SSD_PATH`. Failure is silent — old label keeps working since detection keys on path, not label.
- `zram_actions.php` create flow uses the constant unchanged → new files get `ZRAM_CARD_DISK` automatically

### Drive picker: ZFS support + clickable flag
- Drop the unconditional `/dev/` prefix gate; allow ZFS through (`fstype === 'zfs'`)
- ZFS pools skip sysfs lookups (`/sys/block/$base/...` doesn't apply); transport is `zfs`, model is the pool name
- New filter pass to skip nested mounts (`/mnt/cache/system/docker/...`) and Unraid system dirs (`/mnt/addons`, `/mnt/rootshare`)
- Drive entries gain a `clickable` boolean
- Classify shape now has 4 values: `recommended | ok | warn | blocked`
- `blocked` cases:
    - ZFS (any) — kernel rejects swap files on ZFS datasets
    - Multi-device btrfs — kernel rejects swap files on multi-device btrfs (was `warn`)
- `warn` retained for HDD (slow but legal)

### Frontend rendering
- New CSS classes: `.zram-drive-row-blocked` (cursor not-allowed, opacity 0.55, hover keeps default border), `.indicator-red`
- `loadDrives()` builds the `onclick` attribute conditionally — blocked rows get no handler
- `selectDrive()` checks `el.classList.contains('zram-drive-row-blocked')` and bails

### Tighter rows
- `.zram-card-body dl dt { line-height: 1.6 }` (was 2.2)
- `.zram-card-body dl dd { margin-bottom: 2px; line-height: 1.6 }` (was 4px / default)

## Settings
None.

## Edge Cases
- **Existing user with `ZRAM_CARD_SSD`-labelled file, swap currently active at boot**: skip relabel, log the "already active" branch. File label stays old. No functional regression — detection works on path. Next reboot where swap is offline at the relabel point will catch up.
- **`swaplabel` not present**: skip relabel silently. Kernel keeps reading the legacy label.
- **ZFS pool with no real free space**: `disk_free_space()` returns the dataset's available bytes (zfs reports it correctly). Listing still shows the pool with the value; click is blocked anyway.
- **Btrfs single-device cache pool**: `btrfs filesystem show | grep -c devid` returns 1 → `btrfsRaid = false` → classifies as `recommended` (NVMe) or `ok` (SSD). Single-device btrfs swap files ARE supported by the kernel (with NOCOW set at mkswap time, which `mkswap -L` does not handle — open question if we should `chattr +C` on the swap file before `mkswap`. Out of scope for this release; tracked for future work.)
- **Mount appears twice in /proc/mounts** (e.g. bind mount): each entry is processed independently. The second entry will likely be a nested mount and get filtered by the nested-mount rule.

## Verification

### L2 (PHPUnit)
- New file `tests/php/Tier2PickerPolishTest.php`:
    - Status JSON shape: drive entries have a `clickable` boolean
    - `zram_drives.php` classifies ZFS as `blocked` and `clickable=false`
    - Multi-device btrfs check still in place (textual guard on `'devid'` count)
    - HDD warning string contains "faster disk" (not "SSD")
    - Page render shows `autoSizeGB` (not `autoSizeMB`)
    - `ZRAM_SSD_LABEL` constant value is `ZRAM_CARD_DISK`
    - `zram_init.sh` contains the relabel block

### L3 (smoke.sh)
- Existing assertions retain green
- New soft assertion: `mkswap`/`swapon` log lines after a fresh `create_ssd_swap` contain `ZRAM_CARD_DISK` (not the legacy label) — only run if Tier 2 is configured on the test host
- Existing `overflow only` / `used first` / `Global kernel setting` UX assertions stay

### L1 (lint)
- `php -l` clean on all changed files
- Vitest suite unchanged (no new behaviour exercised by JS)

### Manual (post-deploy)
- Open settings page on the test server
- Confirm Tier 2 picker now lists `/mnt/storage` (ZFS) with red indicator, "[Not Supported]", grey-out, no hover effect, click does nothing
- Confirm `/mnt/cache` (btrfs RAID) shows red "[Not Supported]" and is unclickable
- Confirm `/mnt/swap` (xfs) shows green/recommended and IS clickable
- Confirm Tier 1 status row: `Used: X / Y · zstd · priority 100 — used first`
- Confirm Tier 1 settings: Size dropdown + custom-size input + auto info hint all on one line; auto info reads `50% of 62.6GB = 31.3GB`
- Confirm settings card is visibly tighter than 2026.05.05.02
