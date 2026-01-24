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

### 2. The `.plg` File (The Heart)
The `.plg` file is an XML document containing bash scripts for lifecycle events.

**CRITICAL: XML Parsing & Entities (The "Hybrid Strategy")**
Unraid's pre-installer parser (used during the initial `installplg` phase) can be fragile with recursive XML entities in the root `<PLUGIN>` tag.
*   **Rule:** **Hardcode** critical attributes (`pluginURL`, `support`, `name`, `version`) in the `<PLUGIN>` tag.
*   **Rule:** Use **Entities** (`&gitURL;`, `&emhttp;`) for the payload (`<FILE>` tags) to maintain maintainability.

**Bad (Risk of "XML Parse Error"):**
```xml
<!ENTITY gitURL "...">
<!ENTITY pluginURL "&gitURL;/my.plg"> <!-- Recursive dependency -->
<PLUGIN pluginURL="&pluginURL;">
```

**Good (Robust):**
```xml
<!ENTITY gitURL "...">
<PLUGIN pluginURL="https://.../my.plg"> <!-- Hardcoded/Literal -->
  <FILE Name="&gitURL;/script.sh"> ... </FILE>
</PLUGIN>
```

### 3. Best Practices from Vendor (Limetech)
Analysis of official system plugins reveals robust patterns:

*   **Flash Safety:** Always run `sync -f /boot` after writing to the flash drive to prevent corruption.
*   **Version Comparison:** Use PHP inside Bash for reliable semantic version checks:
    ```bash
    if [[ $(php -r "echo version_compare('$version', '6.12.0');") -lt 0 ]]; then ... fi
    ```
*   **Network Checks:** Verify connectivity before attempting downloads (ping check).
*   **Checksums:** Explicitly verify MD5 or SHA256 sums for critical binaries.
*   **Atomic Swaps:** Download to `/tmp` first, verify, then move to `/boot` or `/usr/local/emhttp`.

### 4. Unraid 7.2 Specifics & Dashboard Cards (The "Trailblazer" Method)

**Dashboard Integration has changed significantly.** The old method of just including a PHP file often leads to **Blank Page Crashes** due to variable scope collisions or buffer leaks.

#### The Safe "Function Pattern" (Required for Stability)
Do not write raw HTML/PHP logic at the top level of your included card file. Instead, wrap everything in a function.

**1. The Card Logic File (`MyCard.php`):**
```php
<?php
// Check for function existence to avoid redeclaration crashes
if (!function_exists('myPluginGetDashCard')) {
    function myPluginGetDashCard() {
        // 1. Safe Settings Loading (Use unique variable prefixes!)
        $my_config = parse_ini_file('/boot/config/plugins/my-plugin/settings.ini');
        
        // 2. Logic & Checks
        if ($my_config['enabled'] !== 'yes') return '';

        // 3. Output Generation (Buffer Capture)
        ob_start();
?>
        <!-- INLINE STYLES ONLY - Do not use <style> blocks inside tiles -->
        <tbody title="My Plugin">
            <tr>
                <td>
                    <div style="display: grid; ...">...</div>
                </td>
            </tr>
        </tbody>
<?php
        return ob_get_clean();
    }
}
?>
```

**2. The Dashboard Registration File (`MyPluginDash.page`):**
*   **Menu Attribute:** MUST be `Menu="Dashboard:0"` (The `:0` is crucial for ordering).
*   **Logic:** Require the file, check for the function, and assign the result.

### 5. Robust Installation & Cleanup (Preventing "File Already Exists")

Unraid does not clean up old files during an update. You MUST handle this manually.

**1. Pre-Install Cleanup:**
*   **Crucial:** Assign a `Name` attribute to the pre-install `<FILE>` tag so it executes before downloads.
```xml
<FILE Run="/bin/bash" Name="/tmp/cleanup">
<INLINE>
rm -rf /usr/local/emhttp/plugins/&name;
</INLINE>
</FILE>
```

**2. Uninstall Cleanup:**
```xml
<REMOVE Script="remove.sh">
#!/bin/bash
removepkg &name;-&version;
rm -rf /usr/local/emhttp/plugins/&name;
# Force WebGUI refresh
if [ -f /usr/local/sbin/update_plugin_cache ]; then
    /usr/local/sbin/update_plugin_cache
fi
/etc/rc.d/rc.nginx reload
</REMOVE>
```
