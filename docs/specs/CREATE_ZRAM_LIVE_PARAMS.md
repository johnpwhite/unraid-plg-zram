# Feature: CREATE uses live form values, not stale saved config

## Status
Approved

## Problem

Reported alongside the REMOVE state-sync bug:

> "I am not able to set the Auto Size to 75% nor make the size customizable.
>  It always creates 16G. NVM figured it out -> You have to save settings first."

The Tier 1 form has three controls — size mode (auto/custom), percent slider
(25–75 % of RAM), and algorithm — but those controls are bound to the *settings*
`<form>`, not to the CREATE button. Their value only persists when the user
clicks **APPLY & SAVE**. The CREATE button calls `zramAction('create_zram')`
with no parameters; the action handler reads `zram_size`, `zram_percent`, and
`zram_algo` from the *saved* config (`zram_actions.php:55-58, 60-66`) and
produces a device sized from the previous save — not from what the user just
selected.

The user's fix ("save first, then create") works, but the UI gives no signal
that the controls aren't live, and the discoverability cost was a forum post
plus minutes of confusion. We can do better: make the CREATE click pass the
current form state, persist it as part of creation, and the apparent split
between "form" and "button" disappears.

A subtle related bug: even after a successful CREATE, the persisted config is
incomplete. `zram_actions.php:102` writes back `zram_algo` and the *old*
`zram_size`, but never updates `zram_percent`. So a user who created via the
settings form (where percent does persist) and then later created again via
the AJAX action would see their saved percent silently ignored on the next
boot's `zram_init.sh` run. The fix to use live params naturally eliminates
this: every CREATE persists every relevant key.

## Requirements

- [ ] CREATE button on the settings page passes current values for `size_mode`,
      `size` (when custom), `percent` (when auto), and `algo` to the
      `create_zram` action.
- [ ] `create_zram` accepts and validates these query params; falls back to
      saved config when any param is omitted (so init-script invocations and
      scripted calls keep working).
- [ ] After successful creation, the saved config reflects the values that
      were actually used — including `zram_percent`. Next-boot init must
      reproduce the current device size.
- [ ] No new endpoint and no extra round trip — params travel on the existing
      `create_zram` GET. Auto-save is invisible to the user.
- [ ] Custom size format is validated (`/^\d+\s*[GMT]$/i`); invalid input is
      rejected with an explicit error rather than silently auto-coerced.
- [ ] Algorithm is validated against the kernel's `comp_algorithm` list
      (already enumerated by the page render in `UnraidZramCard.page:71-75`)
      to defeat injection or typo'd values reaching `zramctl`.
- [ ] PHPUnit guard so a future refactor cannot quietly drop the live-param
      contract.

## Design

### User Experience

Single, invisible change. The user adjusts the slider/dropdowns and clicks
CREATE. The device is created with what the user *sees on screen*, not with
what was last saved to disk. APPLY & SAVE retains its existing role for
non-creation settings (refresh interval, debug toggle, console visibility,
etc.) and as the explicit "remember these for boot" affordance — which the
new behaviour also satisfies as a side-effect of every CREATE.

No new buttons. No confirm dialog. No "unsaved changes" overlay. The
overlay was rejected because it adds a click for the common case (user
adjusts then creates) to defend against an edge case (user wants to create
with old saved values) that has no real user story.

### Backend

**`src/zram_actions.php` — `create_zram` action**

The top of the action becomes:

```
sizeMode = ?size_mode  (auto | custom)
sizeIn   = ?size       (custom path — e.g. "4G")
pctIn    = ?percent    (auto path — 25..75)
algo     = ?algo       (validated against /sys/block/zram*/comp_algorithm)

size := custom ? validate(sizeIn) :
        auto   ? "auto"          :
                 cfg.zram_size

pct  := pctIn ?: cfg.zram_percent

# auto-size resolution unchanged, except it consults the resolved $pct
# (live or fallback) instead of cfg['zram_percent'] directly.
```

Validation:
- `size_mode` must be `auto` or `custom`. Anything else → fall back to saved.
- Custom `sizeIn` must match `/^\d+\s*[GMT]$/i`. Failure → 400-style JSON
  error: "Invalid custom size format (use 4G, 512M, etc.)".
- `pctIn` is filtered with `FILTER_VALIDATE_INT` clamped 25–75 (matches the
  existing slider range and the existing server-side clamp at the form save
  path, `UnraidZramCard.page:26`).
- `algo` is validated against the live `/sys/block/zram*/comp_algorithm`
  list. If no zram device exists yet (cold start before module load), fall
  back to a static allow-list of `[zstd, lz4, lzo, deflate]`. Mismatch →
  fall back to `cfg.zram_algo`. We do not error out, because the page render
  derives its dropdown from the same live source — a mismatch here would
  mean the page lied to the user, not that the user is doing something
  malicious.

After successful `swapon`, persist the actually-used values:

```
zram_config_write({
    zram_algo:    algo,
    zram_size:    sizeMode == 'custom' ? sizeIn : 'auto',
    zram_percent: pct,
})
```

