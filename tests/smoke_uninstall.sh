#!/bin/bash
# smoke_uninstall.sh — destructive test of the plugin uninstall → reinstall cycle.
#
# Runs ON the test server. Removes the zram plugin, asserts clean teardown,
# then reinstalls and asserts clean restore. Uses a trap so the plugin WILL
# be reinstalled even if an assertion fails mid-flight (unless the snapshot
# PLG itself was lost — in which case manual recovery is required).
#
# NOT part of the per-publish loop. Invoke manually, or before a storefront
# release, when you can tolerate ~20 seconds of plugin downtime on the test
# server.
#
# Usage (from dev machine):
#   scp tests/smoke_uninstall.sh root@<ip>:/tmp/zram-uninstall-smoke.sh
#   ssh root@<ip> "bash /tmp/zram-uninstall-smoke.sh"
#
# Exit codes:
#   0   uninstall + reinstall + all assertions pass
#   1   infrastructure error (no plg to work with, commands missing)
#   2   PLG snapshot failed — test aborted before removal
#   3   plugin remove command failed
#   4   cleanup assertion failed (collector / tmp / plugin dir)
#   5   plugin reinstall failed — MANUAL RECOVERY REQUIRED
#   6   post-reinstall assertion failed (plugin dir / version mismatch)
#   7   unexpected: flash config was destroyed (user settings lost by remove)

set -u
PLG="unraid-zram-card"
PLG_FILE="${PLG}.plg"
PLUGIN_DIR="/usr/local/emhttp/plugins/${PLG}"
PLG_ARTEFACT="/var/log/plugins/${PLG_FILE}"
TMP_PLG_COPY="/tmp/zram-smoke-reinstall.plg"
TMP_DIR="/tmp/${PLG}"
CONFIG_DIR="/boot/config/plugins/${PLG}"

fail() {
    local code=$1; shift
    echo "UNINSTALL SMOKE FAIL [$code]: $*" >&2
    exit "$code"
}

command -v plugin >/dev/null 2>&1 || fail 1 "Unraid 'plugin' command not available"

# ---- Pre-check: plugin currently installed and PLG snapshot available ----
if [ ! -d "$PLUGIN_DIR" ]; then
    fail 1 "Plugin not currently installed: $PLUGIN_DIR missing. Nothing to uninstall."
fi
if [ ! -f "$PLG_ARTEFACT" ]; then
    fail 2 "Installed PLG not at $PLG_ARTEFACT — cannot snapshot for reinstall"
fi

# Take a safe copy of the PLG. Recovery will reinstall from this file.
cp "$PLG_ARTEFACT" "$TMP_PLG_COPY" 2>/dev/null || fail 2 "Could not snapshot PLG to $TMP_PLG_COPY"
INSTALLED_VERSION=$(grep -oE '<!ENTITY version\s+"[^"]+"' "$TMP_PLG_COPY" | head -1 | sed 's/.*"\([^"]*\)".*/\1/')
echo "  Snapshot: $TMP_PLG_COPY (version $INSTALLED_VERSION)"

# Trap: always attempt reinstall on exit, even if asserts fail. Tries the
# canonical pluginURL first (populates /var/log/plugins/ via the symlink
# mechanism), falls back to the local snapshot if URL install fails.
recover() {
    local orig_exit=$?
    if [ ! -d "$PLUGIN_DIR" ]; then
        echo "  [recover] Plugin removed — attempting reinstall..."
        # Try URL first (matches user experience, populates /var/log/plugins symlink)
        local URL=""
        if [ -f "$TMP_PLG_COPY" ]; then
            URL=$(grep -oE 'pluginURL="[^"]+"' "$TMP_PLG_COPY" | head -1 | sed 's/.*"\([^"]*\)".*/\1/')
        fi
        if [ -n "$URL" ] && plugin install "$URL" >/dev/null 2>&1; then
            echo "  [recover] Reinstall succeeded via pluginURL."
        elif [ -f "$TMP_PLG_COPY" ] && plugin install "$TMP_PLG_COPY" >/dev/null 2>&1; then
            echo "  [recover] Reinstall succeeded via local snapshot."
        else
            echo "  [recover] RECOVERY FAILED — plugin is uninstalled." >&2
            [ -n "$URL" ] && echo "  [recover] Try manually: plugin install $URL" >&2
            [ -f "$TMP_PLG_COPY" ] && echo "  [recover] Or: plugin install $TMP_PLG_COPY" >&2
        fi
    fi
    exit $orig_exit
}
trap recover EXIT

# ---- Stage A: Uninstall ----
echo "  [A] Removing $PLG_FILE ..."
plugin remove "$PLG_FILE" >/tmp/zram-remove.log 2>&1 || {
    cat /tmp/zram-remove.log >&2
    fail 3 "plugin remove failed — see /tmp/zram-remove.log"
}
sleep 1

