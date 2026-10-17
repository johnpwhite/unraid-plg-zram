#!/bin/bash
# install-engine.sh — zram plugin dev-deploy engine
# Called by deploy.sh with the following env already set:
#   NAME, VERSION, EMHTTP_DEST, CONFIG_DIR, GIT_URL, UPGRADE_MODE, OLD_LAYOUT_VERSION
#   LOG_FILE, log_status, log_step, log_ok, log_warn, log_fail, log_progress (functions)
set -uo pipefail

# Test seams (docs/specs/TEST_SEAMS.md): production defaults when unset.
TMP_DIR="${ZRAM_TMP_DIR:-/tmp}"
EMHTTP_ROOT="${ZRAM_EMHTTP_ROOT:-/usr/local/emhttp/plugins}"

log_step "Extracting source tarball"
# Stop here when the tarball is missing or bad. Before this check, a failed
# extract fell through to rm -rf of the working install and then a cp that
# copied nothing, and the log still said "Files installed".
if ! (cd "${TMP_DIR}" && tar -xzf aicli-src.tar.gz) || [ ! -d "${TMP_DIR}/src" ]; then
    log_fail "Could not extract ${TMP_DIR}/aicli-src.tar.gz — existing install was not changed"
    exit 1
fi
log_ok "Tarball extracted to ${TMP_DIR}/src"

log_step "Installing plugin files to ${EMHTTP_DEST}"
# Safety guard: refuse to rm -rf a path that doesn't sit squarely under the
# expected plugin tree.  A malformed env (empty var, short path, etc.) would
# otherwise silently destroy an unintended directory.
case "${EMHTTP_DEST}" in
    "${EMHTTP_ROOT}"/?*) : ;;
    *) echo "install-engine: refusing rm -rf on suspicious EMHTTP_DEST='${EMHTTP_DEST}'" >&2; exit 1 ;;
esac
rm -rf "${EMHTTP_DEST}"
if ! cp -r "${TMP_DIR}/src/." "${EMHTTP_DEST}/"; then
    log_fail "Could not copy plugin files to ${EMHTTP_DEST}"
    exit 1
fi
log_ok "Files installed"

# Stamp the layout version so deploy.sh can detect hot-swap vs full teardown
# on subsequent runs. deploy.sh reads $dest/src/.layout-version — create the
# src/ stub dir + file to satisfy that path.
mkdir -p "${EMHTTP_DEST}/src"
cp -f "${TMP_DIR}/src/.layout-version" "${EMHTTP_DEST}/src/.layout-version" 2>/dev/null || true
log_ok "Layout version stamped"

log_step "Setting file permissions"
chmod +x "${EMHTTP_DEST}/zram_init.sh"          2>/dev/null || true
chmod +x "${EMHTTP_DEST}/zram_actions.php"       2>/dev/null || true
chmod +x "${EMHTTP_DEST}/zram_status.php"        2>/dev/null || true
chmod +x "${EMHTTP_DEST}/zram_drives.php"        2>/dev/null || true
chmod +x "${EMHTTP_DEST}/zram_collector.php"     2>/dev/null || true
chmod +x "${EMHTTP_DEST}/zram_oom_apply.sh"      2>/dev/null || true
for ev in stopping disks_mounted; do
    if [ -f "${EMHTTP_DEST}/event/$ev" ]; then
        chmod +x "${EMHTTP_DEST}/event/$ev"
    fi
done
log_ok "Permissions set"

log_step "Ensuring config dir exists: ${CONFIG_DIR}"
mkdir -p "${CONFIG_DIR}"
log_ok "Config dir ready"

log_step "Writing default settings.ini (skipped if already present)"
if [ ! -f "${CONFIG_DIR}/settings.ini" ]; then
    cat > "${CONFIG_DIR}/settings.ini" << 'DEFCFG'
enabled="yes"
refresh_interval="3000"
collection_interval="3"
swappiness="100"
debug="no"
console_visible="yes"
zram_size="auto"
zram_percent="50"
zram_algo="zstd"
ssd_swap_enabled="no"
ssd_swap_path=""
ssd_swap_size="16G"
ssd_swap_mount=""
ssd_swap_backing="auto"
ssd_swap_allow_zfs="no"
oom_protect_enabled="no"
oom_levels=""
oom_default_level="normal"
oom_proc_patterns=""
oom_auto_deps=""
oom_oom_group="yes"
vm_memory_min="no"
DEFCFG
    log_ok "Default settings.ini written"
else
    log_status "Existing settings.ini preserved"
fi

log_step "Loading loop module"
modprobe loop 2>/dev/null || true
log_ok "Loop module ready"

log_step "Running zram_init.sh"
# Close deploy.sh's extra FDs (3, 4 etc.) so background processes started by
# zram_init.sh (collector, retry poller) don't inherit the SSH channel FD and
# keep the deploy SSH connection open after we exit.
if bash "${EMHTTP_DEST}/zram_init.sh" 3>/dev/null 4>/dev/null; then
    log_ok "zram_init.sh completed successfully"
else
    log_warn "zram_init.sh exited nonzero — ZRAM state may be partial"
fi

log_ok "Deploy complete: ${NAME} v${VERSION} installed to ${EMHTTP_DEST}"
log_status "Deploy finished at $(date '+%Y-%m-%d %H:%M:%S')"
