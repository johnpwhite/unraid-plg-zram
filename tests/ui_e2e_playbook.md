# ZRAM UI E2E Playbook

This document is executed by a Haiku subagent with Chrome DevTools MCP tools. It drives the Unraid dashboard/settings UI, runs DOM-level assertions, and captures screenshots for downstream visual review.

## Configuration passed by caller

The caller (Opus) provides:
- `SERVER`: the test server host (e.g. `192.168.1.4`)
- `OUT_DIR`: a writable dir on the dev machine where PNG screenshots are saved

## Prerequisites (handled by caller)

- SSH deploy has completed, L3 smoke has passed
- The test server is reachable on HTTP port 80
- Chrome DevTools MCP is available in the session

## Screenshot capture rule (MANDATORY)

Every `mcp__chrome-devtools__take_screenshot` call **MUST** include the `filePath` parameter with an absolute path. Without `filePath`, the screenshot is attached inline to the tool response and is **not saved to disk**.

**PATH TRAP on Windows** — Chrome DevTools MCP runs as a Windows process and resolves paths using Windows conventions. An MSYS-style `/tmp/zram-l4-shots/` passed to MCP is interpreted as `C:\tmp\zram-l4-shots\` — **not** the MSYS `/tmp` which is `C:\Users\<user>\AppData\Local\Temp`. To avoid the mismatch, always pass **Windows-style absolute paths** to MCP:

```
# CORRECT — unambiguous Windows path
mcp__chrome-devtools__take_screenshot {
  filePath: "C:/tmp/zram-l4-shots/01-dashboard-idle.png",
  format: "png"
}
```

Then verify the file via Bash using the **same Windows path** in quoted form so bash's path-translation doesn't rewrite it:
```
ls -la "C:/tmp/zram-l4-shots/01-dashboard-idle.png"
```

If the caller passes `OUT_DIR=/tmp/zram-l4-shots`, treat it as `C:/tmp/zram-l4-shots` for MCP calls and for verification. The caller is expected to create `C:\tmp\zram-l4-shots\` as the canonical location.

Include the file size in your step-result `notes`.

## Playbook

### Step 0 — Auth bootstrap

Unraid's WebGUI may redirect unauthenticated requests to `/login` or to the myunraid.net portal. Try navigation first; if redirected, fall back to cookie injection.

1. `mcp__chrome-devtools__new_page` url=`http://<SERVER>/Dashboard`
2. `mcp__chrome-devtools__take_snapshot`
3. If the snapshot contains text like "Sign in" or "myunraid.net", auth is blocking:
   - SSH to the server: `ssh root@<SERVER> "cat /var/local/emhttp/var.ini | grep -E 'NAME|PASS|hostname'"`
   - Report auth-blocked and skip remaining steps. Return `{auth_blocked: true, steps: []}`.

### Step 0.5 — Settings tab: icon row present AND actually rendered

**Unraid URL-mapping gotcha:** despite the `.page` directive reading `Menu="Utilities"`, the row actually renders on the **`/Settings`** page (top-nav "SETTINGS"), not `/Utilities`. Clicking the row navigates to `/Settings/<PageName>`. The `/Utilities` URL exists but renders an empty page and is NOT where plugin entries appear in modern Unraid. Don't let the `Menu=` value confuse you.

This step catches two regressions L3 can't:
- The row appears with broken-image placeholder (file served 404 or corrupted)
- The row disappears entirely (Menu directive changed, install hook failed, or Unraid remapped the menu category in a future version)

