# Feature: L4 — UI Interaction + Visual Review

## Status
Draft

## Problem
L1–L3 catch server-side correctness, pure-logic regressions, and config persistence. They don't catch:
- Client-side interaction bugs (broken onclick handlers, drive picker not highlighting)
- Visual regressions that only appear under interaction (tooltip clipping on hover — shipped and fixed this session)
- Silent JS errors that don't break the page but break a feature

We need a layer that *drives the UI* and *judges the output* — a real browser running real user interactions, with both hard DOM assertions and a fuzzy LLM reviewer for aesthetic/correctness concerns.

## Requirements
- [ ] Interaction coverage: hover chart, click settings cog, change refresh interval + save, switch tabs, click drive picker row.
- [ ] DOM-level assertions after each interaction (deterministic gate).
- [ ] Screenshots captured at each state, fed to Gemini with a structured-correctness prompt (fuzzy gate).
- [ ] Runs as L4 in the deploy flow, after L3 SSH smoke passes.
- [ ] Non-blocking on LLM `medium`/`low` findings; blocking on LLM `high` findings + any DOM-assertion failure.
- [ ] Token cost near-zero (Gemini free quota).
- [ ] No GitHub/GitLab CI — runs locally + on the test server.

## Design

### Architecture
```
Deploy flow:
  publish → SSH deploy → L3 smoke.sh → [L4 ui_e2e] → L4 visual_review.sh → report
                                         └─ Haiku+MCP   └─ Opus+gemini CLI
```

### Components

**`tests/ui_e2e_playbook.md`** — a Haiku-subagent instruction document. The subagent has Chrome DevTools MCP tools and walks the playbook:
- Each step: navigate / click / hover / fill action + DOM assertion + screenshot capture
- Returns structured JSON at end: `{steps: [{n, pass, screenshot_path, dom_check, notes}]}`

**`tests/visual_review.sh`** — bash harness that takes a directory of screenshots + an expectation file, calls `gemini -m gemini-2.5-flash -p "<PROMPT>" -i <screenshot>` per image, parses JSON, aggregates.

**`tests/visual_prompt.txt`** — the prompt template for Gemini. Observable-facts-only; no aesthetic judgement.

### Auth handling
Unraid dev server has `myunraid.net` redirect on. Chrome MCP hits login → 302.

**Mitigation**: inject the `unraid_*` session cookie into the browser context before navigation. Cookie sourced from the server's `/var/local/emhttp/var.ini` (we already read this file in L3 smoke for CSRF).

Haiku subagent prompt includes the cookie injection as step 0:
```
1. ssh root@<ip> "grep unraid_ /var/local/emhttp/var.ini"  → capture session id
2. mcp__chrome-devtools__new_page http://<ip>/Dashboard
3. mcp__chrome-devtools__evaluate_script document.cookie = "unraid_XXX=<sessionid>"
4. mcp__chrome-devtools__navigate_page http://<ip>/Dashboard  (reload with cookie)
```

If cookie approach fails, fallback: programmatic login via `mcp__chrome-devtools__fill_form` on `/login`.

### DOM assertions pattern
Per step, use `mcp__chrome-devtools__take_snapshot` to get the accessibility tree, then check for specific elements/text. Fallback to `evaluate_script` for precise measurements (e.g. `document.getElementById('zram-chart-tooltip').getBoundingClientRect().bottom < window.innerHeight`).

### Playbook scope (minimum viable first iteration)
1. Dashboard idle — card rendered, 5 stat cards visible, chart canvas non-zero size.
2. Chart hover at x=10%, 50%, 90% — tooltip appears, tooltip bottom edge within viewport, tooltip contains `Uncompressed`/`Compressed`/`Load` text.
3. Settings cog click — navigates to `/Utilities/UnraidZramCard`.
4. Settings page — form fields present; console tabs switch; refresh input accepts new value and Save completes without a visible error banner.

Items deferred to future iterations: drive picker selection, collector action buttons (create/destroy), debug log clear.

### Screenshot + Gemini review
After each step's screenshot lands, aggregate all into a single Gemini call or per-image calls. Prompt:

```
You are reviewing a screenshot of an Unraid plugin UI.
Focus ONLY on observable correctness issues. Ignore aesthetic preferences.

Check for:
1. UI elements clipped by their container (tooltip cut off, text truncated, chart extending beyond card)
2. Text that overlaps other elements or is unreadable
3. Missing or broken content (empty cards, literal "undefined" / "null" / "NaN" showing)
4. Broken layout (overlapping boxes, misaligned grid cells)

Output strict JSON:
{"issues": [{"severity": "high"|"medium"|"low", "element": "<string>", "problem": "<string>"}]}

If no issues, return {"issues": []}.
```

Severity threshold: `high` → fail deploy report; `medium`/`low` → warn.

### Integration
L4 runs via a Haiku subagent dispatched by Opus after L3 passes. Output collected by Opus, which:
1. Parses the subagent's JSON report (per-step DOM assertion results + screenshot paths).
2. Runs `tests/visual_review.sh <screenshot_dir>` which calls Gemini per screenshot.
3. Combines both signals into a final L4 report: `{dom_pass: bool, lm_findings: [...], overall: pass|warn|fail}`.

### Failure behaviour
Consistent with L3:
- Factory publishes: report findings, do NOT auto-rollback.
- Storefront publishes: auto-rollback on `overall: fail`.

## Settings
None. All config lives in `tests/` files.

## Edge Cases
- **MCP unavailable / session ended**: skip L4 with a NOTICE. L3 still gates.
- **Test server offline**: skip L4.
- **Gemini rate-limited** (free quota exhausted): retry with `gemini-2.5-flash-lite` or skip visual review. DOM assertions still gate.
- **Cookie extraction returns empty**: fallback to form login. If that fails, skip L4 with NOTICE (not a hard fail — L3 is the gate).
- **Screenshot mis-aligned** (browser resized): the prompt asks for observable issues, not layout-comparison, so minor rendering variance doesn't cause false positives.

## Verification
1. Publish a trivial change. Deploy flow runs L3 green, then L4 dispatches Haiku.
2. Haiku captures 4 screenshots. Visual_review.sh runs Gemini per image.
3. Report shows 0 DOM-assertion failures and 0 high-severity LLM findings.
4. Intentionally revert the tooltip fix (canvas-drawn, clips). Rerun — L4 should flag `high: tooltip clipped at bottom of chart card`.
5. Intentionally break the settings save (form action URL). Rerun — L3 already catches via assertion 3; L4 additionally shows the Settings page still rendering correctly (no DOM regression).
