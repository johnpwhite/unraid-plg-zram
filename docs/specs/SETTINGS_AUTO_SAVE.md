# Feature: Settings Auto-Save (2026.05.06.05)

## Status
Approved

## Problem
The Plugin Settings card needs an explicit `APPLY & SAVE` click to persist any change. Tier 1 / Tier 2 actions (CREATE/REMOVE) are already AJAX with immediate effect, and CREATE for Tier 1 reads live form state for `size_mode`/`percent`/`algo`. The remaining batch-save button is now the only place on the page that requires an explicit click — out of step with the rest of the UI.

User asked: is the button still needed, or can changes save on blur?

## Requirements
- [x] Each settings field auto-saves on `blur` (text/number) or `change` (select/checkbox)
- [x] Inline `Saved ✓` indicator next to the field that just saved (green, fade out after ~1.5s)
- [x] Per-key validation in PHP — values clamped/normalised before write
- [x] Targeted side effects: `sysctl vm.swappiness=N` only when swappiness changed; collector restart only when `collection_interval` or `debug` changed
- [x] APPLY & SAVE button removed
- [x] POST handler retained as a deprecated fallback (anyone with a bookmarked submit URL keeps working)
- [x] Tier 1 form fields (`size_mode` / `zram_percent` / `zram_algo`) also auto-save on change — they take effect on next CREATE / next reboot, same semantic as today, just no longer waiting on a button click

## Design

### New PHP action: `update_setting`

Single-key write. Whitelist of accepted keys; per-key validation; targeted side effects.

```
GET zram_actions.php?action=update_setting&key=<name>&value=<val>&csrf_token=<csrf>
```

**Whitelist**:
```
enabled, refresh_interval, collection_interval, swappiness,
debug, console_visible, zram_size, zram_percent, zram_algo
```

Anything outside the whitelist returns `{success: false, message: 'Invalid setting key'}`.

**Per-key validation**:

| Key | Validation |
| :--- | :--- |
| `enabled`, `debug`, `console_visible` | `'yes'` / `'no'` (any other input → `'no'`) |
| `refresh_interval` | float seconds → `* 1000` → `max(1000, ms)` ; legacy raw ms (≥ 100) accepted as-is |
| `collection_interval` | `max(1, intval())` |
| `swappiness` | clamped 0–200 |
| `zram_size` | `'auto'` or `/^\d+\s*[GMT]$/i` (else rejected) |
| `zram_percent` | clamped 25–75 |
| `zram_algo` | must be in `['zstd','lz4','lzo','deflate']` |

**Side effects** (only when the saved key requires it):
- `swappiness` → `sysctl -q vm.swappiness=N` immediately
- `collection_interval` or `debug` → restart collector daemon (PID-file dance, then `nohup zram_init.sh`)
- `enabled` → no immediate effect; consumed on next dashboard render
- `refresh_interval` → no immediate effect; consumed on next page load (UI poll cadence)
- `console_visible` → no immediate effect; consumed on next page load
- `zram_size`, `zram_percent`, `zram_algo` → no immediate effect; consumed on next CREATE / reboot

This is a meaningful efficiency improvement over the old POST handler, which restarted the collector on every save regardless of whether the change needed it.

### Frontend wiring

Each form field gets `data-autosave="true"` (single attribute marker — no per-field JS needed).

Single bootstrap call iterates `[data-autosave]` and attaches the right listener:
- `<input type="text|number">` → `blur`
- `<input type="checkbox">` → `change`
- `<input type="range">` → `change` (so the slider commits on release, not on every drag tick)
- `<select>` → `change`

Each handler reads `el.name` (the canonical settings key) and `el.value` / `el.checked`, fires `update_setting`, and on success spawns a `Saved ✓` indicator next to the field.

### Indicator

```html
<span class="zram-saved-indicator">Saved ✓</span>
```

```css
.zram-saved-indicator {
  display: inline-block;
  margin-left: 8px;
  color: #7fba59;
  font-size: 0.85em;
  font-weight: bold;
  opacity: 1;
  transition: opacity 0.5s ease-out;
}
.zram-saved-indicator.fade { opacity: 0; }
```

JS lifecycle:
1. On save success: append the span next to the input
2. After 1000ms: add `.fade` class → CSS transitions opacity to 0
3. After 1500ms: remove the span from the DOM

If the user makes another change before the previous indicator finishes fading, the previous span is removed first — only one indicator at a time per field.

### Error handling

If `update_setting` returns `{success: false, message: '...'}`, show a red `! <message>` indicator instead of `Saved ✓`. Same lifecycle (fades after 2s — slightly longer to give the user time to read).

If the AJAX call itself fails (network/timeout): show `Save failed — retry` with no auto-fade, and re-enable the field's listener so the next blur retries.

### POST fallback

Keep the existing POST handler in `UnraidZramCard.page` so an in-flight pre-upgrade form submission still works. Marked with a deprecation comment. To be removed in a future release once we're confident no caller hits it.

## Settings
None.

## Edge Cases
- **User mid-types a value, tabs away** — blur fires, partial value saved. Min/max clamps protect against absurd values. Acceptable: same as Unraid's own settings forms behave.
- **User changes swappiness during a swap event** — `sysctl` is non-blocking; no risk.
- **Two fields blurred in rapid succession** — two parallel AJAX calls, two indicators appear on different fields. No race because each save targets a distinct key.
- **Collector restart while collection_interval saved** — old PID killed, new daemon picks up the new interval. Same path as the existing batch save handler.
- **Browser auto-fill on page load** — fires `change` events on inputs even though the user did not interact. Mitigation: capture `data-original-value` on render, only fire save if `el.value !== el.dataset.originalValue` AND `el.dataset.originalValue !== undefined` (skips the very first synthetic change).
- **Tier 1 settings changed after CREATE** — saves to config; active device unaffected. Expected and matches existing semantic.
- **POST fallback path hit** — old handler still writes the same fields, still restarts the collector unconditionally. No regression for that path.

## Verification

### L2 (PHPUnit) — new file `tests/php/SettingsAutoSaveTest.php`
- `update_setting` action handler exists in `zram_actions.php`
- Whitelist enforcement (loops over each invalid key, expects rejection — text guard)
- Per-key validation: swappiness clamp, refresh_interval seconds-to-ms, zram_percent clamp, etc.
- Targeted side-effect gating: `sysctl vm.swappiness` only inside the swappiness branch; collector restart only inside the collection_interval/debug branch
- Page contains `data-autosave="true"` on every settings field
- Page no longer contains `<button … name="save_settings">`
- Page contains `.zram-saved-indicator` CSS class
- JS contains the autosave bootstrap (`document.querySelectorAll('[data-autosave]')`)

### L3 (smoke.sh)
Existing assertions stay green. New soft check: settings render still has all the UX clarifier strings (no regression on the swappiness / priority explainers).

### Manual (post-deploy)
- Open settings, change swappiness from 150 → 160, tab away → Saved ✓ appears, fades after ~1.5s
- `cat /proc/sys/vm/swappiness` immediately shows 160
- Change collection_interval → collector restarts (`pgrep -f zram_collector` shows new PID)
- Refresh page — values persist
- Change a Tier 1 setting (e.g. algo from zstd to lz4) → Saved ✓ — confirm `settings.ini` has new algo; active ZRAM device unchanged until next CREATE
- APPLY & SAVE button no longer in the DOM
