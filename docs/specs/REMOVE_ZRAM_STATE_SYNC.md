# Feature: Trustworthy ZRAM remove/create — kill silent failure and stale-cache mismatch

## Status
Approved

## Problem

Forum bug report (post-upgrade to 2026.04.28.02): user clicks REMOVE on an
active ZRAM device. The action returns success and the page reloads — but
the REMOVE button is still there. Three or four manual reloads later, the
button finally flips to CREATE. They click CREATE; it errors with
"ZRAM device already active". After several attempts they give up — only
to notice later that the dashboard *did* eventually show a healthy zram
device with the new swappiness applied.

The user's lived experience is a state-sync bug: the UI lies about whether
the device exists. The actual cause has three independent contributing
failure modes, all in `zram_actions.php` and `zram_config.php`:

1. **`remove_zram` ignores exit codes.** `swapoff` and `zramctl --reset`
   return integer status from `zram_run()`, but the caller discards both.
   Under memory pressure (heavy swap usage at the time of removal),
   `swapoff` can fail with `ENOMEM` or `EBUSY`. `zramctl --reset` then
   also fails (cannot reset an in-use device). We `unlink(device.conf)`
   and return `success=true` regardless. The kernel state is unchanged;
   our cache marker is gone.
2. **`blkid` cache lag.** `zram_get_our_device()` probes via
   `blkid -t LABEL=ZRAM_CARD -o device`. With no `-c /dev/null`, blkid
   consults `/run/blkid/blkid.tab` first. `zramctl --reset` does not fire
   a `change` udev event, so the cached label entry can persist for a
   measurable window after a *successful* reset. Page reloads in that
   window see "device still labeled" → render REMOVE.
3. **Cascade into create.** When the user finally gets a CREATE button
   and clicks it, the same stale-cache or partially-removed device is
   detected by `zram_get_our_device()` and create is rejected with
   "ZRAM device already active: /dev/zramN".

The eventual self-heal is not from the user's CREATE clicks. It is the
APPLY & SAVE settings submit (UnraidZramCard.page line 35) re-running
`zram_init.sh`, which by then sees a clean slate and creates a fresh
device with the saved swappiness. The recovery is incidental.

## Requirements

- [ ] `remove_zram` reports `success=false` when `swapoff` fails — does
      not delete `device.conf`, does not pretend success.
- [ ] `remove_zram` reports `success=false` when `zramctl --reset` fails.
- [ ] After successful `swapoff`, the swap signature is actively wiped
      from the device so blkid (and its cache, on its next refresh)
      cannot keep reporting our label on a reset device.
- [ ] `zram_get_our_device()` bypasses the blkid cache so freshness is
      guaranteed — every call reflects current on-device state, not
      a stale `/run/blkid/blkid.tab` entry.
- [ ] Existing `create_zram`, `create_ssd_swap`, and `remove_ssd_swap`
      paths keep their current contracts (already check exit codes).
- [ ] PHPUnit guard against future regression of the silent-failure
      pattern in `remove_zram`.

## Design

### User Experience

Single user-visible change: when removal genuinely fails (memory pressure
preventing `swapoff`), the action ends with a clear error toast instead
of pretending it worked. The user knows immediately that they need to
wait or reduce swap usage. No more "click REMOVE four times, then CREATE
five times" guessing game.

When removal succeeds, the page reload now reliably shows CREATE — the
blkid cache bypass means there is no longer a "labeled but reset" window
during which the page lies.

### Backend

**`src/zram_actions.php` — `remove_zram` action**

Current sequence (silent-failure pattern):

```
zram_run swapoff $devPath        — exit code discarded
zram_run zramctl --reset $devPath — exit code discarded
unlink device.conf
return success=true
```

New sequence (mirrors the create-path discipline):

```
swapoff $devPath        — guard with !== 0; on fail, return success=false
wipefs -a $devPath      — clear swap signature; logged but not fatal
zramctl --reset $devPath — guard with !== 0; on fail, return success=false
unlink device.conf
return success=true
```

Notes on the `wipefs` step:
- It runs *after* `swapoff` (so the device is not in use) and *before*
  `zramctl --reset` (so the device still has size and is writable).
