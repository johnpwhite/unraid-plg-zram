# Feature: Test Suite for ZRAM Plugin

## Status
Approved

## Problem
The publish pipeline currently runs only syntax-level checks (`php -l`, JSON parse, XML well-formedness, ShellCheck). Real bugs have shipped that none of those catch:

- **2026.04.17.02**: form action hardcoded to wrong URL. Settings save silently dropped for an unknown duration.
- **2026.04.17.02**: dashboard cache-buster was a literal `'2026.04.16'` string, so browsers served stale JS through multiple plugin upgrades.

We need a layered test suite that runs pre-publish and post-deploy, catching both classes of bug without the overhead of maintaining a browser-automation harness.

## Requirements
- [ ] Pre-publish: static analysis uplift — PHPStan level 5 on `src/**/*.php`, ESLint on `src/js/*.js`.
- [ ] Pre-publish: unit tests for pure PHP functions (config read/write, device filter, formatters) and pure JS logic (formatBytes, history filter, chart config assembly).
- [ ] Post-deploy: SSH-based smoke suite that exercises the live plugin on the test server.
- [ ] Pipeline integration: `publish-factory.ps1` aborts on failure at any layer. Haiku deploy subagent runs smoke suite after `plugin install` and reports failures.
- [ ] No GitHub Actions / GitLab CI — all testing runs locally on dev machine + test server.
- [ ] No browser automation — SSH+curl+Vitest cover 95% of actionable bugs; screenshot review stays as the final human step for visual correctness.

## Design

### Layer 1: Static Analysis (pre-publish, local)
**Goal:** catch type errors, undefined variables, dead code, null-safety bugs.

