# Trailblazer Findings: Unraid 7.2 Plugin Development (ZRAM Card)

**Date:** Jan 25, 2026
**Context:** Migrating/Creating a Dashboard Card plugin for Unraid 7.2.
**Status:** Dashboard Card Registration and Installation stability achieved.

---

## 🏆 The "Golden Path" (What Works)

### 1. The "Hybrid XML" Strategy
*   **Issue:** Unraid's pre-installer often throws "XML parse error" or "XML file does not exist" if the root `<PLUGIN>` tag relies on recursive entities (e.g., `pluginURL="&pluginURL;"` where `&pluginURL;` depends on `&gitURL;`).
*   **Solution:**
    *   **Hardcode** the attributes in the `<PLUGIN>` tag (`name`, `author`, `version`, `pluginURL`).
    *   **Use Entities** for the file payload (`<FILE URL="&gitURL;/...">`).
    *   This satisfies the strict bootstrapper parser while keeping the file list maintainable.

### 2. Dashboard Registration: The "Function Pattern"
The most stable way to register a dashboard card in Unraid 7.x without crashing the page.
*   **Logic:** Wrap card logic in a unique function (`getZramDashboardCard`).
*   **Variable Scope:** Prefix all variables (`$zram_settings`).
*   **Return Type:** The function MUST return a string (HTML).

### 3. Styling: Inline Only
*   **Critical Finding:** Unraid 7's dashboard renderer **crashes or renders blank** if it encounters `<style>...</style>` blocks inside a dashboard tile.
*   **Solution:** Use **inline styles** (`style="display: grid; ..."`) for all elements within the PHP output.

### 4. Robust Installation: The "Pre-Install Nuke"
*   **Problem:** Unraid does not clear the old plugin directory during an update (overwrite).
*   **Solution:** Use a `<FILE Run="/bin/bash" Name="/tmp/cleanup">` script to `rm -rf` the plugin directory *before* new files are downloaded.

---

## ❌ Failure Log (What NOT to do)

### 1. Recursive XML in Header
*   **Attempt:** `<!ENTITY pluginURL "&gitURL;/file.plg">` -> `<PLUGIN pluginURL="&pluginURL;">`
*   **Result:** Installation failure (XML Parse Error).
*   **Fix:** Hardcode the full URL in the `<PLUGIN>` tag.

### 2. The "Closure" Pattern
*   **Attempt:** `(function(){ ... })();` inside `ZramCard.php`.
*   **Result:** Blank Dashboard / Crash.

### 3. Internal Style Blocks
*   **Attempt:** `<style>.my-class { ... }</style>` inside the card HTML.
*   **Result:** CSS Grid layout breaks, dashboard may fail to render.

---

## 🛠 Next Steps (Re-enabling Features)

1.  **Chart.js Integration:**
    *   Add `chart.js` back to the payload.
    *   Load it via a `<script>` tag.
    *   **Crucial:** Do not assume the DOM is ready immediately. Use a mechanism to initialize the chart after the element exists (potentially `defer` or a check loop).