1. `mcp__chrome-devtools__navigate_page` url=`http://<SERVER>/Settings`
2. Wait up to 6s: `mcp__chrome-devtools__wait_for` text=`Unraid ZRAM`.
3. Assert via `evaluate_script`:
   ```
   () => {
     // Find the row for our plugin — the link whose href ends with /Settings/UnraidZramCard
     const link = [...document.querySelectorAll('a[href]')].find(
       a => a.getAttribute('href').endsWith('/Settings/UnraidZramCard')
     );
     if (!link) return JSON.stringify({ok: false, reason: 'Settings row missing — Menu directive may be wrong'});

     // Find our icon — an <img> whose src references our PNG
     const img = [...document.querySelectorAll('img')].find(
       i => i.src && i.src.includes('unraid-zram-card')
     );
     if (!img) return JSON.stringify({ok: false, reason: 'Icon <img> tag missing in Settings row'});

     // CRITICAL: verify the image actually loaded. <img> can exist with src set
     // but naturalWidth=0 when the resource 404s or is corrupt — Unraid still
     // renders a broken-image placeholder, and L3's file-on-disk check passes.
     const rendered = img.complete && img.naturalWidth > 0 && img.naturalHeight > 0;
     return JSON.stringify({
       ok: rendered,
       href: link.getAttribute('href'),
       imgSrc: img.src,
       complete: img.complete,
       naturalWidth: img.naturalWidth,
       naturalHeight: img.naturalHeight,
       rendered,
       reason: rendered ? '' : 'Icon <img> present but naturalWidth=0 — file failed to load or is corrupt'
     });
   }
   ```