- **PHP** — PHPStan level 5.
  - File: `unraid-plg-zram/phpstan.neon`
  - Scope: `src/**/*.php` (not `.page` — those have embedded PHP but PHPStan doesn't handle mixed templates well; lint handles those via `php -l`).
  - Already probed by `publish-factory.ps1` at `[0C/5]` step — just needs a `phpstan.neon` at plugin root.
- **JS** — ESLint with `eslint:recommended`.
  - Files: `unraid-plg-zram/.eslintrc.json`, `unraid-plg-zram/package.json` (devDep only — no runtime deps).
  - Scope: `src/js/*.js`.
  - Rule overrides: `no-unused-vars: warn` (not error — we have legacy settings.js helpers).

### Layer 2: Unit Tests (pre-publish, local)
**Goal:** exercise deterministic code paths without touching Unraid runtime.

- **PHP — PHPUnit**
  - Location: `unraid-plg-zram/tests/php/`
  - Config: `unraid-plg-zram/phpunit.xml`, `unraid-plg-zram/composer.json` (dev-only)
  - Target functions:
    - `zram_config_read()` — merges defaults, tolerates missing file, reads from fixture `.ini`.
    - `zram_config_write()` — round-trip write-read preserves values, atomic lock behaviour.
    - PLG cache-buster — verify the `filemtime` call returns a fresh integer and embeds into the script URL (regression guard for the 2026.04.17.02 bug).
- **JS — Vitest + JSDOM**
  - Location: `unraid-plg-zram/tests/js/`
  - Config: `unraid-plg-zram/vitest.config.js` (devDep: vitest, jsdom)
  - Target functions (expose via `module.exports` guarded by `typeof module`):
    - `formatBytes()` — byte-formatting edge cases (0, NaN, large TB).
    - History backfill filter — legacy `{s,l}` entries dropped, new `{o,u,l}` entries kept (regression guard for the 2026.04.17.01 schema migration).

### Layer 3: SSH Smoke Suite (post-deploy, remote)
**Goal:** catch integration-level bugs the moment they ship to the test server.

- Location: `unraid-plg-zram/tests/smoke.sh` — runs *on the test server*, invoked via SSH from the deploy workflow.
- Assertions (numbered so failures are easy to report):
  1. `UnraidZramDash.page` renders without PHP notices/warnings/fatals.
  2. `zram_status.php` returns HTTP 200 with valid JSON containing `aggregates.total_used`, `aggregates.total_original`, `aggregates.compression_ratio`.
  3. Settings form POST persists: grab CSRF from `/var/local/emhttp/var.ini`, POST to `/Utilities/UnraidZramCard`, verify `settings.ini` mtime advanced AND `refresh_interval` value was written.
  4. Dashboard HTML embeds a cache-busted JS URL (`zram-card.js?v=<digits>`), and the digits change between two consecutive plugin installs.
  5. Collector PID file exists and the process is alive.
  6. `history.json` contains at least one new-schema entry (`{t, o, u, l}`).

Exit codes: 0 = all pass; 2..7 = specific assertion failed (2 = assertion #1, etc.); 1 = infrastructure error (ssh down, etc.).

### Pipeline Integration
**`publish-factory.ps1`** gains a new stage between `[0C/5]` and `[0D/5]`:
```
[0D/5] Running unit tests...
  > PHPUnit: <count> tests, <count> assertions — OK
  > Vitest: <count> tests passed
```
Aborts on any failure. Layer 1 (phpstan/eslint) already runs in the existing `[0C/5]` code-validation block — extend that block to invoke the new tools when their config files exist.

**Haiku deploy subagent prompt** gains new steps after `plugin install`:
```
# Save rollback point (previous plg version) before install — owned by caller, not smoke
cp /var/log/plugins/unraid-zram-card.plg /tmp/unraid-zram-card.plg.prev

# Copy smoke script to server (tests/ does NOT ship inside the tarball — prod installs stay pristine)
scp -o StrictHostKeyChecking=no tests/smoke.sh root@<ip>:/tmp/zram-smoke.sh
ssh root@<ip> "bash /tmp/zram-smoke.sh"
```

**Rollback policy:**
- **Storefront releases**: auto-rollback on smoke failure. `plugin install /tmp/unraid-zram-card.plg.prev` restores the previous version with no prompt. A broken public release should not survive on the user's daily-driver server.
- **Factory releases**: prompt/flag only. Report the failing assertion number + output; do NOT auto-rollback. During dev iteration, the user may have deployed a known-broken version intentionally. Opt-in via a `-RollbackOnSmokeFail` flag on `publish-factory.ps1` for users who want stricter Factory loops.

The rollback mechanism is owned by the deploy wrapper (Haiku subagent prompt), not by `smoke.sh` itself. `smoke.sh` returns a numbered exit code and never touches plugin state — single responsibility.

**Final layout:**
```
unraid-plg-zram/
├── phpstan.neon               # Layer 1 — PHP static analysis
├── .eslintrc.json             # Layer 1 — JS static analysis
├── package.json               # devDeps: eslint, vitest, jsdom
├── composer.json              # devDeps: phpunit/phpunit
├── phpunit.xml                # PHPUnit config
├── vitest.config.js           # Vitest config
└── tests/                     # Dev-only — none of this ships to the server
    ├── smoke.sh               # Post-deploy smoke, scp'd per-deploy
    ├── php/
    │   ├── ConfigTest.php
    │   └── CacheBusterTest.php
    ├── js/
    │   └── zram-card.test.js
    └── fixtures/
        └── settings.ini
```

Keeping `tests/` at plugin root (not inside `src/`) means production installs stay pristine — no test artefacts shipped to end users. The Haiku subagent `scp`s `smoke.sh` per deploy, runs it, and cleans up.

## Settings
None. All test configuration lives in config files at plugin root.

## Edge Cases
- **PHPStan/Vitest not installed**: `publish-factory.ps1` probes for `phpstan.neon` and `package.json` and skips the corresponding stage with a NOTICE, not an error. This keeps the pipeline working for plugins that haven't adopted the pattern yet.
- **Smoke suite fails but deploy was intentional**: Haiku subagent reports failures verbatim but does NOT roll back the install (destructive). User decides.
- **Test server offline**: smoke suite skipped with a NOTICE. Factory publish is not blocked — the user is in control of deploy timing anyway.
- **Legacy `{s,l}` history entries at test time**: the JS history-filter test uses a fixture with both schemas mixed; the Vitest assertion is on the filtered output, not the source, so this is deterministic.

## Verification
1. Run `phpstan analyse` against current `src/**/*.php` — should pass at level 5 with zero issues (or a baseline file committed).
2. Run `eslint src/js/` — should pass.
3. Run `./vendor/bin/phpunit` — should pass.
4. Run `npx vitest run` — should pass.
5. Publish a trivial doc change via `/unraid-factory`. The pipeline should show a new `[0D/5] Running unit tests...` stage with all green.
6. Intentionally break the cache-buster (revert to a hardcoded string) and rerun — the CacheBusterTest should fail and abort the publish.
7. Intentionally break the form action (set it to `/Settings/UnraidZramCard` again) and rerun after deploy — smoke assertion #3 should fail with a clear message.
