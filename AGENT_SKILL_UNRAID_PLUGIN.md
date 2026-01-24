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
    │   │   ├── content.php  # Main UI logic
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
**Key Elements:**
*   `<FILE Name="...">`: Defines files to be downloaded or created.
*   `<INSTALL>`: Script running during installation.
*   `<REMOVE>`: Script running during uninstallation.
*   `<MD5>`: Checksum for validity (optional but good).

**Template:**
```xml
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "my-plugin">
<!ENTITY author    "MyName">
<!ENTITY version   "2024.01.24">
<!ENTITY launch    "Settings/MyPlugin">
<!ENTITY pluginURL "https://raw.githubusercontent.com/user/repo/master/my-plugin.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" launch="&launch;" pluginURL="&pluginURL;" min="6.12.0" support="link-to-support">

<CHANGES>
## 2024.01.24
- Initial Release
</CHANGES>

<!-- 1. Define Source File (e.g., a tarball or individual files) -->
<FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz">
<URL>https://github.com/user/repo/releases/download/&version;/&name;-&version;.txz</URL>
</FILE>

<!-- 2. Install Script -->
<INSTALL>
<!-- Unpack sources -->
installpkg /boot/config/plugins/&name;/&name;-&version;.txz

<!-- Post-install logic -->
echo "Plugin &name; installed."
</INSTALL>

<!-- 3. Uninstall Script -->
<REMOVE>
removepkg &name;-&version;
rm -rf /usr/local/emhttp/plugins/&name;
# Force WebGUI refresh to remove ghost pages
/etc/rc.d/rc.nginx reload
echo "Plugin &name; removed."
</REMOVE>

</PLUGIN>
```

### 3. Unraid 7.2 Specifics
*   **New API**: Unraid 7.2 introduces a more robust, "dev-ready" API.
*   **Dashboard Cards**:
    *   Dashboard integration is shifting. Traditionally done via `.page` files or modifying `dashboard.php`.
    *   In 7.2, look for modular dashboard hooks or API endpoints to register a tile/card.
    *   *Note: Without full API docs, standard practice is to inspect `/usr/local/emhttp/plugins/dynamix.vm.manager` or similar stock plugins to mimic registration.*

### 4. UI Development (PHP/HTML)
*   **Base URL**: Plugins live under the emhttp server.
*   **Page Files**: A `.page` file in your plugin dir registers a menu item.
    *   Example: `MyPlugin.page`
    *   Content:
        ```ini
        Menu="Utilities"
        Title="My Plugin"
        Icon="wrench"
        ---
        <?php
        // PHP Logic here
        ?>
        ```
*   **Dashboard Widget**: Often requires a specific naming convention or a registration script if not using the legacy method.
*   **DOM Structure Changes (Unraid 7.x)**:
    *   Avoid direct DOM manipulation of core elements as structure has changed.
    *   **Buttons**: Do not place buttons directly in `<dd>` tags; wrap them in `<span>` to prevent full-width stretching.
    *   **Tables**: Use `<div class="TableContainer">` for responsiveness.
    *   **Footer**: Append content to `.footer-right` instead of hardcoded IDs.

### 5. ZRAM Specifics (Context of Current Task)
*   **Goal**: Create a ZRAM status card.
*   **Backend**: Needs to run `zramctl` or check `/proc/swaps` / `/sys/block/zram*`.
*   **Frontend**: Display stats (Compression ratio, Used vs Total).

### 6. Migration to Responsive GUI (Unraid 7.x)
Unraid 7.2 introduces a refactored webGUI with responsive CSS. This impacts dashboard tiles and general plugin layout.

#### Important: DOM Structure Changes
*   **Title Bar**: Adding elements (buttons, sliders) to title bars is no longer supported.
*   **Dashboard Tiles**: The `tbody` structure for tiles has changed to use flexbox.

#### Dashboard Tile Structure (New vs Old)
**New (Flexbox):**
```html
<tbody>
  <tr>
    <td>
      <span class='tile-header'>
        <span class='tile-header-left'>
          <i class='icon-performance f32'></i>
          <div class='section'>
            <h3 class='tile-header-main'>Title</h3>
            <span>Subtitle</span>
          </div>
        </span>
        <span class='tile-header-right'>
          <span class='tile-ctrl'> <!-- Primary Controls --> </span>
          <span class='tile-header-right-controls'> <!-- Secondary Links --> </span>
        </span>
      </span>
    </td>
  </tr>
  <tr>
    <td> <!-- Content --> </td>
  </tr>
</tbody>
```

**Conditional Rendering (Backwards Compatibility):**
```php
<? $isResponsiveWebgui = version_compare(parse_ini_file('/etc/unraid-version')['version'],'7.2.0-beta','>='); ?>
...
<div class='section'>
  <? if ($isResponsiveWebgui): ?>
    <h3 class='tile-header-main'>Title</h3>
    <span>Subtitle</span>
  <? else: ?>
    Title<br><span>Subtitle</span><br>
  <? endif; ?>
</div>
...
```

#### CSS & Grid System
*   **Classes**: `.ctrl` -> `.tile-ctrl`, `.tile-header` (flex row), `.tile-header-left/right`.
*   **Grid**: Dashboard uses CSS Grid.
    *   Mobile: 1 column
    *   Tablet (768px+): 2 columns
    *   Desktop (1600px+): 3 columns
*   **Wide Tables**: Wrap in `<div class="TableContainer">` for horizontal scrolling.

#### Opting Out (Last Resort)
If responsive layout breaks your plugin completely:
*   Add `ResponsiveLayout="false"` and `Markdown="false"` to your `.page` file header.
*   Or wrap specific sections in `<div class="content--non-responsive">`.

