# Feature: L4 visual coverage — every page, every feature surface, every served asset

## Status
Approved

## Problem

The icon-perm regression on 2026-05-14 hit production-shape behaviour despite the smoke suite reporting 9/9 green. Smoke assertion #7 said:

```
[7/9] Icon file valid (175801 bytes, PNG magic ok)
```

— and it was, on disk. But a real user's browser asks nginx for `/plugins/unraid-zram-card/unraid-zram-card.png`, and nginx (as `nobody`) couldn't read a 0600 root-only file. The two surfaces — *file exists on disk* and *nginx serves the bytes to a browser* — diverged on a permission bit, and the smoke harness only touched one of them.

Same shape: smoke checks that `UnraidZramDash.page` exists + has content, but doesn't observe whether Chart.js (a 0600 JS asset served via nginx) actually loads in the browser; the dashboard chart would have been silently broken until someone opened the page. The dashboard JS is just the icon's larger cousin.

The pipeline already has an L4 hook — the deploy wrapper reports `l4: skipped (no tests/run-l4.sh in plugin)`. **Fill that slot** with an end-to-end visual check that traverses the user's path: load the page in headless Chrome (via Chrome DevTools MCP), screenshot it, verify every referenced static asset returns 200 with content, then second-opinion the screenshot through Gemini against an explicit checklist.

## Requirements

