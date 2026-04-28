#!/bin/bash
# smoke.sh — post-deploy integration test for unraid-zram-card.
#
# Runs ON the test server (scp'd there by the deploy wrapper). Exits 0 on
# all-pass; non-zero per assertion for easy reporting. Never touches plugin
# state — rollback is the caller's responsibility.
#
# Exit codes:
#   0   all assertions pass
#   1   infrastructure error (missing tool, unwritable tmp, etc.)
#   2   assertion 1 failed: dashboard PHP renders without errors or sentinel leaks
#   3   assertion 2 failed: zram_status.php JSON shape
#   4   assertion 3 failed: settings form POST persists
#   5   assertion 4 failed: cache-buster uses dynamic value
#   6   assertion 5 failed: collector process alive
#   7   assertion 6 failed: history.json has new-schema entries
#   8   assertion 7 failed: plugin icon asset present and readable
#   9   assertion 8 failed: inactive-state renders gracefully

set -u
PLG="unraid-zram-card"
PLUGIN_DIR="/usr/local/emhttp/plugins/${PLG}"
CONFIG_FILE="/boot/config/plugins/${PLG}/settings.ini"
HISTORY_FILE="/tmp/${PLG}/history.json"
PID_FILE="/tmp/${PLG}/collector.pid"

# Support overriding the host for remote invocation; default to localhost since
# the script runs on the server itself.
HOST="${SMOKE_HOST:-localhost}"

fail() {
    local code=$1; shift
    echo "SMOKE FAIL [$code]: $*" >&2
    exit "$code"
}

command -v curl >/dev/null 2>&1 || fail 1 "curl not available"
command -v php  >/dev/null 2>&1 || fail 1 "php not available"
command -v jq   >/dev/null 2>&1 || echo "WARN: jq not available — JSON checks use grep fallback" >&2

# ---- Assertion 1: Dashboard page renders without PHP errors or leaked sentinel values ----
DASH_ERR=$(mktemp)
DASH_OUT=$(mktemp)
php "${PLUGIN_DIR}/UnraidZramDash.page" >"$DASH_OUT" 2>"$DASH_ERR"
if grep -qiE '(PHP (Fatal|Warning|Notice|Deprecated)|Parse error|Uncaught)' "$DASH_ERR"; then
    cat "$DASH_ERR" >&2
    rm -f "$DASH_ERR" "$DASH_OUT"
    fail 2 "UnraidZramDash.page emitted PHP errors"
fi
# Scan rendered HTML for sentinel values that mean something didn't compute.
# Constrained regex: only flag these when they appear as a value in a tag (between
# > and <) or as a lone word — NOT substrings in class names, CSS, or JS code.
if grep -Eq '>(undefined|NaN|null)[[:space:]]*<' "$DASH_OUT"; then
    grep -nE '>(undefined|NaN|null)[[:space:]]*<' "$DASH_OUT" >&2
    rm -f "$DASH_ERR" "$DASH_OUT"
    fail 2 "UnraidZramDash.page rendered sentinel value (undefined/NaN/null) as visible text"
fi
rm -f "$DASH_ERR" "$DASH_OUT"
echo "  [1/8] Dashboard renders clean (no PHP notices, no sentinel leaks)"