### 7. Plugin Generation Tools
**UnRAID-Plugin-Generator** (by bobbintb) allows defining plugins in TOML and compiling to XML `.plg`.
*   **Config**: `config.toml` defines metadata, files, and scripts.
*   **Features**: Auto-versioning, MD5 hashing, entity substitution.
*   **Workflow**: Edit TOML -> Run script -> Output `.plg`.

### 8. Best Practices & Advanced Concepts

#### Plugin vs. Docker: The "Golden Rule"
*   **Use Docker** for Applications (Plex, Sonarr, Web Servers). It provides isolation, dependencies, and safety.
*   **Use Plugins** *only* for:
    *   OS-level integration (Drivers, ZFS, Kernel modules).
    *   Modifying the Unraid WebGUI itself (Themes, Dashboard Cards).
    *   System tools that require direct host access (UPS management, System stats).

#### Naming Conventions (Strict)
*   **Consistency is Key**: The `<PLUGIN name="...">` attribute, the `.plg` filename, and your directory in `/usr/local/emhttp/plugins/` **must** match.
*   **No Spaces**: Never use spaces or special characters in the plugin name. Use `kebab-case` or `CamelCase` (e.g., `unraid-zram-card` or `UnraidZramCard`).

#### Resilient Installation (The "Cache" Pattern)
Plugins should download assets to the USB drive (persistent) and then install to RAM (runtime). This prevents re-downloading on every boot.
**Example `.plg` logic:**
```xml
<!-- 1. Define locations -->
<!ENTITY name "my-plugin">
<!ENTITY boot "/boot/config/plugins/&name;"> <!-- USB (Persistent) -->
<!ENTITY emhttp "/usr/local/emhttp/plugins/&name;"> <!-- RAM (Runtime) -->

<!-- 2. Download to USB (if not exists or update needed) -->
<FILE Name="&boot;/package.txz">
<URL>https://github.com/.../package.txz</URL>
</FILE>

<!-- 3. Install to RAM from USB -->
<FILE Name="&emhttp;/package.txz" Run="upgradepkg --install-new">
<LOCAL>&boot;/package.txz</LOCAL>
</FILE>
```

#### Event Hooks & Lifecycle
*   **Source of Truth**: The file `/usr/local/sbin/emhttp_event` on Unraid lists all available system events (e.g., `starting`, `started`, `stopping`, `disks_mounted`).
*   **Implementation**: To hook an event, plugins typically install a script into a specific event directory or register via a command. Inspecting standard plugins like `dynamix` is the best documentation here.
*   **Install Order**: Plugins load in **ASCII alphabetical order** of their `.plg` filenames at boot. If `plugin-B` depends on `plugin-A`, rename them or use `00-plugin-A.plg` to ensure correct loading.

#### CLI Tools
*   `plugin --help`: Run this in the Unraid terminal to see how the plugin manager handles installation/updates (useful for debugging your `.plg` script logic).

## Development Workflow
1.  **Draft**: Edit files locally.
2.  **Package**: Create the `.plg` (and optional `.txz` archive of sources).
3.  **Deploy**:
    *   *Option A (Dev)*: SCP files directly to `/usr/local/emhttp/plugins/unraid-zram-card/` on the server for instant UI updates.
    *   *Option B (Install)*: Copy `.plg` to `/boot/config/plugins/` and reboot or run `installplg /boot/config/plugins/my.plg`.
4.  **Debug**: Check `/var/log/syslog` and Web Developer Console.

## Conventions
*   **Naming (File/Dir)**: Kebab-case is common for directories/repos (`unraid-zram-card`).
*   **Naming (Display)**: The "Installed Plugins" list uses the **filename** of the `.plg` file. Name your file `UnraidZramCard.plg` (CamelCase) if you want a friendly display name.
*   **Icons**: Standard FontAwesome or local PNGs.
*   **Safety**: Read-only operations for the dashboard card.
*   **Versioning**: Strict `YYYY.MM.DD` format. Use `.1`, `.2` for intraday releases (e.g., `2024.01.24.1`). **NO letters** (e.g., `2024.01.24a` is invalid).
*   **Metadata**:
    *   **Description**: Include a `README.md` in your plugin directory and reference it in the `.plg` file. Use HTML `<br>` tags for line breaks in lists.
    *   **Support**: The `support` attribute in the `<PLUGIN>` tag must be a valid URL (e.g., GitHub Issues or Forum Thread).

## Troubleshooting & Common Pitfalls

### 1. "Ghost" UI Elements after Uninstall
*   **Symptom**: Settings pages or dashboard cards remain visible even after removing the plugin files.
*   **Cause**: Unraid's WebGUI and Nginx cache the menu structure and page mappings.
*   **Fix**: Your uninstall script (`<REMOVE>`) must explicitly reload Nginx.
    ```bash
    removepkg &name;-&version;
    rm -rf &emhttp;
    /etc/rc.d/rc.nginx reload  # Forces WebGUI refresh
    ```

### 2. Dashboard Card Sizing (Unraid 7.x)
*   **Symptom**: Custom cards look "huge" or break the grid layout.
*   **Cause**: Unraid 7.2 uses a CSS Grid/Flexbox layout. Fixed heights or unconstrained widths break this.
*   **Fix**:
    *   Use CSS constraints: `max-width: 100%; overflow: hidden;`.
    *   Avoid large fixed heights for Charts/Canvas (keep < 150px).
    *   Ensure your `canvas` has `display: block` to avoid inline whitespace issues.

### 3. Settings Not Saving
*   **Symptom**: Checkbox or Select options revert after clicking Save.
*   **Cause**: PHP `isset($_POST['val'])` is always true for `<select>` elements.
*   **Fix**: Check the *value*: `($_POST['enabled'] === 'yes')`.