- [ ] **All plugin-touching pages covered**: Plugins listing, Settings → UnraidZramCard, main Dashboard (the card on the Dashboard).
- [ ] **All static assets actually fetched**: for each page, enumerate `<img>`, `<script>`, `<link rel="stylesheet">` references from the rendered DOM, then HTTP-GET each one (with the test user's auth cookie). 200 + content-length > 0 + content-type matches expected family (image/*, application/javascript, text/css). **Any asset returning 4xx/5xx OR returning a redirect to a login page fails the run.** *(This is the assertion that would have caught the icon perm regression.)*
- [ ] **All feature surfaces verified rendered** per page (DOM assertions, not vibes — see "Coverage matrix" below).
- [ ] **Gemini second-opinion review** of each screenshot against `tests/visual_prompt.txt`. Each "high"-confidence finding from Gemini cross-checks against a DOM assertion (per the global `factory-ui-reviewer` pattern); a Gemini finding *without* a matching DOM assertion logs a WARN but doesn't fail the run (avoids Gemini false-positives breaking publishes).
- [ ] **Integrated into the deploy pipeline**: deploy wrapper calls `tests/run-l4.sh` after smoke. Exit 0 = green. Non-zero = publish-and-deploy reports `l4: FAIL` and the storefront publish should refuse to proceed.
- [ ] **Plugin states exercised, not just one snapshot**: at minimum, run the suite with Tier 2 *active* AND with Tier 2 *inactive-but-file-present* (the ACTIVATE-button state introduced in #749). Optional later: Tier 1-only mode, Tier 2-only mode, no-tiers fallback.
- [ ] **Screenshots archived per run** to `C:/tmp/<plugin>-shots/run-<timestamp>/` for post-mortem (same convention as the `factory-ui-reviewer` agent).

## Design

### Coverage matrix

| Page | Static assets to GET-probe | DOM assertions | Screenshot review |
|---|---|---|---|
| **`/Plugins`** | `plugins/unraid-zram-card/unraid-zram-card.png` (icon); any CSS the row uses | row exists with plugin name + version + status badge (NOT "partially installed"); `<img src="…unraid-zram-card.png">` present, naturalWidth > 0 | icon renders, version is current, no "partially installed" badge, no broken-image fallback glyph |
| **`/Settings/UnraidZramCard`** | the full set: `js/zram-card.js`, `js/zram-settings.js`, `js/chart.min.js`, all `?v=…` cache-busted; the icon | Tier 1 card present (header + body); Tier 2 card present with state-correct body (Active OR file-exists-but-inactive with ACTIVATE button OR no-file with drive picker); Plugin Settings section with swappiness + refresh + collection inputs; Advanced panel `<details>` collapsed; Activity log container | all sections rendered, no `Invalid .page format` artefact, no `undefined`/`NaN` text, button placement clean, no broken assets |
| **`/Dashboard`** (zram card visible) | same JS bundle + icon | dashboard card present; mini-chips render (Uncompressed, Compressed, Ratio, Load, Swappiness OR mode-appropriate subset per `DASHBOARD_TIER2_VISIBILITY.md`); chart canvas exists and `naturalWidth > 0` post-init; cache-buster on `?v=` is a numeric `filemtime`, not a calendar string | chart visible (not empty/broken), chips populated (no `null`/`NaN`), units sensible (MB/GB not raw bytes), icon if displayed |

### Static-asset probe (the gap-closer)

For each page, after `page.evaluate()` resolves the DOM, collect all of:

```javascript
[...document.querySelectorAll('img[src], script[src], link[rel=stylesheet][href]')]
  .map(el => el.src || el.href)
  .filter(url => url.startsWith(location.origin) || url.startsWith('/'))
```

Then for each URL, `curl -s -b "$COOKIE" -o /tmp/probe -w '%{http_code} %{size_download} %{content_type}\n' "$URL"`. Fail the run if:
- `http_code` ≠ 200, OR
- `size_download` == 0 (this catches the 0-byte stub failure shape directly), OR
- `content_type` doesn't match the family expected for the extension (`.png` → `image/*`, `.js` → `application/javascript` or `text/javascript`, `.css` → `text/css`).

A login-redirect (302 to `/login`) fails the family check (`text/html` for what should be `image/*`) — both helpful framings.

### Gemini second-opinion (existing pipeline, extended)

`tests/visual_review.sh` already runs the Gemini pass. Extend `tests/visual_prompt.txt` with the explicit per-page checklist (icon present + no broken images + key UI elements visible). The DOM-assertion cross-check happens after: each Gemini finding tagged `"severity": "high"` must correspond to a DOM-level assertion the script already evaluates; unmatched-but-high findings log WARN and continue.

### Wiring into the pipeline

Drop `tests/run-l4.sh`. The deploy wrapper already greps for it (`l4: skipped (no tests/run-l4.sh in plugin)`). When present + executable, the wrapper invokes it post-smoke, fails the publish on non-zero exit.

```bash
#!/bin/bash
# tests/run-l4.sh — visual + asset-fetch L4 gate
# Driven by .gemini/skills/unraid-factory/scripts/publish-and-deploy.php after smoke.
# Requires: HOST=192.168.1.4 SESSION_COOKIE=...  (deploy wrapper supplies)
set -euo pipefail
cd "$(dirname "$0")"

bash ui_pages_visit.sh         # Chrome DevTools MCP driver, screenshots + DOM evidence
bash assets_probe.sh           # curl every referenced asset, fail on 4xx/5xx/0-byte
bash visual_review.sh          # Gemini second-opinion against visual_prompt.txt
```

(Each sub-script returns non-zero on failure; `set -e` propagates.)

## Settings
None.

## Edge Cases

- **Asset URL points off-origin** (e.g. a CDN'd Chart.js, font from Google) — skip probe (already filtered by `startsWith(location.origin)`), or HEAD-only check.
- **Login-redirect on probe** — the test runner must supply a valid session cookie. Failure to authenticate is itself a test failure (the harness can't see what a user sees). Cookie is captured once in `run-l4.sh` via the same flow `factory-ui-reviewer` uses.
- **Chrome DevTools MCP unavailable** — `run-l4.sh` exits with a clear "MCP not available" message; deploy wrapper treats it as L4-skipped (warn, not fail) so a missing-tool doesn't block local developer iteration.
- **Gemini quota exceeded / API down** — visual_review.sh logs the failure and exits 0 for that sub-step; DOM + asset probes still gate the publish.
- **Plugin state for "Tier 2 inactive but file exists"** is hard to fabricate cleanly — needs `swapoff` on the file in the test setup. Pragmatic: assert the ACTIVATE button render path via a state-fixture that pre-`swapoff`s and re-`swapon`s around the test (script-side, in `ui_pages_visit.sh`).

## Verification

- A run of `bash tests/run-l4.sh` against a healthy install passes (every page renders, every asset probes 200, Gemini agrees).
- Deliberately break the install (e.g. `chmod 0600 /usr/local/emhttp/plugins/unraid-zram-card/unraid-zram-card.png`) → `run-l4.sh` fails with a clear "asset probe FAIL: …unraid-zram-card.png returned 403" message AND the icon-broken Gemini finding fires.
- The `publish-and-deploy.php` wrapper, on a healthy install, reports `l4: ok` (not `skipped`). On a broken install, it reports `l4: FAIL` and refuses to mark publish green.

## Related

- `tests/ui_e2e_playbook.md` — the existing visual-review playbook this builds on.
- `tests/visual_review.sh` / `tests/visual_prompt.txt` — the Gemini pipeline being extended.
- `.claude/docs/unraid-patterns/SERVER_SIDE_RENDER_MODES.md` — the three render modes (both tiers / Tier 1 only / Tier 2 only) the coverage matrix should iterate over.
- The user-level `jpw-ui-visual-review` skill — same Gemini + Chrome MCP pattern, generalised.
- `factory-ui-reviewer` agent (project-level) — implements the Gemini-finding-cross-checks-DOM-assertion pattern this spec adopts.
- 2026-05-14 incident — the regression that exposed the gap.
