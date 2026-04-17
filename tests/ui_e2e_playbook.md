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

### Step 1 — Dashboard idle state

1. Wait up to 8s for the ZRAM card to render: `mcp__chrome-devtools__wait_for` text=`ZRAM STATUS`.
2. `mcp__chrome-devtools__take_snapshot` and assert:
   - Text `ZRAM STATUS` present
   - Text `Uncompressed`, `Compressed`, `Ratio`, `Load`, `Swappiness` all present (the 5 stat cards)
   - Canvas element with id `zramChart` is in the snapshot
3. `mcp__chrome-devtools__take_screenshot` and save to `<OUT_DIR>/01-dashboard-idle.png`.

### Step 2 — Chart hover (3 positions)

Find the chart canvas bounding box:
```
mcp__chrome-devtools__evaluate_script:
  const c = document.getElementById('zramChart');
  const r = c.getBoundingClientRect();
  return JSON.stringify({x: r.x, y: r.y, w: r.width, h: r.height});
```

For each `fraction` in `[0.1, 0.5, 0.9]`:
1. Move mouse to `(x + w*fraction, y + h/2)` using `mcp__chrome-devtools__hover` on the canvas at that offset, OR use `evaluate_script` to dispatch a `mousemove` event on the canvas if `hover` doesn't accept coords.
2. Wait 300ms for the tooltip to render.
3. `evaluate_script`:
   ```
   const tt = document.getElementById('zram-chart-tooltip');
   if (!tt) return JSON.stringify({present: false});
   const rect = tt.getBoundingClientRect();
   return JSON.stringify({
     present: true,
     opacity: tt.style.opacity,
     bottomInViewport: rect.bottom <= window.innerHeight,
     topInViewport: rect.top >= 0,
     text: tt.innerText
   });
   ```
4. Assert: `present: true`, `opacity === "1"`, both `bottomInViewport` and `topInViewport` are true, text contains all of `Uncompressed`, `Compressed`, `Load`.
5. `take_screenshot` → `<OUT_DIR>/02-hover-<fraction>.png` (e.g. `02-hover-0.1.png`).

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

### Step 5 — Close

`mcp__chrome-devtools__close_page`.

## Output format

The subagent MUST return a single JSON object as its final message:

```json
{
  "auth_blocked": false,
  "screenshots_dir": "<OUT_DIR>",
  "steps": [
    {"n": 1, "name": "dashboard-idle",      "pass": true,  "notes": ""},
    {"n": 2, "name": "chart-hover-0.1",     "pass": true,  "notes": ""},
    {"n": 2, "name": "chart-hover-0.5",     "pass": true,  "notes": ""},
    {"n": 2, "name": "chart-hover-0.9",     "pass": true,  "notes": ""},
    {"n": 3, "name": "settings-cog-nav",    "pass": true,  "notes": ""},
    {"n": 4, "name": "settings-form-save",  "pass": true,  "notes": ""}
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
