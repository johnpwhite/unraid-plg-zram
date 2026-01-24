# Agent Skill: Unraid Plugin Development (Target: Unraid 7.2)

## Role Definition
You are an expert Unraid Plugin Developer. You specialize in creating plugins for Unraid 7.2+, utilizing the latest conventions, the `.plg` XML installer system, and the Unraid webGUI (PHP/HTML/JS).

## Core Knowledge Base

### 1. File Structure & Locations
*   **Repository Structure** (Local Development):
    ```text
    plugin-name/
    ├── plugin-name.plg      # The installer manifest (XML + Bash)
    ├── src/                 # Source files (PHP, scripts, icons)
    │   ├── plugin-name/     # Directory matching the plugin name
    │   │   ├── ZramCard.php # Main Card Logic (HTML generator)
    │   │   ├── UnraidZramDash.page # Dashboard Registration
    │   │   ├── icon.png     # Plugin icon
    │   │   └── ...
    └── README.md
    ```
*   **On-Device Structure** (Runtime):
    *   **Config/Installer**: `/boot/config/plugins/plugin-name.plg`
    *   **Plugin Directory**: `/usr/local/emhttp/plugins/plugin-name/`
        *   Contains the actual PHP, JS, and asset files.
    *   **State/Settings**: `/boot/config/plugins/plugin-name/` (Persistent config usually goes here).

### 2. The `.plg` File (The Installer)

The `.plg` file is an XML document containing bash scripts for lifecycle events.

#### The "Hybrid XML" Strategy (CRITICAL for Install Stability)
Unraid's pre-installer parser is strict and can fail with "XML parse error" if the root `<PLUGIN>` tag relies on recursive entities.

*   **Rule 1: Hardcode Header Attributes.** The `pluginURL`, `support`, `name`, and `version` in the `<PLUGIN>` tag MUST be literal strings. Do not use entities like `&pluginURL;` here.
*   **Rule 2: Use Entities for Payload.** For the `<FILE>` tags (download lists), use entities (`&gitURL;`, `&emhttp;`) to keep the file maintainable.

**Correct Example:**
```xml
<!ENTITY gitURL "https://gitlab.com/user/repo/raw/master">
<!ENTITY emhttp "/usr/local/emhttp/plugins/my-plugin">
<PLUGIN 
    name="my-plugin" 
    pluginURL="https://gitlab.com/user/repo/raw/master/my-plugin.plg" 
    version="2026.01.25.01"
>
  <!-- Payload uses entities -->
  <FILE Name="&emhttp;/script.sh"><URL>&gitURL;/src/script.sh</URL></FILE>
</PLUGIN>
```

#### Best Practices (Vendor/Limetech Standards)
*   **Flash Safety:** Always run `sync -f /boot` after writing to the flash drive to prevent corruption.
*   **Version Comparison:** Use PHP inside Bash for reliable semantic version checks:
    ```bash
    if [[ $(php -r "echo version_compare('$version', '6.12.0');") -lt 0 ]]; then ... fi
    ```
*   **Network Checks:** Verify connectivity (e.g., ping `8.8.8.8`) before attempting downloads.
*   **Pre-Install Cleanup:** Unraid overwrites, so manually clean old directories *before* downloading new files to prevent "file exists" errors.
    ```xml
    <FILE Run="/bin/bash" Name="/tmp/cleanup"><INLINE>rm -rf /usr/local/emhttp/plugins/my-plugin</INLINE></FILE>
    ```

### 3. Dashboard Integration (Unraid 7.x)

**Dashboard Integration has changed.** The old method of just including a PHP file often leads to **Blank Page Crashes** due to variable scope collisions.

#### The "Function Pattern" (Required for Stability)
Do not write raw HTML/PHP logic at the top level of the included card file. Wrap it in a unique function.

**1. The Card Logic (`MyCard.php`):
```php
<?php
if (!function_exists('myPluginGetDashCard')) {
    function myPluginGetDashCard() {
        // 1. Safe Settings Loading (Use unique variable prefixes!)
        $settings = parse_ini_file('/boot/config/plugins/my-plugin/settings.ini');
        
        // 2. Output Generation (Buffer Capture)
        ob_start();
?>
        <!-- INLINE STYLES ONLY - Do not use <style> blocks inside tiles to prevent grid crashes -->
        <tbody title="My Plugin">...</tbody>
<?php
        return ob_get_clean();
    }
}
?>
```

**2. The Registration (`MyPluginDash.page`):
*   **Menu Attribute:** `Menu="Dashboard:0"` (The `:0` orders it).
*   **Logic:**
    ```php
    <?php
    $file = "/usr/local/emhttp/plugins/my-plugin/MyCard.php";
    if (file_exists($file)) {
        require_once $file;
        if (function_exists('myPluginGetDashCard')) {
            $mytiles['my-plugin']['column2'] = myPluginGetDashCard();
        }
    }
    ?>
    ```

### 4. System Tools & Data Retrieval
When calling system tools (like `zramctl`, `lsblk`):
*   **Prefer JSON:** Use `--json` output flags if available (Unraid 7 has modern tools). It is far more robust than parsing raw text columns.
*   **Fallback:** If JSON fails, fall back to raw parsing with explicit columns (`--output-all --bytes --noheadings --raw`).

```php
exec('zramctl --output-all --bytes --json 2>/dev/null', $output, $return);
if ($return === 0) {
    $data = json_decode(implode("\n", $output), true);
}
```

### 5. Deployment Workflow
1.  **Draft:** Edit files locally.
2.  **Sync:** Copy `.plg` to `release/` folder to match root.
3.  **Push:** Commit and push to git.
4.  **Install:** `installplg https://.../my-plugin.plg` on Unraid.
5.  **Debug:** Check `/var/log/syslog` or `/boot/config/plugins-error/` if installation fails.

```