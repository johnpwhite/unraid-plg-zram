# Unraid ZRAM Status & Management

A modern, high-contrast dashboard card and management utility for ZRAM swap devices on Unraid 7.2+.

![Dashboard Preview](https://raw.githubusercontent.com/johnpwhite/unraid-plugin-zram/main/docs/images/dashboard.png)

## Features

*   **Real-time Dashboard Card:** Monitor RAM saved, compression ratio, and actual ZRAM usage with a live-updating history chart.
*   **On-the-fly Management:** Create and remove ZRAM swap devices directly from the Settings page.

![Settings Preview](https://raw.githubusercontent.com/johnpwhite/unraid-plugin-zram/main/docs/images/settings.png)

*   **Compression Choice:** Support for all kernel-supported algorithms (`zstd`, `lz4`, `lzo`, etc.).
*   **Boot Persistence:** Automatically re-applies your ZRAM configuration on every boot.
*   **Safe Evacuation:** Built-in guards to prevent OOM crashes when removing ZRAM devices.
*   **Air-Gap Support:** "Hybrid Installer" logic supports servers without internet access via manual file placement.

## Installation

### Method 1: Community Applications (Recommended)
Search for **"Unraid ZRAM"** in the Apps tab.

### Method 2: Manual URL
Copy the link below and paste it into the **Plugins > Install Manager** tab:
`https://github.com/johnpwhite/unraid-plugin-zram/raw/main/unraid-zram-card.plg`

## Usage

1.  Navigate to **Settings > Unraid ZRAM**.
2.  Choose your desired size and compression algorithm.
3.  Click **Create Device**.
4.  View your statistics on the main **Dashboard**.

## Requirements
*   Unraid 6.12.1 or newer (Optimized for Unraid 7.2+).
*   ZRAM kernel module (Standard in Unraid).

---
*Created by John White*