This replaces the prior `zram_size: $cfg['zram_size']` (which wrote back the
*old* value) and adds the missing `zram_percent` key.

### Frontend

**`src/js/zram-settings.js` — CREATE click**

A small helper reads the current Tier 1 form state and returns a query string
suitable for `zramAction`'s `extra` parameter:

```js
function buildCreateZramParams() {
    var mode  = document.getElementById('zram_size_mode').value;
    var algo  = document.getElementById('zram_algo_select').value;
    var pct   = document.getElementById('zram_percent_slider').value;
    var size  = document.getElementById('zram_custom_size').value;
    var p = 'size_mode=' + encodeURIComponent(mode)
          + '&algo='     + encodeURIComponent(algo);
    if (mode === 'auto')   p += '&percent=' + encodeURIComponent(pct);
    if (mode === 'custom') p += '&size='    + encodeURIComponent(size);
    return p;
}
```

The CREATE button's `onclick` changes from `zramAction('create_zram')` to
`zramAction('create_zram', buildCreateZramParams())`.

The existing `syncFormValues()` (used by APPLY & SAVE) is unchanged — both
paths now persist the same keys, but via different submission mechanisms.

### Boot init

`src/zram_init.sh` is unchanged. It reads `zram_size`, `zram_percent`, and
`zram_algo` from `settings.ini` exactly as before. The fix here is at the
*write* end: every CREATE now persists those keys, so init-script consumers
see correct values without any code change on their side.

## Settings

No new keys. The fix exposes existing keys (`zram_percent` was already in
`ZRAM_DEFAULTS`) to a code path that previously didn't update them.

## Edge Cases

- **User clicks CREATE without touching the form.** All controls hold their
  defaults from the page render, which were sourced from the saved config.
  Effective behaviour is identical to today: persist same as saved, create
  same as saved. No regression.
- **User selects custom mode but leaves the size box empty.** Validation
  rejects with "Invalid custom size format". No silent fall-through to
  whatever the previous save had — that would be a worse UX than the user's
  current "always 16G" complaint.
- **User picks an algorithm not present in the kernel's allow-list (e.g.
  by hand-editing the dropdown in DevTools).** Action falls back to saved
  algo. No error, but a debug-log entry records the rejected value.
- **Init script runs before the user has ever clicked CREATE in this
  version.** `settings.ini` may have a `zram_percent` from before this fix
  was deployed — that key already has a default in `ZRAM_DEFAULTS` (`50`),
  so init falls back gracefully. No migration needed.
- **`zramctl --find` allocates a different device than expected.** Unchanged
  by this fix — the device is whatever `--find` returns, the saved values
  describe the size/algo, not the device path.

## Verification

### Unit (PHPUnit)

Add `tests/php/CreateZramLiveParamsTest.php`:

- Fail if `create_zram` source no longer reads `size_mode`, `size`,
  `percent`, or `algo` from `INPUT_GET`.
- Fail if the post-success `zram_config_write` no longer writes
  `zram_percent` (regression guard for the silently-dropped key).
- Fail if `zram-settings.js` no longer constructs the create-time query
  string (textual check on `buildCreateZramParams` or its inline equivalent).

These are guard tests, not behavioural — the action handler can't run in
PHPUnit without a kernel (it shells out to `zramctl`/`mkswap`/`swapon`).

### Smoke (live server)

Existing smoke harness already covers settings POST persistence and renders.
The CREATE-from-live-form path is verified manually post-deploy:

1. Open settings page on test server.
2. Adjust percent slider to 75 %, leave size mode on auto.
3. Click CREATE without first clicking APPLY & SAVE.
4. Inspect `zramctl /dev/zram0` — disksize should be ≈ 75 % of MemTotal.
5. Inspect `/boot/config/plugins/unraid-zram-card/settings.ini` — must show
   `zram_percent="75"`.
6. Reboot test server (or just re-run `zram_init.sh`) and confirm device
   reallocates at 75 %.

### Manual scenarios

1. **Auto + 75 %** — set slider to 75 %, mode=auto, click CREATE. Created
   size ≈ 75 % of RAM. settings.ini persists `zram_percent="75"`.
2. **Custom 8G** — switch to custom, type "8G", click CREATE. Created size
   8G exactly. settings.ini persists `zram_size="8G"`.
3. **Custom invalid input** — type "8 gigabytes", click CREATE. Error toast,
   no device created, no config write.
4. **Algorithm change** — pick `lz4` from the dropdown without clicking
   APPLY & SAVE first, click CREATE. Created device runs lz4. settings.ini
   reflects `zram_algo="lz4"`.

## Out of scope

- Re-styling the CREATE / APPLY & SAVE separation in the UI. The split is
  fine once the buttons behave as labeled.
- Live preview of the resolved size next to the CREATE button (would be
  nice but is a separate UX polish concern).
- Validating `zram_size` against available memory (kernel will refuse on
  its own; we surface the error already).
