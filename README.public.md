# Unraid ZRAM Status & Management

A modern, high-contrast dashboard card and management utility for ZRAM swap devices on Unraid 7.2+.

![Dashboard Preview](https://raw.githubusercontent.com/johnpwhite/unraid-plugin-zram/main/docs/images/dashboard.png)

## Features

*   **Real-time Dashboard Card:** Monitor RAM saved, compression ratio, and actual ZRAM usage with a live-updating history chart.
*   **On-the-fly Management:** Create and remove ZRAM swap devices directly from the Settings page.
*   **Compression Choice:** Support for all kernel-supported algorithms (`zstd`, `lz4`, `lzo`, etc.).
*   **Boot Persistence:** Automatically re-applies your ZRAM configuration on every boot.
*   **Safe Evacuation:** Built-in guards to prevent OOM crashes when removing ZRAM devices.
*   **Air-Gap Support:** "Hybrid Installer" logic supports servers without internet access via manual file placement.

## Understanding ZRAM
ZRAM is a tool that creates a "compressed swap" area in your RAM. Instead of your computer slowing down when it runs out of memory, it compresses older data into a smaller space within your RAM. This is much faster than using a hard drive or SSD for swap.

## Installation

### Method 1: Community Applications (Recommended)
Search for **"Unraid ZRAM"** in the Apps tab.

### Method 2: Manual URL
Copy the link below and paste it into the **Plugins > Install Manager** tab:
`https://github.com/johnpwhite/unraid-plugin-zram/raw/main/unraid-zram-card.plg`

## Settings & Usage Guide

### Plugin Settings
- **Enable Dashboard**: Controls whether the ZRAM status card appears on your main Unraid Dashboard.
- **Refresh Interval (ms)**: How often the Dashboard card updates its live statistics (Default: 3000).
- **Collection Interval (sec)**: How often the background service records data for the history chart (Default: 3).
- **Swappiness (0-100)**: Controls how "aggressive" the system is about using ZRAM. A value of **100** is recommended for ZRAM.
- **Debug Mode**: Records detailed technical logs for troubleshooting.
- **Show Command Console**: Displays a real-time terminal window at the bottom of the settings page.

### Managing ZRAM Devices
1.  Navigate to **Settings > Unraid ZRAM**.
2.  **Adding a Device**: Enter a size (e.g., `4G`), choose a compression algorithm (`zstd` recommended), and click **Create Device**.
3.  **Removing a Device**: Click the **X** icon next to the device. Note: Devices cannot be resized while in use.
4.  **Adjusting Priority**: Click the **Pencil icon** next to a device, enter a new priority number (higher is used first), and click **Apply Changes**.

### Diagnostics & Logs
- **Command History**: Shows a simplified log of all actions taken (persists across refreshes).
- **System Debug Log**: Shows raw technical output from background services.

## Requirements
*   Unraid 6.12.1 or newer (Optimized for Unraid 7.2+).
*   ZRAM kernel module (Standard in Unraid).

---
*Created by John White*
