# Unraid ZRAM Settings & Usage Guide

This guide explains how to configure and manage your ZRAM swap devices using the Unraid ZRAM plugin.

## 1. Understanding ZRAM
ZRAM is a tool that creates a "compressed swap" area in your RAM. Instead of your computer slowing down when it runs out of memory, it compresses older data into a smaller space within your RAM. This is much faster than using a hard drive or SSD for swap.

## 2. Plugin Settings Explained

### Enable Dashboard
- **Options**: Yes / No
- **What it does**: Controls whether the ZRAM status card appears on your main Unraid Dashboard.

### Refresh Interval (ms)
- **Valid Values**: 1000 or higher (Default: 3000)
- **What it does**: How often the Dashboard card updates its live statistics. 1000ms = 1 second.

### Collection Interval (sec)
- **Valid Values**: 1 or higher (Default: 3)
- **What it does**: How often the background service records data for the history chart. Even if you close your browser, this service keeps track of performance.

### Swappiness (0-100)
- **Valid Range**: 0 to 100 (Default: 100)
- **What it does**: Controls how "aggressive" the system is about using ZRAM. For ZRAM, a value of **100** is recommended to ensure the system utilizes the compressed memory effectively.

### Debug Mode
- **What it does**: When enabled, the plugin records detailed technical logs. Only turn this on if you are troubleshooting an issue.

### Show Command Console
- **What it does**: Displays a real-time terminal window at the bottom of the settings page so you can see exactly what commands the plugin is running.

---

## 3. How to Manage ZRAM Devices

### Adding a New Device
1. Go to **Settings > Unraid ZRAM**.
2. In the **ZRAM Management** section, enter a **Size** (e.g., `4G` or `512M`).
3. Choose a **Compression Algorithm** (e.g., `zstd` is highly recommended for best balance).
4. Click **Create Device**.

### Changing an Existing Device
Because ZRAM devices are part of the system's active memory, they cannot be resized while in use.
1. Click the **X** icon next to the device to remove it.
2. Follow the "Adding a New Device" steps above to create a new one with your desired settings.

### Adjusting Priority
1. Click the **Pencil icon** next to a device.
2. Enter a new **Priority** number (Higher numbers are used by the system first).
3. Click **Apply Changes**.
   *Note: The system will temporarily move data out of that ZRAM device to apply the change.*

---

## 4. Diagnostics & Logs

### Command History Tab
This shows a simplified log of every action you've taken (Creating devices, changing settings, etc.). It persists on the server so you can see your history even after a page refresh.

### System Debug Log Tab
This shows the raw technical output from the background services. Use the **Refresh Log** button to see the latest entries or **Clear Debug Log** to start fresh.