4. Pass condition: `ok === true` AND `naturalWidth >= 16` (reject tiny/favicon-sized sprites that aren't our icon).
5. `mcp__chrome-devtools__take_screenshot` → `<OUT_DIR>/00-settings-tab.png` (Windows-style path).

### Step 1 — Dashboard idle state

1. Wait up to 8s for the ZRAM card to render: `mcp__chrome-devtools__wait_for` text=`ZRAM STATUS`.
2. `mcp__chrome-devtools__take_snapshot` and assert:
   - Text `ZRAM STATUS` present
   - Text `Uncompressed`, `Compressed`, `Ratio`, `Load`, `Swappiness` all present (the 5 stat cards)
   - Canvas element with id `zramChart` is in the snapshot
3. `mcp__chrome-devtools__take_screenshot` and save to `<OUT_DIR>/01-dashboard-idle.png`.

### Step 2 — Chart hover (3 positions)

**First: wait for chart data to populate.** After page load the chart fetches the history JSON; hovering before data arrives produces no tooltip (Chart.js has nothing to look up). Poll Chart.js's internal state until labels are populated:

```
mcp__chrome-devtools__evaluate_script:
  async () => {
    const start = Date.now();
    while (Date.now() - start < 10000) {
      const c = document.getElementById('zramChart');
      const inst = c && typeof Chart !== 'undefined' ? Chart.getChart(c) : null;
      const labels = inst?.data?.labels || [];
      if (labels.length > 3) {
        const r = c.getBoundingClientRect();
        return JSON.stringify({ ready: true, labels: labels.length, x: r.x, y: r.y, w: r.width, h: r.height });
      }
      await new Promise(res => setTimeout(res, 250));
    }
    return JSON.stringify({ ready: false, reason: 'chart labels not populated in 10s' });
  }
```

If `ready: false`, mark step 2 as `pass: false` with the reason and skip the three hovers. Continue to step 3 — don't abort the whole playbook.

For each `fraction` in `[0.1, 0.5, 0.9]`:
1. **Trigger the tooltip programmatically** via Chart.js's own API — dispatching synthetic `mousemove` events does not reliably activate Chart.js tooltips in every browser/version. Use `setActiveElements`:
   ```
   mcp__chrome-devtools__evaluate_script:
     async () => {
       const c = document.getElementById('zramChart');
       const inst = Chart.getChart(c);
       const r = c.getBoundingClientRect();
       const frac = <FRACTION>;
       const chartX = r.width * frac;
       const chartY = r.height / 2;
       const idx = Math.round(frac * (inst.data.labels.length - 1));
       inst.tooltip.setActiveElements(
         inst.data.datasets.map((_, di) => ({ datasetIndex: di, index: idx })),
         { x: chartX, y: chartY }
       );
       inst.update('none');
       await new Promise(res => setTimeout(res, 300));
       const tt = document.getElementById('zram-chart-tooltip');
       const tr = tt ? tt.getBoundingClientRect() : null;
       return JSON.stringify({
         present: !!tt,
         opacity: tt ? tt.style.opacity : null,
         left: tr?.left, right: tr?.right, top: tr?.top, bottom: tr?.bottom,
         canvasRight: r.right, canvasLeft: r.left,
         horizontalOverflow: tt && tr ? Math.max(0, tr.right - r.right, r.left - tr.left) : null,
         viewportH: window.innerHeight,
         text: tt ? tt.innerText : null
       });
     }
   ```
2. Assert: `present: true`, `opacity === "1"`, `bottom <= viewportH` AND `top >= 0` (vertical clip), `horizontalOverflow === 0` (horizontal clamp), and `text` contains all of `Uncompressed`, `Compressed`, `Load`.
3. `take_screenshot` → `<OUT_DIR>/02-hover-<fraction>.png` (e.g. `02-hover-0.1.png`). Use Windows-style `C:/tmp/...` path per the PATH TRAP rule.

### Step 3 — Settings cog navigation

1. Click the settings cog: `mcp__chrome-devtools__click` on the `a[href='/Dashboard/Settings/UnraidZramCard']` link (use `take_snapshot` first to find its uid).
2. Wait for URL change: `evaluate_script: return location.pathname + location.search`.
3. Assert the path is `/Utilities/UnraidZramCard` or `/Dashboard/Settings/UnraidZramCard` (Unraid may rewrite).
4. `take_screenshot` → `<OUT_DIR>/03-settings-page.png`.

### Step 4 — Settings form smoke

1. On the settings page, `take_snapshot` and assert presence of:
   - Input `name="refresh_interval"`
   - Input `name="collection_interval"`
   - Input `name="swappiness"`
   - Button with text `APPLY & SAVE`
2. Use `fill` or `evaluate_script` to set the refresh_interval input to a test value (e.g. `2500`).
3. Click the APPLY & SAVE button.
4. Wait up to 5s for the save-confirmation banner: `mcp__chrome-devtools__wait_for` text=`Settings Saved` (case-insensitive).
5. `take_screenshot` → `<OUT_DIR>/04-settings-saved.png`.
6. Assert no JS errors: `evaluate_script: return (window.__errors || [])`  (returns errors captured by a page-level error listener if one exists, else empty).

### Step 4a — Settings page static content (structural elements)

Navigate back to the settings page (`/Utilities/UnraidZramCard`) fresh so Save banner from step 4 is gone, then assert via `evaluate_script` that every structural element exists. This catches regressions where a form field or pane disappears without failing the save flow.

```
() => {
  const byName = n => document.querySelector(`[name="${n}"]`);
  const byId = i => document.getElementById(i);
  const checks = {
    refresh_interval:    !!byName('refresh_interval'),
    collection_interval: !!byName('collection_interval'),
    swappiness:          !!byName('swappiness'),
    enabled_select:      !!byName('enabled'),
    zram_size_mode:      !!byId('zram_size_mode'),
    zram_algo_select:    !!byId('zram_algo_select'),
    zram_percent_slider: !!byId('zram_percent_slider'),
    drive_list:          !!byId('drive-list'),
    console_log:         !!byId('console-log'),
    debug_log_view:      !!byId('debug-log-view'),
    tab_cmd:             !!byId('tab-cmd'),
    tab_debug:           !!byId('tab-debug-log'),
    save_button:         [...document.querySelectorAll('button')].some(
                            b => /apply.*save/i.test(b.textContent)
                          ),
  };
  const missing = Object.entries(checks).filter(([,v]) => !v).map(([k]) => k);
  return JSON.stringify({ok: missing.length === 0, missing, checks});
}
```

Pass condition: `missing` array is empty. Fail note should list which structural elements were missing.

`take_screenshot` → `<OUT_DIR>/04a-settings-content.png`.

### Step 4b — Tab switcher interaction

The settings page has `.zram-tab` elements that toggle between the Console log and the Debug Log views. A broken tab handler is silent — the page still "works" but one pane is stuck hidden. Test the switch programmatically:

```
// Click Debug Log tab
() => {
  const tab = document.getElementById('tab-debug-log');
  if (!tab) return JSON.stringify({ok: false, reason: 'tab-debug-log missing'});
  tab.click();
  // Allow any handler/animation to complete
  return new Promise(resolve => setTimeout(() => {
    const console = document.getElementById('console-log');
    const debug = document.getElementById('debug-log-view');
    const consoleHidden = !console || window.getComputedStyle(console).display === 'none';
    const debugVisible  = !!debug && window.getComputedStyle(debug).display !== 'none';
    resolve(JSON.stringify({
      ok: consoleHidden && debugVisible,
      consoleHidden, debugVisible
    }));
  }, 300));
}
```

Pass: `ok === true`. Then `take_screenshot` → `<OUT_DIR>/04b-settings-debug-tab.png`.

Repeat the reverse to confirm the Console tab also activates:

```
() => {
  const tab = document.getElementById('tab-cmd');
  if (!tab) return JSON.stringify({ok: false, reason: 'tab-cmd missing'});
  tab.click();
  return new Promise(resolve => setTimeout(() => {
    const console = document.getElementById('console-log');
    const debug = document.getElementById('debug-log-view');
    const consoleVisible = !!console && window.getComputedStyle(console).display !== 'none';
    const debugHidden  = !debug || window.getComputedStyle(debug).display === 'none';
    resolve(JSON.stringify({
      ok: consoleVisible && debugHidden,
      consoleVisible, debugHidden
    }));
  }, 300));
}
```

Pass: `ok === true`. Then `take_screenshot` → `<OUT_DIR>/04c-settings-console-tab.png`.

### Step 5 — Close

`mcp__chrome-devtools__close_page`.

## Output format

The subagent MUST return a single JSON object as its final message:

```json
{
  "auth_blocked": false,
  "screenshots_dir": "<OUT_DIR>",
  "steps": [
    {"n": "0.5", "name": "settings-tab-icon",      "pass": true, "notes": ""},
    {"n": 1,     "name": "dashboard-idle",         "pass": true, "notes": ""},
    {"n": 2,     "name": "chart-hover-0.1",        "pass": true, "notes": ""},
    {"n": 2,     "name": "chart-hover-0.5",        "pass": true, "notes": ""},
    {"n": 2,     "name": "chart-hover-0.9",        "pass": true, "notes": ""},
    {"n": 3,     "name": "settings-cog-nav",       "pass": true, "notes": ""},
    {"n": 4,     "name": "settings-form-save",     "pass": true, "notes": ""},
    {"n": "4a",  "name": "settings-static-content","pass": true, "notes": ""},
    {"n": "4b",  "name": "tab-switcher-debug",     "pass": true, "notes": ""},
    {"n": "4b",  "name": "tab-switcher-console",   "pass": true, "notes": ""}
  ],
  "overall": "pass"
}
```

If any assertion fails, set `pass: false` on that step, fill `notes` with a short description (≤100 chars), and set `overall: "fail"`. Continue running remaining steps to collect maximum diagnostic info.

If `auth_blocked: true`, return early with `steps: []` and `overall: "skipped"`.

## Failure modes Opus handles (not this playbook)

- Gemini visual review comes next (separate stage, reviews the PNGs you captured).
- Rollback decisions based on factory-vs-storefront policy (not this playbook's concern).
- Screenshot cleanup (Opus decides whether to archive or discard).
