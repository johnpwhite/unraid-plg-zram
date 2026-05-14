# Feature: Install-script hardening + non-destructive uninstall

## Status
Approved

## Problem

The `.plg`'s `<INLINE>` install script silently produces a 0-byte-stub plugin on transient network failures, and its remove script destroys user data on uninstall. Both bit a live test server on 2026-05-14 (see `30-…` arc).

### The 0-byte stub failure mode

Current per-file fetch:
```bash
if ! wget -q -O "$EMHTTP_DEST/$DEST" "$GIT_URL/$SRC"; then
    echo "ERROR: Download failed for $DEST"
fi
# loop continues; no abort, no cleanup
```

Three compounding problems:
1. **`wget -O <dest>` truncates `<dest>` to 0 bytes before fetching.** If the fetch fails (404, DNS, network blip, gitlab container restart, etc.), `<dest>` is left as a 0-byte stub. Same name as the real file — nothing downstream can distinguish "this file is supposed to be empty" from "this file failed to download." Classic POSIX gotcha.
2. **The loop continues on per-file failure** — just `echo`s "ERROR" and proceeds.
3. **No post-install sanity gate** — Plugin Manager sees the script exit 0 and marks the install complete.

Result: the install "succeeds" with empty files. `UnraidZramCard.page` is 0 bytes → Unraid webgui logs `Invalid .page format: …` on every render and the Plugins page shows the plugin "partially installed." On 2026-05-14, this happened because the CA plugin auto-updater triggered a reinstall while gitlab.johnpwhite.com was momentarily unreachable. Manual repair required ([repair script in /tmp during the incident](#); the actual fix is here in the .plg).

### The destructive uninstall

The remove script:
```bash
if [ -n "$SSD_PATH" ] && [ -f "$SSD_PATH" ]; then
    echo "Removing swap file: $SSD_PATH"
    rm -f "$SSD_PATH"
fi
```

`rm`'s the user's Tier 2 disk swap file as part of uninstall. This means:
- Uninstall → reinstall (a common "fix it via the UI" flow) **silently destroys 16 GB+ of user-configured swap**.
- Any future Plugin Manager update that internally does `remove`-then-`install` (which Unraid does for some failed-state recovery paths) wipes the user's swap file.

This contradicts the Unraid convention captured in `.claude/docs/unraid-patterns/HYBRID_PERSISTENCE.md`: user data survives uninstall/reinstall. `settings.ini` already does (it's on `/boot/config/plugins/<name>/`); the Tier 2 swap file is user data of similar weight and should too.

## Requirements

- [ ] Install script fetches each file to a temp location, validates non-empty, **then** mv's into place. No 0-byte stubs reach `$EMHTTP_DEST` on failure.
- [ ] Install script accumulates per-file failures into a `$FAILED` counter and **`exit 1`s** at the end of the loop if any occurred. Plugin Manager sees the install fail loudly.
- [ ] `wget` retries transient errors (`--tries=3 --timeout=15`) — so a brief network blip doesn't fail the whole install.
- [ ] Post-install **belt-and-braces sanity gate**: `find "$EMHTTP_DEST" -type f -size 0` → if anything, `exit 1` with a clear message.
- [ ] Remove script **no longer `rm`s the Tier 2 swap file**. `swapoff` stays; the file persists across uninstall/reinstall. (User can REMOVE via the Tier 2 card pre-uninstall if they actually want it gone.)
- [ ] Remove script still cleans up the in-memory zram device and the plugin's emhttp dir (both ephemeral RAM-side state, safe to wipe).

## Design

### Install script (`<INLINE>` inside the install `<FILE>` block in `unraid-zram-card.plg`)

```bash
FAILED=0
for mapping in "${FILES[@]}"; do
    DEST="${mapping%%:*}"
    SRC="${mapping##*:}"
    # … local-source fallback unchanged (still safe, still cheap) …
    if [ -z "$FOUND" ]; then
        # Fetch to temp first, validate non-empty, then mv. The old pattern
        # `wget -q -O "$EMHTTP_DEST/$DEST"` truncates the destination before
        # the fetch starts, so a transient network failure (DNS, 404, gitlab
        # restart) leaves a 0-byte stub at the real destination — masquerading
        # as a successful install. See INSTALL_HARDENING.md.
        TMP=$(mktemp)
        if wget -q --tries=3 --timeout=15 -O "$TMP" "$GIT_URL/$SRC" && [ -s "$TMP" ]; then
            mkdir -p "$(dirname "$EMHTTP_DEST/$DEST")"
            mv "$TMP" "$EMHTTP_DEST/$DEST"
        else
            SIZE=$(stat -c %s "$TMP" 2>/dev/null || echo 0)
            echo "ERROR: Download failed for $DEST (got $SIZE bytes from $GIT_URL/$SRC)"
            rm -f "$TMP"
            FAILED=$((FAILED+1))
        fi
    fi
done

# … chmod block unchanged …

if [ "$FAILED" -gt 0 ]; then
    echo "FATAL: $FAILED file(s) failed to install. Plugin install incomplete — Unraid will flag this."
    exit 1
fi

# Belt-and-braces: catch any future bug that creates an empty file without
# tripping the FAILED counter (e.g. a chmod against a path that vanished).
EMPTY=$(find "$EMHTTP_DEST" -type f -size 0 2>/dev/null)
if [ -n "$EMPTY" ]; then
    echo "FATAL: zero-byte files found post-install:"
    echo "$EMPTY"
    exit 1
fi
```

We loop through ALL files (don't abort on first failure) so the install log shows every failed file for diagnostics — a partial-network-blip scenario might fail 3 of 14, and that's useful to know on the first attempt instead of bisecting via repeated reinstalls.

### Remove script (`<INLINE>` inside the remove `<FILE>` block)

Drop the `rm -f "$SSD_PATH"` block. Keep:
- `swapoff "$SSD_PATH"` (deactivate from kernel)
- `swapoff /dev/zramN` + `zramctl --reset` (in-memory state — safe to wipe)
- `rm -rf "$PLUGIN_DIR"` (emhttp dir — RAM-side, regenerated by install)

Add a one-line comment noting the swap file is preserved deliberately.

### Why not stage-and-atomic-mv the whole directory?

The "proper" atomic install would: stage everything into `$EMHTTP_DEST.staging`, validate, then `mv -T` over `$EMHTTP_DEST`. More resilient (the half-installed state is invisible to anyone reading `$EMHTTP_DEST` mid-install). But:
- Adds complexity (mv -T directory semantics, cleanup-on-failure).
- The current "individual fetch-to-temp" already eliminates 0-byte stubs.
- Atomic dir-replace is overkill for a plugin install that runs once on update, not while the plugin is being read by other processes.

Deferred. Revisit if the per-file approach proves insufficient.

## Settings
None.

## Edge Cases

- **Partial network blip** (some files succeed, some fail) → `exit 1`, plugin marked failed, user retries. Files that succeeded remain in `$EMHTTP_DEST` from the partial run, but the next install starts with `rm -rf "$EMHTTP_DEST"` so there's no carryover.
- **`mktemp` fails** (rootfs full) → `wget` to empty string fails → branch hits the FAILED counter. Self-protecting.
- **`mv $TMP $DEST` fails** (rootfs full, disk error) → `mv` returns non-zero, but the conditional `&&` already evaluated the wget+`-s` check first, so the failed `mv` would still increment $FAILED. *Wait — actually no:* the current shape is `if wget && [ -s ]; then mv; else ... ; fi`. If `mv` fails, it's swallowed. Mitigation: use `if wget && [ -s "$TMP" ] && mv "$TMP" "$EMHTTP_DEST/$DEST"; then …`. Add the `mv` to the success guard.
- **Remove → user expected the swap file gone** → not a real loss; the file is at `/mnt/swap/.swap/zram-card.swap` (visible), 16 GB, easy to `rm` manually if desired. The CHANGES line in the next release should call this behaviour change out so anyone uninstalling-to-clean knows.
- **Cycle: uninstall, then reinstall a different version with incompatible settings.ini schema** — out of scope for this WP; `settings.ini` already survives uninstall and we already have config-migration logic in `zram_init.sh`.

## Verification

- `tests/php/InstallScriptHardeningTest.php` — source-inspection (codebase convention):
  - `.plg` install block contains `mktemp`, `[ -s "$TMP" ]`, `mv "$TMP"`.
  - `.plg` install block contains `--tries=3` and `--timeout=15` on the wget.
  - `.plg` install block has `FAILED=$((FAILED+1))` and `exit 1` when `$FAILED > 0`.
  - `.plg` install block has a post-install `find … -size 0` sanity check that `exit 1`s.
  - `.plg` remove block contains `swapoff "$SSD_PATH"` AND does NOT contain `rm -f "$SSD_PATH"` (or equivalent unlink).
- Live verification: factory publish to .4 → the new install script runs as part of the deploy → smoke (9 assertions) green → manual: `find /usr/local/emhttp/plugins/unraid-zram-card -type f -size 0` returns nothing.
- Future fault-injection test (deferred — not in this WP): temporarily block egress to gitlab, trigger reinstall, confirm `exit 1` + no 0-byte stubs left.

## Related

- `docs/specs/TIER2_RECOVERY.md` — the previous "recover from a stranded Tier 2" work; this WP closes a different (rarer but more destructive) variant of the same theme.
- `.claude/docs/unraid-patterns/HYBRID_PERSISTENCE.md` — the convention that says user data survives uninstall. This WP brings the Tier 2 swap file into compliance.
- Incident transcript 2026-05-14 — investigation of why the plugin showed "partially installed" on 192.168.1.4 (root cause discovery for this WP).