# ---- Stage B: Post-uninstall assertions ----
echo "  [B] Asserting clean teardown..."

# B.1: plugin directory gone
if [ -d "$PLUGIN_DIR" ]; then
    fail 4 "plugin dir still present after remove: $PLUGIN_DIR"
fi
echo "    [B.1] plugin dir removed"

# B.2: collector process not running (was at PID in $PID_FILE before removal)
#      We can't check the old PID since the file is likely gone, so check by name
if pgrep -f "zram_collector.php" >/dev/null 2>&1; then
    LEAK_PIDS=$(pgrep -f "zram_collector.php" | tr '\n' ' ')
    fail 4 "zram_collector.php still running after uninstall: PIDs=$LEAK_PIDS"
fi
echo "    [B.2] collector processes stopped"

# B.3: /tmp working dir cleaned up
if [ -d "$TMP_DIR" ]; then
    # Not fatal — some plugins leave logs intentionally — but at least PID file should go
    if [ -f "$TMP_DIR/collector.pid" ]; then
        fail 4 "stale collector.pid in $TMP_DIR after uninstall"
    fi
    echo "    [B.3] tmp dir retained but collector.pid cleaned"
else
    echo "    [B.3] tmp dir fully removed"
fi

# B.4: /boot/config preserved (user settings should survive uninstall)
# Unraid convention: plugin uninstall should NOT destroy user settings.
# A reinstall should pick up the user's prior config.
if [ ! -d "$CONFIG_DIR" ]; then
    fail 7 "$CONFIG_DIR was destroyed by uninstall — user settings are lost across uninstall/reinstall. Check the PLG remove hook."
fi
echo "    [B.4] flash config preserved at $CONFIG_DIR (user settings survive)"

# B.5: ZRAM kernel device — plugin should deactivate OUR zram device
# We can't easily know which was "ours" post-uninstall, but we can check that
# no device with our label exists
if command -v blkid >/dev/null 2>&1; then
    if blkid -t LABEL=ZRAM_CARD -o device 2>/dev/null | grep -q .; then
        fail 4 "ZRAM device with ZRAM_CARD label still active after uninstall"
    fi
    echo "    [B.5] no ZRAM_CARD-labelled device remains"
fi

# ---- Stage C: Reinstall ----
# Prefer reinstalling via the canonical pluginURL — this matches what a real
# user does via the WebGUI, and populates /var/log/plugins/ correctly. Fall
# back to the local snapshot only if URL install fails (no network / GitLab
# unreachable).
echo "  [C] Reinstalling ($INSTALLED_VERSION)..."
PLUGIN_URL=$(grep -oE 'pluginURL="[^"]+"' "$TMP_PLG_COPY" | head -1 | sed 's/.*"\([^"]*\)".*/\1/')
REINSTALL_SOURCE=""
if [ -n "$PLUGIN_URL" ] && plugin install "$PLUGIN_URL" >/tmp/zram-reinstall.log 2>&1; then
    REINSTALL_SOURCE="pluginURL ($PLUGIN_URL)"
elif plugin install "$TMP_PLG_COPY" >/tmp/zram-reinstall.log 2>&1; then
    REINSTALL_SOURCE="local snapshot ($TMP_PLG_COPY)"
else
    cat /tmp/zram-reinstall.log >&2
    trap - EXIT
    fail 5 "plugin install failed via URL and local snapshot — MANUAL RECOVERY REQUIRED. PLG at $TMP_PLG_COPY"
fi
sleep 2
echo "    reinstalled from: $REINSTALL_SOURCE"

# ---- Stage D: Post-reinstall assertions ----
echo "  [D] Asserting clean restore..."
if [ ! -d "$PLUGIN_DIR" ]; then
    fail 6 "plugin dir not restored after reinstall: $PLUGIN_DIR"
fi
# The definitive post-install artefact that always exists: the installed .page
# file contains Title= line which must match. Version check uses the PLG in
# place if URL-installed (populated) or the /usr/local/emhttp copy if bundled.
INSTALLED_PLG_CANDIDATES=("$PLG_ARTEFACT" "$PLUGIN_DIR/$PLG_FILE")
FOUND_VERSION=""
for cand in "${INSTALLED_PLG_CANDIDATES[@]}"; do
    if [ -f "$cand" ] && grep -q "$INSTALLED_VERSION" "$cand" 2>/dev/null; then
        FOUND_VERSION="$cand"
        break
    fi
done
if [ -z "$FOUND_VERSION" ]; then
    fail 6 "reinstalled plugin version $INSTALLED_VERSION not confirmed at any of: ${INSTALLED_PLG_CANDIDATES[*]}"
fi
echo "    [D.1] plugin dir restored, version $INSTALLED_VERSION confirmed at $FOUND_VERSION"

# Clear the trap — we completed successfully, no need for recovery
trap - EXIT

echo "UNINSTALL SMOKE PASS"
exit 0