# ---- Assertion 2: Status JSON shape (via PHP CLI, bypassing nginx auth) ----
STATUS_JSON=$(php -d display_errors=0 -r "
    \$_SERVER['DOCUMENT_ROOT']='/usr/local/emhttp';
    chdir('${PLUGIN_DIR}');
    require '${PLUGIN_DIR}/zram_status.php';
" 2>/dev/null)

if [ -z "$STATUS_JSON" ]; then
    fail 3 "zram_status.php produced no output via CLI"
fi

if command -v jq >/dev/null 2>&1; then
    for key in '.aggregates.total_original' '.aggregates.total_used' '.aggregates.compression_ratio'; do
        echo "$STATUS_JSON" | jq -e "$key != null" >/dev/null \
            || fail 3 "zram_status.php missing required key: $key"
    done
else
    echo "$STATUS_JSON" | grep -q '"total_original"' || fail 3 "missing total_original"
    echo "$STATUS_JSON" | grep -q '"total_used"'     || fail 3 "missing total_used"
    echo "$STATUS_JSON" | grep -q '"compression_ratio"' || fail 3 "missing compression_ratio"
fi
echo "  [2/8] Status JSON has required keys"

# ---- Assertion 3: Settings form POST persists (regression guard: 2026.04.17.02) ----
# Invokes the .page save handler via PHP CLI with faked $_POST/$_SERVER, bypassing
# nginx auth. Backs up the current config and restores it after the test so we
# don't leave random state behind.
CFG_BACKUP=""
if [ -f "$CONFIG_FILE" ]; then
    CFG_BACKUP=$(mktemp)
    cp "$CONFIG_FILE" "$CFG_BACKUP"
    # Restore on exit, regardless of pass/fail
    trap 'if [ -n "$CFG_BACKUP" ] && [ -f "$CFG_BACKUP" ]; then cp "$CFG_BACKUP" "'"$CONFIG_FILE"'"; rm -f "$CFG_BACKUP"; fi' EXIT
fi

MTIME_BEFORE=$(stat -c %Y "$CONFIG_FILE" 2>/dev/null || echo 0)
TEST_INTERVAL=$(( (RANDOM % 3000) + 1000 ))  # 1000-3999 ms — distinct from any likely real value
sleep 1  # ensure mtime granularity (stat -c %Y is seconds)

php -d display_errors=0 -r "
    \$_SERVER['DOCUMENT_ROOT']='/usr/local/emhttp';
    \$_SERVER['REQUEST_METHOD']='POST';
    \$_POST = [
        'save_settings'       => '1',
        'enabled'             => 'yes',
        'refresh_interval'    => '${TEST_INTERVAL}',
        'collection_interval' => '3',
        'swappiness'          => '175',
        'zram_size'           => 'auto',
        'zram_percent'        => '50',
        'zram_algo'           => 'zstd',
    ];
    \$var = ['csrf_token' => ''];
    chdir('${PLUGIN_DIR}');
    ob_start();
    require '${PLUGIN_DIR}/UnraidZramCard.page';
    ob_end_clean();
" 2>/dev/null

MTIME_AFTER=$(stat -c %Y "$CONFIG_FILE" 2>/dev/null || echo 0)
PERSISTED_INTERVAL=$(grep -m1 'refresh_interval=' "$CONFIG_FILE" 2>/dev/null | cut -d'"' -f2)

if [ "$MTIME_AFTER" -le "$MTIME_BEFORE" ]; then
    fail 4 "settings.ini mtime did not advance after simulated POST — save handler broken"
fi
if [ "$PERSISTED_INTERVAL" != "$TEST_INTERVAL" ]; then
    fail 4 "refresh_interval did not persist correctly (wrote ${TEST_INTERVAL}, read ${PERSISTED_INTERVAL})"
fi
echo "  [3/8] Settings POST persists (refresh_interval=${TEST_INTERVAL} written, restoring)"

# Explicit restore here too, in case trap is skipped by later assertion failure
if [ -n "$CFG_BACKUP" ] && [ -f "$CFG_BACKUP" ]; then
    cp "$CFG_BACKUP" "$CONFIG_FILE"
fi

# ---- Assertion 4: Cache-buster uses dynamic value (regression guard: 2026.04.17.02) ----
DASH_HTML=$(curl -sf "http://${HOST}/Dashboard" 2>/dev/null || true)
# Look for ?v= parameter on zram-card.js. Must be digits (filemtime), not a calendar string.
VBUST=$(echo "$DASH_HTML" | grep -oE 'zram-card\.js\?v=[^"]+' | head -1 || true)
if [ -z "$VBUST" ]; then
    # Dashboard route may require auth; fall back to rendering the card directly
    CARD_HTML=$(php -r "
        \$_SERVER['DOCUMENT_ROOT']='/usr/local/emhttp';
        require_once '${PLUGIN_DIR}/ZramCard.php';
        echo getZramDashboardCard();
    " 2>/dev/null)
    VBUST=$(echo "$CARD_HTML" | grep -oE 'zram-card\.js\?v=[^"]+' | head -1 || true)
fi
if [ -z "$VBUST" ]; then
    fail 5 "could not locate zram-card.js cache-buster in rendered output"
fi
case "$VBUST" in
    *"?v=20"[0-9][0-9].[0-9][0-9].[0-9][0-9]*)
        fail 5 "cache-buster is a hardcoded calendar version: $VBUST — should be filemtime()"
        ;;
esac
echo "  [4/8] Cache-buster is dynamic: $VBUST"

# ---- Assertion 5: Collector process alive ----
# Assertion 3's save handler restarts the collector (benign side effect). Poll
# up to ~6s for the new PID to settle before declaring failure.
COLL_PID=""
for attempt in 1 2 3 4 5 6; do
    if [ -f "$PID_FILE" ]; then
        COLL_PID=$(cat "$PID_FILE" 2>/dev/null)
        if [ -n "$COLL_PID" ] && kill -0 "$COLL_PID" 2>/dev/null; then
            break
        fi
    fi
    COLL_PID=""
    sleep 1
done
if [ -z "$COLL_PID" ]; then
    fail 6 "collector did not come back alive within 6s (PID file: $(cat "$PID_FILE" 2>/dev/null || echo missing))"
fi
echo "  [5/8] Collector alive (PID $COLL_PID)"

# ---- Assertion 6: History has new-schema entries ----
if [ ! -s "$HISTORY_FILE" ]; then
    echo "  [6/8] history.json empty — skipping schema check (collector may have just started)"
else
    if command -v jq >/dev/null 2>&1; then
        NEW_COUNT=$(jq '[.[] | select(.o != null and .u != null)] | length' "$HISTORY_FILE")
        if [ "$NEW_COUNT" -lt 1 ]; then
            fail 7 "history.json has no new-schema {o,u,l} entries — collector writing legacy format?"
        fi
        echo "  [6/8] history.json has $NEW_COUNT new-schema entries"
    else
        grep -q '"o":' "$HISTORY_FILE" || fail 7 "history.json has no 'o' field — legacy schema?"
        echo "  [6/8] history.json has new-schema entries (grep fallback)"
    fi
fi

# ---- Assertion 7: Plugin icon asset present and readable ----
# Catches the case where the .plg FILE manifest drops the icon, or the install
# hook puts it in the wrong place. Note: file-present is necessary but NOT
# sufficient — L4 verifies the icon actually renders (naturalWidth > 0).
ICON_PATH="${PLUGIN_DIR}/unraid-zram-card.png"
if [ ! -f "$ICON_PATH" ]; then
    fail 8 "plugin icon missing on server: $ICON_PATH"
fi
ICON_SIZE=$(stat -c %s "$ICON_PATH" 2>/dev/null || echo 0)
if [ "$ICON_SIZE" -lt 1024 ]; then
    fail 8 "plugin icon suspiciously small ($ICON_SIZE bytes): $ICON_PATH"
fi
# PNG magic bytes: 89 50 4E 47
MAGIC=$(head -c 4 "$ICON_PATH" 2>/dev/null | od -An -tx1 | tr -d ' \n')
if [ "$MAGIC" != "89504e47" ]; then
    fail 8 "plugin icon is not a valid PNG (magic=$MAGIC)"
fi
echo "  [7/8] Icon file valid ($ICON_SIZE bytes, PNG magic ok)"

# ---- Assertion 8: Dashboard renders gracefully in "no ZRAM device" state ----
# Forces the inactive path via PHP CLI with stubbed constants so the plugin's
# device-lookup returns empty without touching the real kernel zram device.
# The render must: (a) emit no PHP notices, (b) contain the graceful
# "No ZRAM devices active" fallback text, (c) leak no undefined/NaN/null.
INACTIVE_ERR=$(mktemp)
INACTIVE_HTML=$(php -d display_errors=0 -r "
    define('ZRAM_LABEL', 'ZRAM_CARD_SMOKE_NO_SUCH_LABEL');
    define('ZRAM_SSD_LABEL', 'ZRAM_CARD_SMOKE_NO_SUCH_SSD');
    define('ZRAM_DEVICE_FILE', '/tmp/zram-smoke-nonexistent-device-file');
    \$_SERVER['DOCUMENT_ROOT']='/usr/local/emhttp';
    require_once '${PLUGIN_DIR}/zram_config.php';
    require_once '${PLUGIN_DIR}/ZramCard.php';
    echo getZramDashboardCard();
" 2>"$INACTIVE_ERR")

if grep -qiE '(PHP (Fatal|Warning|Notice|Deprecated)|Parse error|Uncaught)' "$INACTIVE_ERR"; then
    cat "$INACTIVE_ERR" >&2
    rm -f "$INACTIVE_ERR"
    fail 9 "Inactive-state render emitted PHP errors"
fi
if ! echo "$INACTIVE_HTML" | grep -qE 'No ZRAM devices active'; then
    echo "--- inactive render output (first 40 lines) ---" >&2
    echo "$INACTIVE_HTML" | head -40 >&2
    rm -f "$INACTIVE_ERR"
    fail 9 "Inactive-state render did not show 'No ZRAM devices active' fallback"
fi
if echo "$INACTIVE_HTML" | grep -Eq '>(undefined|NaN|null)[[:space:]]*<'; then
    rm -f "$INACTIVE_ERR"
    fail 9 "Inactive-state render leaked sentinel values (undefined/NaN/null)"
fi
rm -f "$INACTIVE_ERR"
echo "  [8/8] Inactive-state renders gracefully (fallback visible, no errors, no sentinel leaks)"

echo "SMOKE PASS"
exit 0
