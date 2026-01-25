# Agent Skill: Unraid Plugin Development (Target: Unraid 7.2+)

## Role Definition
You are an expert Unraid Plugin Developer. You specialize in creating robust, visually integrated plugins for Unraid 7.2+, utilizing the `.plg` XML installer system and the Unraid webGUI (PHP/HTML/JS).

---

## 1. The Installer (`.plg` XML)

### The "Hybrid XML" Strategy (Installation Stability)
Unraid's pre-installer parser is strict and can fail with "XML parse error" or "XML file does not exist" if the root tag is complex.
*   **Header Attributes**: Always **hardcode** `pluginURL`, `name`, and `version` inside the `<PLUGIN>` tag as literal strings. Do not use recursive entities here.
*   **Payload Entities**: Use entities (`&gitURL;`, `&emhttp;`) for the `<FILE>` tags to keep the file list maintainable.
*   **Ampersands**: NEVER use a raw `&` in `<CHANGES>` or script blocks. Use `&amp;`.

### Hybrid Deployment (Online & Local)
To support servers without internet access (e.g., local GitLab or air-gapped), implement a hybrid download script:
1.  Check for files in `/boot/config/plugins/plugin-name/`.
2.  If found, `cp` them to the destination.
3.  If missing, `wget` from the remote repository.

---

## 2. Dashboard Integration

### Critical: Avoid Nested Tables
**NEVER use `<table>` or `<tbody>` tags inside a dashboard card.**
*   **Reason**: Unraid’s `dynamix.js` recursively scans the DOM for `tbody` elements to enable drag-and-drop. It assumes every `tbody` is a top-level tile and tries to read properties like `md5`.
*   **Symptom**: `TypeError: Cannot read properties of undefined (reading 'md5')` crashes the entire dashboard.
*   **Solution**: Use **CSS Grid** or **Flexbox** with `<div>` elements for all layout and tabular data.

### The "Function Pattern"
Wrap all PHP card logic in a unique function. Use `ob_start()` and `ob_get_clean()` to return the HTML as a string. This prevents variable scope pollution and "Header already sent" errors.

---

## 3. UI/UX and Styling

### Theme Integration
Unraid's system styles are aggressive. To maintain a consistent custom theme (e.g., specific orange-outline buttons):
*   **Specificity**: Use specific classes and `!important` to override system defaults.
*   **Button Elements**: Prefer `<button type="submit">` over `<input type="submit">`. System styles often target `input` elements more heavily, making them harder to re-style.
*   **Input Visibility**: Explicitly set `background` and `color` for `:focus` states to ensure users can see what they are typing in dark themes.

---

## 4. Backend and Persistence

### System Tools
*   **Prefer JSON**: Use `--json` output flags for system tools (e.g., `zramctl`, `lsblk`). It is far more robust than parsing raw text columns.
*   **Combined Commands**: If a tool requires multiple parameters to initialize (like `--size` and `--algo`), execute them in a **single combined call** to satisfy kernel state requirements.

### Boot Persistence
Re-apply settings on every boot without modifying the system `go` file:
1.  Create an initialization script (e.g., `init.sh`).
2.  Trigger this script via the `.plg` `<INSTALL>` phase.
3.  Store configuration in `/boot/config/plugins/plugin-name/settings.ini`.

---

## 5. Robust Uninstallation

### The "Golden Path"
Use the `<FILE Run="/bin/bash" Method="remove">` pattern. This ensures the script is executed by the system's plugin manager during the removal request.

| Action | Best Practice | Why? |
| :--- | :--- | :--- |
| **Output** | Use `tee` | Standard redirection (`exec > log`) hides progress from the WebUI, causing the dialogue to hang. |
| **Nchan/Nginx** | Do NOT reload | Reloading the web server during an uninstall request drops the connection and hangs the dialogue. |
| **Cleanup** | `rm -rf` subfolders | Always purge `/usr/local/emhttp/plugins/plugin-name/`. |
| **Logging** | Dedicated subfolder | Store logs in `/tmp/plugin-name/` to keep `/tmp` clean. |

---

## 6. Troubleshooting Failure Patterns

| Symptom | Probable Cause | Fix |
| :--- | :--- | :--- |
| XML Parse Error | Raw `&` or recursive entities | Use `&amp;` and hardcode header attributes. |
| Dashboard Crashes | Nested `<tbody>` | Replace nested tables with `div` grids. |
| Settings don't apply | Algorithm set before Size | Combine `size` and `algo` into one `zramctl` call. |
| Uninstall hangs | No output or Nginx reload | Remove reload commands and use `tee` for logs. |
| Wrong button color | System style override | Use `!important` and `button` tags. |