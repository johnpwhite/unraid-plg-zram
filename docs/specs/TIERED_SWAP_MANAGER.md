# Feature: Tiered Swap Manager

## Status
Approved

## Problem

The ZRAM Card plugin currently manages ZRAM swap devices but has several architectural limitations:

1. **No device isolation** — Shows and manages ALL zram devices on the system, including those owned by other plugins (e.g., aicliagents uses a zram device formatted as ext4 with label `AICLI_ZRAM`). Users see foreign devices they shouldn't touch.

2. **No overflow protection** — On a memory-constrained server (8-16GB), once ZRAM fills up there's nowhere to go. The OOM killer fires. There is no second tier.

3. **Manual sizing** — Users must guess a ZRAM size. Too small wastes potential; too large steals RAM from applications.

4. **Code quality defects** — Shell injection vectors, race conditions on config writes, wrong device removed from config, and several standards violations (see Appendix A).

## Requirements

### Core (Must Have)
- [ ] Label all plugin-managed ZRAM devices with `ZRAM_CARD` via `mkswap -L`
- [ ] Filter all device listings to only show devices with our label (or unlabeled swap for migration)
- [ ] Exclude devices with foreign labels (e.g., `AICLI_ZRAM`)
- [ ] Auto-size ZRAM based on system RAM (default 50%, configurable slider)
- [ ] Single ZRAM device model (simplify from current multi-device)
- [ ] Fix all P0/P1 bugs from code review (Appendix A)

### Tier 2: SSD/NVMe Swap File (Must Have)
- [ ] Drive picker showing eligible mounted filesystems
- [ ] Media type detection: recommend SSD/NVMe, warn on HDD, hide USB/removable
- [ ] Create/manage a swap file on the selected filesystem
- [ ] Automatic priority assignment: ZRAM=100, SSD=10
- [ ] Configurable swap file size with sensible defaults
- [ ] Safe removal (evacuation check before swapoff)

### Standards Compliance (Must Have)
- [ ] CSRF token validation on all actions
- [ ] Migrate AJAX from POST to GET (POST hangs on Unraid NGINX)
- [ ] `filter_input()` for all request parameters
- [ ] `escapeshellarg()` on all shell command arguments
- [ ] Atomic config writes with file locking
- [ ] Split large files to stay under 150-line target
- [ ] Add `<module_context>` docblocks to all PHP files

### Dashboard (Should Have)
- [ ] Tiered view showing ZRAM tier and disk swap tier separately
- [ ] Show total physical RAM for context
- [ ] Show effective memory (RAM + ZRAM effective + disk swap)
- [ ] Indicate which tier is currently active (ZRAM only vs overflowing to disk)
- [ ] Memory pressure indicator

### Nice to Have
- [ ] `mem_limit` parameter exposed in UI (caps ZRAM physical RAM usage)
- [ ] `/tmp` on ZRAM option (compressed tmpfs)
- [ ] `/var/log` on ZRAM option (compressed log storage)

## Design

### Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│                    ZRAM Card Plugin                  │
│                                                     │
│  Tier 1: ZRAM Swap (always active)                  │
│  ┌───────────────────────────────────────────────┐  │
│  │ /dev/zramN (label: ZRAM_CARD)                 │  │
│  │ Priority: 100                                 │  │
│  │ Size: auto (50% of RAM) or user-configured    │  │
│  │ Algorithm: zstd                               │  │
│  │ Compressed in RAM, ~3:1 ratio                 │  │
│  └───────────────────────────────────────────────┘  │
│                                                     │
│  Tier 2: Disk Swap File (optional, user-enabled)    │
│  ┌───────────────────────────────────────────────┐  │
│  │ /mnt/cache/.swap/zram-card.swap (or similar)  │  │
│  │ Priority: 10                                  │  │
│  │ Size: user-configured (default: 2x RAM)       │  │
│  │ Plain swap on SSD/NVMe filesystem             │  │
│  │ Only activated when ZRAM tier is full          │  │
│  └───────────────────────────────────────────────┘  │
│                                                     │
│  Dashboard: Shows both tiers, effective memory      │
└─────────────────────────────────────────────────────┘
```

### Device Labeling

**Label constant:** `ZRAM_CARD`

**Creation:** `mkswap -L ZRAM_CARD /dev/zramN`

**Detection:** `blkid -s LABEL -o value /dev/zramN`

**Filtering logic (applied everywhere devices are listed):**
```
For each zram device from zramctl:
  label = blkid -s LABEL -o value /dev/zramN
  if label == "ZRAM_CARD"        → SHOW (ours, labeled)
  if label == "" AND in /proc/swaps → SHOW (legacy unlabeled swap, likely ours pre-update)
  if label == something_else      → HIDE (another plugin's device)
  if not in /proc/swaps           → HIDE (not swap-formatted, e.g. ext4 mount)
```

**Migration path:** Existing devices work unlabeled until next reboot, when `zram_init.sh` recreates them with the label. No disruptive relabeling of running devices.

### Single Device Model

The current multi-device model adds complexity without benefit on modern kernels (per-CPU compression streams since kernel 3.15+). We simplify to:

- **One ZRAM swap device** managed by this plugin
- Config changes from `zram_devices="1G:zstd,2G:lz4"` to `zram_size="auto"` / `zram_size="4G"`
- Remove device table from settings page; replace with single-device status/config
- Dashboard device list still shows the one device with stats

**Migration:** On first boot after update, if `zram_devices` has multiple entries, create a single device with the sum of all sizes. Log the migration.

### Auto-Sizing

```php
$memTotal = intval(trim(shell_exec("awk '/MemTotal/{print $2}' /proc/meminfo"))) * 1024;
$zramSize = intval($memTotal * ($settings['zram_percent'] / 100));
```

| System RAM | Default ZRAM (50%) | Effective with 3:1 ratio |
|-----------|-------------------|-------------------------|
| 8 GB      | 4 GB              | ~12 GB usable           |
| 16 GB     | 8 GB              | ~24 GB usable           |
| 32 GB     | 16 GB             | ~48 GB usable           |
| 64 GB     | 32 GB             | ~96 GB usable           |

Settings: slider from 25% to 75% of RAM, or "Custom" for manual entry. Display calculated size in real-time.

### SSD/NVMe Swap File

**Drive discovery:**
```bash
# Get mounted filesystems, exclude: tmpfs, proc, sysfs, devtmpfs, overlay, squashfs, fuse
# For each:
#   Check /sys/block/<dev>/queue/rotational (0=SSD, 1=HDD)
#   Check /sys/block/<dev>/removable (1=USB)
#   Check available space
```

**Media classification:**
| rotational | removable | Device path     | Classification | UI treatment |
|-----------|-----------|-----------------|---------------|--------------|
| 0         | 0         | /dev/nvme*      | NVMe          | Green, recommended |
| 0         | 0         | /dev/sd*        | SATA SSD      | Green, OK |
| 0         | 1         | /dev/sd*        | USB SSD       | Orange, warn |
| 1         | 0         | /dev/sd*        | HDD           | Orange, strong warn |
| *         | 1         | /dev/sd* (boot) | USB flash     | Hidden entirely |

**Swap file management:**
```bash
# Create
SWAP_DIR="/mnt/cache/.swap"          # or user-selected mount
mkdir -p "$SWAP_DIR"
dd if=/dev/zero of="$SWAP_DIR/zram-card.swap" bs=1M count=$SIZE_MB status=progress
chmod 600 "$SWAP_DIR/zram-card.swap"
mkswap -L ZRAM_CARD_SSD "$SWAP_DIR/zram-card.swap"
swapon -p 10 "$SWAP_DIR/zram-card.swap"

# Remove (with safety check)
swapoff "$SWAP_DIR/zram-card.swap"
rm "$SWAP_DIR/zram-card.swap"
```

**Label:** `ZRAM_CARD_SSD` (distinct from `ZRAM_CARD` for the zram device)

**Boot persistence:** `zram_init.sh` checks config for swap file path and re-activates on boot if the file exists and the mount is available.

### Settings Model

**New `settings.ini` format:**
```ini
enabled="yes"
refresh_interval="3000"
collection_interval="3"
swappiness="100"
debug="no"
console_visible="yes"

# Tier 1: ZRAM
zram_size="auto"
zram_percent="50"
zram_algo="zstd"

# Tier 2: SSD Swap (optional)
ssd_swap_enabled="no"
ssd_swap_path=""
ssd_swap_size="16G"
ssd_swap_mount=""
```

**Removed fields:** `swap_size`, `compression_algo`, `zram_devices` (migrated on first boot)

### User Experience

**Settings page layout:**
```
┌──────────────────────────────────────────────────────────┐
│ Tier 1: ZRAM Swap                                        │
│ ┌──────────────────────────────────────────────────────┐ │
│ │ Status: Active (/dev/zram1, 4 GB, zstd)              │ │
│ │                                                      │ │
│ │ Size:       [Auto (50% of RAM) ▼]  = 4 GB of 8 GB   │ │
│ │ Algorithm:  [zstd ▼]                                 │ │
│ │ Swappiness: [====●=====] 100                         │ │
│ │                                                      │ │
│ │ [APPLY]  [RESET DEVICE]                              │ │
│ └──────────────────────────────────────────────────────┘ │
│                                                          │
│ Tier 2: Disk Swap File (Overflow Protection)             │
│ ┌──────────────────────────────────────────────────────┐ │
│ │ Status: Inactive                                     │ │
│ │                                                      │ │
│ │ Enable: [Yes ▼]                                      │ │
│ │ Drive:  [● /mnt/cache (Samsung 990 Pro, 800GB free)] │ │
│ │ Size:   [16 GB]                                      │ │
│ │                                                      │ │
│ │ ⚠ /dev/sdb (WD Red 4TB) — HDD, not recommended      │ │
│ │                                                      │ │
│ │ [CREATE SWAP FILE]                                   │ │
│ └──────────────────────────────────────────────────────┘ │
│                                                          │
│ Plugin Settings                                          │
│ ┌──────────────────────────────────────────────────────┐ │
│ │ Dashboard:      [Enabled ▼]                          │ │
│ │ Refresh:        [3000] ms                            │ │
│ │ Collection:     [3] sec                              │ │
│ │ Debug Mode:     [ ]                                  │ │
│ │ Console:        [Visible ▼]                          │ │
│ └──────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────┘
```

**Dashboard card:**
```
┌─────────────────────────────────────────────┐
│ 🔄 ZRAM STATUS              3.0s  ⚙        │
│ Active (1 dev) + SSD Overflow               │
├─────────────────────────────────────────────┤
│ RAM Saved  Ratio  Used   Swap Tier  Swpns   │
│  2.1 GB   3.2x   680MB   ZRAM      100     │
│                                             │
│ [════════════════════ chart ═══════════════] │
│                                             │
│ Tier 1: zram1  4 GB  zstd  (prio 100)      │
│ Tier 2: /mnt/cache  16 GB  (prio 10) idle  │
│                                             │
│ Effective Memory: 8 GB + 12 GB + 16 GB      │
│                   RAM    ZRAM     SSD        │
└─────────────────────────────────────────────┘
```

### Backend File Structure (Post-Refactor)

```
src/
├── zram_init.sh              # Boot init (label-aware create, swap file reactivate)
├── zram_collector.php         # Background stats collector (filtered to our devices)
├── zram_status.php            # Status API (filtered, tiered response)
├── zram_actions.php           # Device/swap management actions (was zram_swap.php)
├── zram_config.php            # Config read/write with file locking (new, shared)
├── zram_drives.php            # Drive discovery API for swap file picker (new)
├── ZramCard.php               # Dashboard card renderer (filtered)
├── UnraidZramCard.page        # Settings page
├── UnraidZramDash.page        # Dashboard loader
├── js/
│   ├── chart.min.js
│   └── zram-card.js           # Updated for tiered display
└── unraid-zram-card.png
```

**Key refactors:**
- `zram_swap.php` renamed to `zram_actions.php`, split into smaller action handlers
- New `zram_config.php` with `flock()`-based atomic config read/write (fixes race condition)
- New `zram_drives.php` for drive discovery API
- All files get `<module_context>` docblocks
- All shell commands use `escapeshellarg()`
- All request params use `filter_input()`
- CSRF validation on all mutating actions

### Boot Sequence (`zram_init.sh`)

```
1. Load config from settings.ini
2. Apply swappiness: sysctl vm.swappiness=$val

3. Tier 1 - ZRAM:
   a. Check for existing ZRAM_CARD labeled device in blkid
   b. If found and active in /proc/swaps → skip (idempotent)
   c. If not found:
      - modprobe zram
      - Calculate size (auto or fixed from config)
      - zramctl --find --size $SIZE --algorithm $ALGO
      - mkswap -L ZRAM_CARD /dev/zramN
      - swapon -p 100 /dev/zramN

4. Tier 2 - Disk Swap File (if enabled):
   a. Check if mount point is available
   b. Check if swap file exists at configured path
   c. If exists → swapon -p 10 $PATH (idempotent: check /proc/swaps first)
   d. If mount not ready → log warning, skip (will activate when mount appears)

5. Launch collector (kill existing first, SIGTERM + wait + SIGKILL fallback)
```

### Config Locking (`zram_config.php`)

```php
function zram_config_read() {
    $configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";
    return @parse_ini_file($configFile) ?: [];
}

function zram_config_write($settings) {
    $configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";
    $lockFile = "/tmp/unraid-zram-card/config.lock";
    
    $fp = fopen($lockFile, 'c');
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    
    // Re-read under lock to prevent lost updates
    $current = @parse_ini_file($configFile) ?: [];
    $merged = array_merge($current, $settings);
    
    $lines = [];
    foreach ($merged as $k => $v) $lines[] = "$k=\"$v\"";
    $result = file_put_contents($configFile, implode("\n", $lines));
    
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result !== false;
}
```

## Settings

| Setting | Default | Range | Description |
|---------|---------|-------|-------------|
| `zram_size` | `"auto"` | `"auto"` or size string | ZRAM device size |
| `zram_percent` | `"50"` | 25-75 | Percent of RAM when auto |
| `zram_algo` | `"zstd"` | Kernel-supported algos | Compression algorithm |
| `swappiness` | `"150"` | 0-200 | vm.swappiness value (kernel 5.8+ range; see SWAPPINESS_RANGE_EXTENSION.md) |
| `ssd_swap_enabled` | `"no"` | yes/no | Enable Tier 2 |
| `ssd_swap_path` | `""` | File path | Path to swap file |
| `ssd_swap_size` | `"16G"` | Size string | Swap file size |
| `ssd_swap_mount` | `""` | Mount point | Parent filesystem mount |

## Edge Cases

- **No SSD available**: Tier 2 section shows "No eligible drives found" with explanation
- **SSD removed/unmounted**: Boot init logs warning, Tier 2 stays inactive, dashboard shows "Tier 2: Unavailable (mount missing)"
- **Array not started**: SSD may not be mounted yet; init script must handle delayed activation or re-check
- **Other plugin owns zram0**: Our device gets zram1+ via `zramctl --find`; label ensures we track it correctly regardless of device number
- **Migration from multi-device config**: Sum all device sizes, create single device, log the change
- **Swap file on cache pool that fills up**: Swap file is pre-allocated (dd), so pool free space drops at creation time, not at swap time
- **ZRAM device removed by external tool**: Dashboard shows "Tier 1: Inactive", offers re-create button
- **Config file missing/corrupt**: Fall back to defaults, recreate config, log the event
- **Concurrent AJAX requests**: flock() serializes config writes; actions that modify kernel state (swapoff/swapon) should also serialize via a separate lock

## Verification

### Tier 1 (ZRAM)
- [ ] Fresh install creates single labeled ZRAM device
- [ ] `blkid /dev/zramN` shows `LABEL="ZRAM_CARD"`
- [ ] Dashboard only shows our labeled device, not aicliagents ZRAM
- [ ] Auto-sizing calculates correctly for different RAM amounts
- [ ] Device survives reboot (recreated by init script)
- [ ] Settings change triggers device recreation with new params

### Tier 2 (SSD Swap)
- [ ] Drive picker shows only eligible drives with correct classification
- [ ] Swap file created with correct size and permissions (600)
- [ ] `swapon --show` lists swap file with priority 10
- [ ] Swap file survives reboot (reactivated by init script)
- [ ] Safe removal: evacuation check before swapoff
- [ ] Missing mount on boot: graceful degradation, no errors

### Migration
- [ ] Existing multi-device config migrated to single device on first boot
- [ ] Unlabeled legacy devices shown during transition period
- [ ] Config format upgraded without data loss

### Standards Compliance
- [ ] CSRF token validated on all mutating actions
- [ ] All AJAX uses GET method
- [ ] All request params go through filter_input()
- [ ] All shell args escaped with escapeshellarg()
- [ ] Config writes are atomic (flock)
- [ ] No file exceeds 150 lines (readability target)
- [ ] All PHP files have <module_context> docblocks

### Security
- [ ] Shell injection test: device name with semicolons/pipes rejected
- [ ] Config injection test: setting values with quotes handled
- [ ] Swap file path traversal: only accepts paths under known mount points

---

## Appendix A: Bugs Found in Code Review

### P0 — Critical

**A1. Wrong device removed from config** (`zram_swap.php:291`)
```php
array_pop($devs);  // Always removes LAST entry, not the target device
```
When removing a specific device (e.g., zram0 of [zram0, zram1]), this pops the last config entry regardless. Kernel device is removed correctly but config describes wrong device set. On reboot, wrong devices recreated.

**A2. Shell injection** (`zram_swap.php:209, 277, 287`)
```php
run_cmd("swapoff $devPath", ...);  // $devPath from $_REQUEST, not escaped
```
`$device` from `$_REQUEST['device']` flows to shell commands without `escapeshellarg()`. The `create` action escapes `$size` and `$algo` but `remove` and `update_priority` don't escape device paths.

### P1 — High

**A3. Priority index mismatch** (`zram_swap.php:213-214`)
```php
$index = intval(str_replace('zram', '', $devName));  // Assumes zram number = array index
```
If another plugin owns zram0, our first device is zram1 but config array index 0. Priority update writes to wrong config entry or does nothing.

**A4. Config write race condition** (5 locations in `zram_swap.php`)
Read-modify-write without any locking. Two concurrent AJAX requests can corrupt or lose each other's writes.

**A5. Flash reads every 3 seconds** (`zram_collector.php` via `zram_log`)
Every collector iteration calls `zram_log('...', 'DEBUG')` which calls `parse_ini_file()` on the flash-backed config to check the debug flag, even when debug is off.

### P2 — Medium

**A6. Missing exit after action handlers** (`zram_swap.php:167-296`)
The `clear_log` handler at line 298 uses `if` not `elseif`, so it evaluates after other handlers that don't `exit`.

**A7. Default swap_size mismatch**
PLG installer writes `swap_size="1G"`, settings page defaults to `'swap_size' => '4G'`.

**A8. Non-atomic history file** (`zram_collector.php:119`)
`file_put_contents` without `LOCK_EX`; status API reads simultaneously. Partial JSON possible.

**A9. Collector kill race** (`zram_init.sh:122-131`)
`kill $PID; sleep 1` may not be enough. New collector's own PID check may see old process still alive and exit.

**A10. chmod 666 on every log write** — redundant syscall after first write.

**A11. Algorithm list requires zram0** (`UnraidZramCard.page:93`)
Falls back to hardcoded list if `/sys/block/zram0/comp_algorithm` missing (another plugin owns zram0 or no module loaded).

**A12. time() cache-buster on chart.min.js** (`ZramCard.php:221`)
Defeats browser caching on a ~200KB library. Should use plugin version.

### P3 — Standards

**A13. No CSRF validation** — Required by Unraid plugin standards on all mutating actions.

**A14. POST for AJAX** — Standards mandate GET (POST hangs on Unraid NGINX for some request types).

**A15. No filter_input()** — Direct `$_REQUEST` access throughout.

**A16. No <module_context> docblocks** — Required on all PHP files per LLM-optimized standards.

**A17. Files exceed 150-line target** — `zram_swap.php` (309), `ZramCard.php` (235), `UnraidZramCard.page` (568).

**A18. Hardcoded theme colors** — Should detect Unraid theme and use CSS variables.

**A19. zram_log function duplicated 4 times** — Should be a single shared include.
