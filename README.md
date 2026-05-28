# Unraid ZRAM — Tiered Swap Manager

A smart swap manager for Unraid that combines compressed RAM (ZRAM) with optional disk overflow protection. Helps memory-constrained servers avoid OOM crashes.

## How It Works

```
Tier 1: ZRAM Swap (always active)
  Compressed in RAM — fast, ~3:1 ratio
  8 GB server → effectively ~20 GB usable memory

Tier 2: Disk Swap File (optional)
  Overflow to a writable disk when ZRAM fills (NVMe/SSD recommended)
  Prevents OOM — the kernel handles overflow automatically via priority
```

## Features

- **Auto-Sizing**: Automatically sizes ZRAM to a percentage of your RAM (configurable 25-75%, default 50%).
- **Live Dashboard Card**: Real-time stats — RAM saved, compression ratio, CPU load, and a 1-hour history chart.
- **Disk Overflow Protection**: Optional swap file on any allowed mount (NVMe/SSD recommended; HDD permitted with a warning) for when ZRAM fills up. Includes a drive picker with smart media detection.
- **Device Isolation**: Labels all managed devices. Only shows its own ZRAM — won't interfere with other plugins that use ZRAM.
- **Safe Operations**: OOM evacuation checks before removing swap. Atomic config writes. CSRF protection on all actions.
- **Boot Persistence**: Automatically recreates your swap configuration on every boot.
- **Compression Choice**: Support for all kernel-supported algorithms (`zstd`, `lz4`, `lzo`, etc.).

## Installation

### Community Applications (Recommended)
Search for **"Unraid ZRAM"** in the Apps tab.

### Manual URL
Copy and paste into **Plugins > Install Plugin**:
`https://github.com/johnpwhite/unraid-plugin-zram/raw/main/unraid-zram-card.plg`

## Settings Guide

### Tier 1: ZRAM Swap
- **Size**: Auto (percentage of RAM) or a fixed size like `4G`.
- **Auto Size Slider**: 25-75% of physical RAM. Default 50%. Shows calculated size in real-time.
- **Algorithm**: `zstd` recommended (best compression ratio with good speed).
- **Swappiness**: 0-200 (kernel 5.8+ range). Default **150**, recommended for ZRAM systems — tells the kernel that compressed-RAM swap is cheaper than dropping page cache. Use 100 for legacy parity, 180+ for aggressive zram with disk swap as fallback.

### Tier 2: Disk Swap File
- **Drive Picker**: Shows eligible mounted filesystems with smart classification:
  - NVMe: Recommended (green)
  - SATA SSD: OK (green)
  - HDD: Not recommended (orange warning)
  - USB/Removable: Hidden
  - Btrfs RAID: Warned (swap files not supported on multi-device btrfs)
- **Size**: Configurable. 16 GB default.
- **Priority**: Automatically set to 10 (ZRAM is 100). Kernel uses ZRAM first, overflows to the disk tier only when needed.

### Plugin Settings
- **Refresh Interval**: Dashboard update frequency (default 3000ms).
- **Collection Interval**: Background history recording frequency (default 3s).
- **Debug Mode**: Detailed logging for troubleshooting.
- **Command Console**: Real-time log of all plugin actions.

## Requirements
- Unraid 6.12.1 or newer (optimized for Unraid 7.2+).
- ZRAM kernel module (standard in Unraid).
- For Tier 2: A writable mount under `/mnt/cache` or `/mnt/disks` with a supported filesystem (XFS, ext4, or single-device btrfs). NVMe/SSD recommended; HDD permitted but slower.

---
*Created by John White*
