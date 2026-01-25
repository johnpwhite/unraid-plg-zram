# Agent Skill: Unraid Plugin Development (Target: Unraid 7.2+)

## Role Definition
You are an expert Unraid Plugin Developer. You specialize in creating robust, visually integrated plugins for Unraid 7.2+, utilizing the `.plg` XML installer system and the Unraid webGUI (PHP/HTML/JS).

---

## 1. The Installer (`.plg` XML)

### The "Hybrid XML" Strategy (Installation Stability)
Unraid's pre-installer parser is strict and can fail with "XML parse error" if the root tag is complex.
*   **Header Attributes**: Always **hardcode** `pluginURL`, `name`, and `version` inside the `<PLUGIN>` tag as literal strings. Do not use recursive entities here.
*   **Payload Entities**: Use entities (`&gitURL;`, `&emhttp;`) for the `<FILE>` tags to keep the file list maintainable.
*   **Ampersands**: NEVER use a raw `&` in `<CHANGES>` or script blocks. Use `&amp;`.

### Air-Gap & Manual Deployment Support
Unraid 7 has **removed the `installplg` command**. Manual installation is now done by browsing to the `.plg` file via the WebUI. To support air-gapped servers:
*   **Multi-Path Detection**: Your installer script should check for local source files in multiple locations (e.g., root of the flash folder, `js/` subfolders, or repository structure) to be resilient to how a user might have manually copied the files.
*   **Unified PLG**: Use a "Hybrid Installer" block that checks for local files first and only attempts a `wget` from GitLab if they are missing. This eliminates the need for separate "local" and "online" `.plg` files.

---

## 2. Boot Persistence & Startup Scripts

### The "Re-install" Paradigm
Unraid runs in RAM. Every reboot wipes the `/usr/local/emhttp/plugins/` directory. The `.plg` file is re-executed by the system on every boot to "re-install" the plugin.

### Robust Boot Initialization
To re-apply settings (like ZRAM swap) on every reboot without modifying the system `go` file:
1.  **Binary Path Detection**: NEVER hardcode paths like `/usr/bin/zramctl`. Use `$(which zramctl || echo "/sbin/zramctl")` to dynamically locate binaries, as `$PATH` may be incomplete during early boot.
2.  **Explicit Execution**: Trigger your initialization script (e.g., `zram_init.sh`) via a `<FILE Run="/bin/bash">` block inside the `.plg`. This ensures it runs immediately after the files are copied back into RAM.
3.  **Boot Logging**: Redirect startup script output to `/tmp/plugin-name/boot_init.log`. This is critical for diagnosing why a plugin failed to restore state during the boot sequence.

---

## 3. Dashboard & UI/UX

### Critical: Avoid Nested Tables
**NEVER use `<table>` or `<tbody>` tags inside a dashboard card.**
*   **Reason**: Unraid’s `dynamix.js` recursively scans the DOM for `tbody` elements for drag-and-drop logic. A nested table triggers a JS crash: `TypeError: Cannot read properties of undefined (reading 'md5')`.
*   **Solution**: Use **CSS Grid** or **Flexbox** with `<div>` elements for all tabular data.

### Visual Precision (Chart.js)
*   **Decimal Precision**: Ensure labels have at least 1 decimal place (e.g., `1.3 GB`) for values > 1MB to prevent data points from appearing "misaligned" due to rounding.
*   **Grace/Headroom**: Add a `grace: '10%'` factor to the Y-axis so data lines never touch the top edge of the card.

---

## 4. Robust Uninstallation

### The "Golden Path"
Use the `<FILE Run="/bin/bash" Method="remove">` pattern. 

| Action | Best Practice | Why? |
| :--- | :--- | :--- |
| **Output** | Plain Text | Silent scripts or complex redirections can cause the Unraid 7 uninstall dialogue to hang. Talkative scripts allow the UI to track progress. |
| **Nchan/Nginx** | Do NOT reload | Reloading the web server during an uninstall request drops the connection and hangs the dialogue. |
| **Cleanup** | `rm -rf` Flash | Explicitly purge the configuration folder from `/boot/config/plugins/plugin-name/` for a truly clean removal. |

---

## 5. Troubleshooting Failure Patterns

| Symptom | Probable Cause | Fix |
| :--- | :--- | :--- |
| XML Parse Error | Raw `&` or recursive entities | Use `&amp;` and hardcode header attributes. |
| Dashboard Crashes | Nested `<tbody>` | Replace nested tables with `div` grids. |
| Settings don't apply | Algorithm set before Size | Combine `size` and `algo` into one `zramctl` call. |
| Boot script fails | Hardcoded binary paths | Use `which` to find binaries dynamically. |
| Uninstall hangs | No output or Nginx reload | Remove reload commands and ensure script echos progress. |