- It is intentionally not gated on exit code — `wipefs` failing is rare
  and recoverable: even if the cache briefly reports the label, the
  cache-bypassed `blkid -c /dev/null` lookup in `zram_get_our_device()`
  would re-probe and find no signature once the reset has run.
- The error case is captured in `$logs` for the diagnostic console, so
  a real wipefs problem is still observable.

**`src/zram_config.php` — `zram_get_our_device()`**

Single-line change to force a fresh probe:

```
blkid -c /dev/null -t LABEL=ZRAM_CARD -o device
```

`-c /dev/null` makes blkid skip the persistent cache file and probe
devices directly. Cost is one blkid scan per call; on Unraid systems
this is microseconds (typically only a handful of zram devices to
scan, none of which require slow IO since they are RAM-backed).

The fallback path (cached `device.conf` + `/sys/block/$cached` check)
is unchanged — it remains a defence against the rare case where blkid
itself errors out.

### Frontend

No change. The 2-second `setTimeout(..., 2000)` reload in
`zram-settings.js` continues to work; the underlying server-side
detection it relies on is now trustworthy.

## Settings

None. No new config keys.

## Edge Cases

- **`swapoff` partially evacuates then fails.** Some pages move out, the
  call returns an error. Device is still in `/proc/swaps`. New behaviour:
  user sees an explicit failure message, can retry. Old behaviour: silent
  success, broken UI.
- **`wipefs` fails (device locked by another process).** Logged, not
  fatal. The next `zramctl --reset` clears the data, and the next
  `blkid -c /dev/null` re-probes the now-empty device, finds no
  signature, returns empty.
- **Device was already manually removed (e.g., admin ran swapoff/reset
  out-of-band).** `zram_get_our_device()` returns empty → `remove_zram`
  hits its existing `'No ZRAM Card device found'` early return. No
  change.
- **`zramctl --reset` succeeds but blkid cache file is owned by root and
  the web user can't write it.** The cache stays stale, but the
  `-c /dev/null` bypass means `zram_get_our_device()` no longer reads
  it. State sync is preserved either way.

## Verification

### Unit (PHPUnit)

Add `tests/php/RemoveZramSilentFailureTest.php` — a focused test that
the `remove_zram` source no longer contains the silent-failure shape.
This is a textual guard, since the actual command-execution path needs
a live kernel; the goal is to fail CI if a future refactor reverts the
fix.

The test asserts that the source contains a `!== 0` check around the
`swapoff` invocation in the `remove_zram` block, and similarly for the
`zramctl --reset` invocation.

### Smoke (live server)

Existing `tests/smoke.sh` already covers dashboard render and settings
POST persistence. The remove/create lifecycle is not in smoke (would
require destructive state changes); the fix is verified manually
against the test server post-deploy by:

1. Confirm zram device active on dashboard.
2. Click REMOVE on settings page. Reload immediately.
3. Page must show CREATE (not REMOVE) on first reload after success
   message clears.
4. Click CREATE. Device must allocate without "already active" error.

### Manual scenarios

1. **Happy path** — swap is empty, REMOVE → CREATE round-trips cleanly
   on first reload.
2. **Loaded zram** — fill zram with `dd if=/dev/zero of=/tmp/x bs=1M
   count=2048` (in tmpfs to force swap pressure), then REMOVE. Either
   succeeds in one click, or reports an honest swapoff failure.
3. **Forced ghost device** — manually `swapoff /dev/zram0` out-of-band
   so it is labeled but inactive, then click REMOVE. Should still
   succeed (swapoff is a no-op on inactive swap; reset still clears).

## Out of scope

- Replacing the 2-second JS reload delay with a polling check on actual
  state (would mask, not fix, the underlying race; current fix removes
  the race).
- Moving zram lifecycle to a single PHP function (the action handlers
  are simple enough; abstraction would not improve correctness).
- Hardening `zram_evacuation_safe()` to re-read MemAvailable immediately
  before swapoff. Listed as #5 in the original investigation. Deferred —
  the safety check is still useful as a *pre-flight* warning, and the
  swapoff-exit-code check now gives an honest post-flight result. Adding
  the recheck is a separate concern (better failure prediction, not
  state-sync correctness).
