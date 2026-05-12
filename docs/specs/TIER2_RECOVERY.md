# Feature: Tier 2 Disk Swap — Recovery Path (ACTIVATE button + collector self-heal)

## Status
Approved

## Problem

Tier 2 (the disk swap file) can land in a **"File exists, not active"** state with **no recovery path** short of SSH or a plugin restart:

- `zram_init.sh` runs a background retry poller (60 × 5 s = 5 min) when the swap file's mount isn't ready at plugin-start time — see `TIER2_BOOT_RETRY.md` (v2026.05.06.09, OP #425). That covers the normal boot race where Unassigned-Devices / pool mounts come up 60–120 s late.
- If the mount comes up **later than 5 minutes** — a long array outage, USB-stick replacement, parity-check-then-mount, etc. — the poller logs *"mount never appeared after 5 minutes — Tier 2 stays inactive"* and exits. The file is intact (it still has its `mkswap` signature) but nothing re-runs `swapon`.
- The settings-page Tier 2 card (`UnraidZramCard.page`) renders **status only** in this state. The `CREATE SWAP FILE` button is hidden whenever the file exists; `REMOVE` only renders when the swap is *active*. Dead end.

Reported from real use: a server with a failing USB stick had its array offline for hours; after recovery the Tier 2 card showed "File exists, not active" with no way to fix it from the UI. (See `30-…` arc / OpenProject Bug.)

## Requirements

- [ ] An **ACTIVATE** button on the Tier 2 card, shown only when the swap file exists on disk but is not in `/proc/swaps`. One click → `swapon` the existing file at the configured priority. No re-`dd`, no `mkswap` — the file is already a valid swap area.
- [ ] The collector daemon **self-heals**: if Tier 2 is configured-enabled and its file exists but isn't active, re-activate it on its next tick. So a multi-hour outage recovers with zero user action.
- [ ] Both paths reuse the same activation semantics as `zram_init.sh`'s `activate_disk_swap()`: legacy `ZRAM_CARD_SSD` → `ZRAM_CARD_DISK` label migration (best-effort, while offline), priority clamped to 0–32767, `ssd_swap_enabled=yes` reasserted in config.
- [ ] Collector self-heal must **not undo a user REMOVE**: the collector's config is cached (~60 s stale), so the heal path re-reads config fresh before acting.
- [ ] Collector self-heal **backs off** 60 s after a `swapon` failure (e.g. a filesystem that genuinely can't host a swap file) so it doesn't spam syscalls/logs every tick.
- [ ] The boot-retry poller in `zram_init.sh` is **retained unchanged** — it's the fast path; the collector is the long-tail catch-all.

## Design

### User Experience

Tier 2 card states:

| State | Header button | Body |
|---|---|---|
| Active | `REMOVE` (danger) | "Active · used X / Y · priority N — overflow only" + path |
| **File exists, not active** | **`ACTIVATE` (default)** | "File exists, not active · Y · priority N — overflow only" + path |
| No file | — | drive picker + size input + `CREATE SWAP FILE` |

Clicking `ACTIVATE` runs through the standard `zramAction()` path: disables the button, logs "Running: Activate disk swap file…" to the Activity feed, calls the AJAX endpoint, then on success logs "Done: …" and reloads the page (showing the now-Active state).

If the collector self-heals first (file/mount appeared, collector's next tick caught it), the user sees an "Auto-reactivated disk swap file …" entry in the Activity feed and the card shows Active on next refresh — no click needed.

### Backend

**`zram_actions.php` — new action `activate_disk_swap` (alias `activate_ssd_swap`)**, placed after `remove_disk_swap`:

1. CSRF-guarded (the file's blanket mutating-action guard already covers it).
2. `$cfg = zram_config_read(); $swapFile = $cfg['ssd_swap_path']`.
3. Empty / `!file_exists` → `{success:false, message:"No disk swap file to activate. Create one first."}`.
4. Already in `/proc/swaps` → reassert `ssd_swap_enabled=yes` if needed, return `{success:true, message:"Disk swap is already active"}` (idempotent).
5. Legacy-label migration: `swaplabel <file>`; if `LABEL:` is `ZRAM_CARD_SSD`, `swaplabel -L ZRAM_CARD_DISK <file>` (best-effort via `zram_run`; a relabel failure is non-fatal).
6. `$prio = clamp(intval($cfg['ssd_swap_priority'] ?? 10), 0, 32767)`.
7. `zram_run("swapon <file> -p $prio", $logs)`; non-zero → `{success:false, message:"swapon failed — …mount may not be ready, or the filesystem may not support swap files."}`.
8. `zram_config_write(['ssd_swap_enabled' => 'yes'])`; `zram_cmd_log(...)`; return `{success:true, message:"Activated disk swap file (N priority)"}`.

**`zram_config.php` — new shared function `zram_reactivate_disk_swap_if_needed(array $cachedCfg, int &$nextTry): bool`:**

- Cheap pre-checks against the *cached* config: `ssd_swap_enabled === 'yes'`, `ssd_swap_path` non-empty, `file_exists($path)`, `$path` NOT in `/proc/swaps` (the `/proc/swaps` read is a virtual-file read, negligible). Plus `time() >= $nextTry` (back-off gate). Any miss → return `false` fast, no flash I/O.
- If pre-checks pass, **re-read config fresh** (`parse_ini_file(ZRAM_CONFIG_FILE)`) — guards against a user REMOVE that the cached copy hasn't picked up yet. Re-confirm `ssd_swap_enabled === 'yes'` and re-read `ssd_swap_path` / `ssd_swap_priority`. Re-check `/proc/swaps` once more.
- Legacy-label migration (best-effort, silent `exec`).
- `swapon <path> -p <prio>`:
  - exit 0 → `zram_log("Tier 2 self-heal: re-activated …", 'INFO')`, `zram_cmd_log("Auto-reactivated disk swap file …", 'cmd')`, `$nextTry = 0`.
  - exit ≠ 0 → `zram_log("Tier 2 self-heal: swapon … failed (…) — backing off 60s", 'WARN')`, `$nextTry = time() + 60`.
- Returns `true` iff it acted (so the caller could log/branch; the collector ignores the return — the function logs its own outcome).

**`zram_collector.php` — wire it in:** declare `$selfHealNextTry = 0;` before the `while (true)` loop; call `zram_reactivate_disk_swap_if_needed($settings, $selfHealNextTry);` once per iteration inside the existing `try`, after the config-refresh block. (`$settings` is the cached config the collector already holds.)

### Frontend

- **`UnraidZramCard.page`** — the Tier 2 card header (currently `if ($ssdActive) { REMOVE button }`) becomes:
  ```php
  <?php if ($ssdActive): ?>
          <button type="button" class="zram-btn zram-btn-danger" onclick="zramAction('remove_disk_swap')">REMOVE</button>
  <?php elseif ($ssdPath && file_exists($ssdPath)): ?>
          <button type="button" class="zram-btn" onclick="zramAction('activate_disk_swap')">ACTIVATE</button>
  <?php endif; ?>
  ```
  Same placement as `REMOVE` — no new layout surface. No body changes (the "File exists, not active" body already exists from `TIER2_BOOT_RETRY.md` work).
- **`src/js/zram-settings.js`** — add to `ZRAM_ACTION_LABELS`: `activate_disk_swap: 'Activate disk swap file'` (and `activate_ssd_swap: 'Activate disk swap file'` for alias parity). No other JS changes — `zramAction()` is already generic.

## Settings
None. Reuses `ssd_swap_enabled`, `ssd_swap_path`, `ssd_swap_priority`.

## Edge Cases

- **File exists but corrupt / wrong signature** → `swapon` fails; the action returns the failure message; the collector backs off 60 s. The user can REMOVE (the REMOVE button isn't shown when inactive… → falls through to the drive picker once the file is gone; until then, recovery is "fix or delete the file via shell"). *Acceptable for now — corrupt-swap-file is not the reported scenario; revisit if it comes up.*
- **User clicks ACTIVATE while the collector is also self-healing** → both call `swapon`; the loser gets `EBUSY`/"already used" and reports it; net state is correct (active). The action's pre-check on `/proc/swaps` makes the race window tiny.
- **User clicks REMOVE, then collector's stale cache says enabled=yes** → the fresh-config re-read in `zram_reactivate_disk_swap_if_needed` sees `ssd_swap_enabled=no` and bails. No re-activation.
- **Mount still not ready when ACTIVATE clicked** → `swapon` fails ("mount may not be ready"); user retries once the share is up, or the collector catches it automatically.
- **Collector not running** (crashed / disabled) → no self-heal, but the ACTIVATE button still works. The two mechanisms are independent.
- **`/mnt/swap` is a different mount after USB swap** → `ssd_swap_path` is absolute (`/mnt/swap/.swap/zram-card.swap`); if the share remounts at the same path the file is found. If the user re-pointed the share elsewhere, `file_exists` is false → card shows the drive picker (CREATE), which is correct.

## Verification

- `tests/php/Tier2RecoveryTest.php` (source-inspection, mirrors `Tier2BootRetryTest.php` / `CompressedFieldFixTest.php` style):
  - `activate_disk_swap` (+ `activate_ssd_swap` alias) handler exists in `zram_actions.php`, runs `swapon -p` with `escapeshellarg`, writes `ssd_swap_enabled => 'yes'`, is idempotent against `/proc/swaps`, does the `ZRAM_LEGACY_SSD_LABEL` → `ZRAM_SSD_LABEL` migration.
  - `UnraidZramCard.page` renders an `onclick="zramAction('activate_disk_swap')"` button in an `elseif` branch gated on `file_exists($ssdPath)` and *not* when `$ssdActive`.
  - `zram-settings.js` `ZRAM_ACTION_LABELS` includes `activate_disk_swap`.
  - `zram_config.php` defines `zram_reactivate_disk_swap_if_needed`; it early-returns unless `ssd_swap_enabled === 'yes'`; it re-reads `parse_ini_file(ZRAM_CONFIG_FILE)` before acting; it sets `$nextTry = time() + 60` on failure; it logs INFO on success / WARN on failure.
  - `zram_collector.php` calls `zram_reactivate_disk_swap_if_needed(...)` inside the `while` loop and declares `$selfHealNextTry`.
- Manual on a test server: with Tier 2 active, `swapoff /mnt/swap/.swap/zram-card.swap`; reload settings → card shows "File exists, not active" + ACTIVATE button; (a) click ACTIVATE → card returns to Active; (b) or wait one collector interval → Activity feed shows "Auto-reactivated disk swap file …", card returns to Active.
- Full suite green: `vendor/bin/phpunit`, `npx vitest run`, `bash tests/smoke.sh`.

## Related
- `docs/specs/TIER2_BOOT_RETRY.md` — the boot-time fast path this complements.
- `docs/specs/REMOVE_ZRAM_STATE_SYNC.md` — the silent-`swapoff`-failure fix; the same "kernel/UI state must agree" principle applies here.
- OpenProject Bug (this work) — captured inline in the WP description until the Bug type's form exposes `canonical_spec`.
