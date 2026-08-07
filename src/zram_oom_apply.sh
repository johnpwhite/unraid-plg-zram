#!/bin/bash
# zram_oom_apply.sh — Apply OOM protection levels at boot and via libvirt hook
# Called by: zram_init.sh (boot), /etc/libvirt/hooks/qemu (VM start)
# Usage: zram_oom_apply.sh [vm_name]
#   With no argument: applies container + service levels + installs libvirt hook
#   With vm_name:     applies that VM's oom_score_adj (called from hook on started)

CONFIG="/boot/config/plugins/unraid-zram-card/settings.ini"
LOG="/tmp/unraid-zram-card/boot_init.log"
HOOK_PATH="/etc/libvirt/hooks/qemu"
HOOK_MARKER_START="# BEGIN zram-oom-protection"
HOOK_MARKER_END="# END zram-oom-protection"
PROTECTED_CGROUP_ROOT="/sys/fs/cgroup/zram-protected"

mkdir -p "$(dirname "$LOG")"

ts()      { date '+%Y-%m-%d %H:%M:%S'; }
zlog()    { echo "[$(ts)] [${2:-INFO}] $1" >> "$LOG"; }

# cfg_val KEY — read a value from settings.ini
cfg_val() {
    grep "^$1=" "$CONFIG" 2>/dev/null | cut -d'"' -f2
}

# oom_score_for_level LEVEL — echo the integer oom_score_adj for a friendly level
oom_score_for_level() {
    case "$1" in
        protected) echo -1000 ;;
        high)      echo -500  ;;
        normal)    echo 0     ;;
        low)       echo 500   ;;
        killfirst) echo 1000  ;;
        *)         echo 0     ;;
    esac
}

# oom_level_for_id ID LEVELS_STRING DEFAULT — look up level for an item id
oom_level_for_id() {
    local id="$1" levels="$2" default="$3"
    # levels format: "vm:DevBox=protected,docker:plex=low"
    # Split ONLY on commas (IFS=',') to preserve spaces in VM names
    local OLDIFS="$IFS"
    IFS=','
    for entry in $levels; do
        local k="${entry%%=*}"
        local v="${entry##*=}"
        if [ "$k" = "$id" ]; then
            IFS="$OLDIFS"
            echo "$v"
            return
        fi
    done
    IFS="$OLDIFS"
    echo "$default"
}

# write_oom_score_adj PID SCORE LABEL
write_oom_score_adj() {
    local pid="$1" score="$2" label="$3"
    if [ ! -f "/proc/$pid/oom_score_adj" ]; then
        zlog "oom_score_adj: pid $pid not found ($label)" WARN
        return 1
    fi
    if echo "$score" > "/proc/$pid/oom_score_adj" 2>/dev/null; then
        zlog "OOM: $label pid=$pid oom_score_adj=$score" INFO
        return 0
    fi
    zlog "OOM: failed to write oom_score_adj=$score for $label (pid $pid)" WARN
    return 1
}

# apply_memory_min CGROUP_DIR CAP_BYTES LABEL — reserve memory in a cgroup v2
# directory from reclaim (see docs/specs/OOM_PROTECTION.md, Forgejo #19). A
# cap is mandatory: reserving without one starves the rest of the box and can
# *cause* the OOM it was meant to prevent, so callers must always pass a
# concrete byte figure (VM's configured RAM, a container's memory limit, or a
# protected service's RSS snapshot) — never "no limit".
apply_memory_min() {
    local cgdir="$1" cap="$2" label="$3"
    local memfile="$cgdir/memory.min"
    if [ ! -f "$memfile" ]; then
        zlog "OOM: $label — memory.min unavailable at $memfile (cgroup v1, or cgroup not yet populated)" WARN
        return 1
    fi
    if [ -z "$cap" ] || [ "$cap" -le 0 ] 2>/dev/null; then
        zlog "OOM: $label — no safe memory.min cap available, skipping (refusing to reserve unbounded memory)" WARN
        return 1
    fi
    if echo "$cap" > "$memfile" 2>/dev/null; then
        zlog "OOM: $label memory.min=$((cap / 1048576))M" INFO
        return 0
    fi
    zlog "OOM: $label — memory.min write failed (permission or cgroup v1)" WARN
    return 1
}

# vm_cgroup_dir PID — resolve a VM qemu pid's cgroup v2 directory.
vm_cgroup_dir() {
    local pid="$1" cg
    cg=$(sed -n 's#^0::##p' "/proc/$pid/cgroup" 2>/dev/null | head -1)
    cg="${cg%/emulator}"
    [ -n "$cg" ] && echo "/sys/fs/cgroup${cg%/}"
}

# vm_max_memory_bytes VM_NAME — the VM's configured max memory, in bytes.
vm_max_memory_bytes() {
    local name="$1" kb
    kb=$(virsh dominfo "$name" 2>/dev/null | awk -F: '/^Max memory:/{gsub(/[^0-9]/,"",$2); print $2; exit}')
    [ -n "$kb" ] && [ "$kb" -gt 0 ] 2>/dev/null && echo $((kb * 1024))
}

# docker_memory_limit_bytes CONTAINER_NAME — the container's configured
# memory limit in bytes, or empty if unset (0 = unlimited in Docker's model —
# there is no safe cap to reserve against, same reasoning as an unset VM max).
docker_memory_limit_bytes() {
    local name="$1" bytes
    bytes=$(docker inspect -f '{{.HostConfig.Memory}}' "$name" 2>/dev/null)
    [ -n "$bytes" ] && [ "$bytes" -gt 0 ] 2>/dev/null && echo "$bytes"
}

# protect_proc_pid PID LABEL — move a protected host-process pid into a
# dedicated per-label cgroup v2 leaf and reserve its current RSS (+10%
# headroom) from reclaim. Unlike a VM or a container with a memory limit,
# a bare host process has no configured allocation to cap against — this
# snapshots live RSS instead, which is a best-effort floor, not a true
# capacity guarantee: it only reflects usage at the moment the hook/boot
# script last ran, so a service that grows afterward is unprotected for the
# excess until the next apply. Documented in OOM_PROTECTION.md.
protect_proc_pid() {
    local pid="$1" label="$2" cgdir rss_kb cap
    cgdir="$PROTECTED_CGROUP_ROOT/$label"
    mkdir -p "$cgdir" 2>/dev/null || { zlog "OOM: proc:$label — could not create $cgdir" WARN; return 1; }
    if ! echo "$pid" > "$cgdir/cgroup.procs" 2>/dev/null; then
        zlog "OOM: proc:$label (pid $pid) — could not move into protected cgroup" WARN
        return 1
    fi
    rss_kb=$(awk '/^VmRSS:/{print $2}' "/proc/$pid/status" 2>/dev/null)
    if [ -z "$rss_kb" ] || [ "$rss_kb" -le 0 ] 2>/dev/null; then
        zlog "OOM: proc:$label (pid $pid) — could not read RSS, skipping memory.min" WARN
        return 1
    fi
    cap=$(( rss_kb * 1024 * 11 / 10 ))
    apply_memory_min "$cgdir" "$cap" "proc:$label (pid $pid)"
}

# install_hook_block HOOK_BLOCK — idempotently (re)install our block into
# $HOOK_PATH. Strips any existing zram-oom-protection block first, then
# inserts the fresh one BEFORE a trailing bare `exit` statement if the hook
# ends with one — libvirt qemu hooks conventionally end `exit 0`, and a naive
# append lands our block after it, where the shell never reaches it (Forgejo
# #16). If the hook has no trailing exit, appends at the end as before.
install_hook_block() {
    local hook_block="$1"

    mkdir -p /etc/libvirt/hooks
    if [ ! -f "$HOOK_PATH" ]; then
        printf '#!/bin/bash\n# Libvirt qemu hook\n' > "$HOOK_PATH"
    fi

    local base tmp last_line_no last_line
    base=$(mktemp)
    sed "/$HOOK_MARKER_START/,/$HOOK_MARKER_END/d" "$HOOK_PATH" > "$base"
    tmp=$(mktemp)

    last_line_no=$(awk 'NF{n=NR} END{print n+0}' "$base")
    last_line=""
    [ "$last_line_no" -gt 0 ] && last_line=$(sed -n "${last_line_no}p" "$base")

    if printf '%s' "$last_line" | grep -Eq '^[[:space:]]*exit([[:space:]]|$)'; then
        head -n "$((last_line_no - 1))" "$base" > "$tmp"
        printf '\n%s\n\n' "$hook_block" >> "$tmp"
        sed -n "${last_line_no},\$p" "$base" >> "$tmp"
    else
        cat "$base" > "$tmp"
        printf '\n%s\n' "$hook_block" >> "$tmp"
    fi
    rm -f "$base"

    if bash -n "$tmp" 2>/dev/null; then
        mv "$tmp" "$HOOK_PATH"
        chmod +x "$HOOK_PATH"
        zlog "OOM: libvirt hook installed/updated at $HOOK_PATH" INFO
        return 0
    fi
    rm -f "$tmp"
    zlog "OOM: libvirt hook install/update syntax check FAILED — hook left unchanged" ERROR
    return 1
}

[ -f "$CONFIG" ] || { zlog "Config not found — OOM apply skipped" WARN; exit 0; }

OOM_ENABLED=$(cfg_val "oom_protect_enabled")
[ "$OOM_ENABLED" = "yes" ] || { zlog "OOM protection disabled — skipping" INFO; exit 0; }

OOM_LEVELS=$(cfg_val "oom_levels")
OOM_DEFAULT=$(cfg_val "oom_default_level")
[ -z "$OOM_DEFAULT" ] && OOM_DEFAULT="normal"
OOM_GROUP=$(cfg_val "oom_oom_group")
OOM_PROC_PATTERNS=$(cfg_val "oom_proc_patterns")
VM_MEMORY_MIN=$(cfg_val "vm_memory_min")

VM_ARG="${1:-}"

# ─── MODE: hook install only (called from apply_oom PHP action) ───
if [ "$VM_ARG" = "--install-hook" ]; then
    zlog "OOM hook-only install: started" INFO
    HOOK_BLOCK=$(cat << 'HOOKBLOCK'
# BEGIN zram-oom-protection
# Installed by unraid-zram-card — do not edit this block manually.
# Remove by disabling OOM protection in the ZRAM plugin settings.
if [ "$2" = "started" ]; then
    APPLY_SCRIPT="/usr/local/emhttp/plugins/unraid-zram-card/zram_oom_apply.sh"
    if [ -x "$APPLY_SCRIPT" ]; then
        "$APPLY_SCRIPT" "$1" &
    fi
fi
# END zram-oom-protection
HOOKBLOCK
)
    install_hook_block "$HOOK_BLOCK"
    exit 0
fi

# ─── MODE: single VM apply (called from libvirt started hook) ───
if [ -n "$VM_ARG" ]; then
    zlog "OOM hook: applying VM $VM_ARG" INFO
    LEVEL=$(oom_level_for_id "vm:$VM_ARG" "$OOM_LEVELS" "$OOM_DEFAULT")
    SCORE=$(oom_score_for_level "$LEVEL")
    PIDFILE="/run/libvirt/qemu/${VM_ARG}.pid"
    if [ -f "$PIDFILE" ]; then
        PID=$(cat "$PIDFILE")
        write_oom_score_adj "$PID" "$SCORE" "vm:$VM_ARG"
        if [ "$LEVEL" = "protected" ] && [ "$VM_MEMORY_MIN" = "yes" ]; then
            VMCGDIR=$(vm_cgroup_dir "$PID")
            VMMAXMEM=$(vm_max_memory_bytes "$VM_ARG")
            [ -n "$VMCGDIR" ] && apply_memory_min "$VMCGDIR" "$VMMAXMEM" "vm:$VM_ARG"
        fi
    else
        zlog "OOM hook: pid file $PIDFILE absent for $VM_ARG" WARN
    fi
    exit 0
fi

# ─── MODE: boot apply — containers, services, hook install ───

zlog "OOM boot apply: started" INFO

# --- Containers ---
if command -v docker >/dev/null 2>&1; then
    while IFS=$'\t' read -r CNAME CID CSTATUS; do
        [ -z "$CNAME" ] && continue
        RUNNING=0
        echo "$CSTATUS" | grep -q '^Up' && RUNNING=1
        [ $RUNNING -eq 0 ] && continue
        LEVEL=$(oom_level_for_id "docker:$CNAME" "$OOM_LEVELS" "$OOM_DEFAULT")
        SCORE=$(oom_score_for_level "$LEVEL")
        CGROUPFILE="/sys/fs/cgroup/docker/$CID/cgroup.procs"
        if [ -f "$CGROUPFILE" ]; then
            while read -r PID; do
                [ -z "$PID" ] || write_oom_score_adj "$PID" "$SCORE" "docker:$CNAME"
            done < "$CGROUPFILE"
            # memory.oom.group
            if [ "$OOM_GROUP" = "yes" ]; then
                OOGFILE="/sys/fs/cgroup/docker/$CID/memory.oom.group"
                if [ -f "$OOGFILE" ]; then
                    echo 1 > "$OOGFILE" 2>/dev/null && zlog "OOM: docker:$CNAME memory.oom.group=1" INFO
                fi
            fi
            if [ "$LEVEL" = "protected" ] && [ "$VM_MEMORY_MIN" = "yes" ]; then
                DLIMIT=$(docker_memory_limit_bytes "$CNAME")
                apply_memory_min "/sys/fs/cgroup/docker/$CID" "$DLIMIT" "docker:$CNAME"
            fi
        else
            zlog "OOM: container $CNAME cgroup.procs not found ($CGROUPFILE)" WARN
        fi
    done < <(docker ps --format $'{{.Names}}\t{{.ID}}\t{{.Status}}' 2>/dev/null)
fi

# --- Plugin/host services ---
# Curated defaults + user patterns
PATTERNS="mover:unraid_mover shfs:shfs btrfs:btrfs"
if [ -n "$OOM_PROC_PATTERNS" ]; then
    OLDIFS="$IFS"; IFS=','
    for PAT in $OOM_PROC_PATTERNS; do
        PATTERNS="$PATTERNS $PAT:$PAT"
    done
    IFS="$OLDIFS"
fi
for ENTRY in $PATTERNS; do
    LABEL="${ENTRY%%:*}"
    PATTERN="${ENTRY##*:}"
    LEVEL=$(oom_level_for_id "proc:$LABEL" "$OOM_LEVELS" "$OOM_DEFAULT")
    SCORE=$(oom_score_for_level "$LEVEL")
    while read -r PID; do
        [ -z "$PID" ] || write_oom_score_adj "$PID" "$SCORE" "proc:$LABEL"
        if [ -n "$PID" ] && [ "$LEVEL" = "protected" ] && [ "$VM_MEMORY_MIN" = "yes" ]; then
            protect_proc_pid "$PID" "$LABEL"
        fi
    done < <(pgrep -f "$PATTERN" 2>/dev/null)
done

# --- VMs: apply to any running VMs at boot ---
if command -v virsh >/dev/null 2>&1; then
    while read -r VMNAME; do
        [ -z "$VMNAME" ] && continue
        LEVEL=$(oom_level_for_id "vm:$VMNAME" "$OOM_LEVELS" "$OOM_DEFAULT")
        SCORE=$(oom_score_for_level "$LEVEL")
        PIDFILE="/run/libvirt/qemu/${VMNAME}.pid"
        if [ -f "$PIDFILE" ]; then
            PID=$(cat "$PIDFILE")
            write_oom_score_adj "$PID" "$SCORE" "vm:$VMNAME"
        fi
    done < <(virsh list --name 2>/dev/null | grep -v '^$')
fi

# --- Install/update libvirt hook block ---
HOOK_BLOCK=$(cat << 'HOOKBLOCK'
# BEGIN zram-oom-protection
# Installed by unraid-zram-card — do not edit this block manually.
# Remove by disabling OOM protection in the ZRAM plugin settings.
if [ "$2" = "started" ]; then
    APPLY_SCRIPT="/usr/local/emhttp/plugins/unraid-zram-card/zram_oom_apply.sh"
    if [ -x "$APPLY_SCRIPT" ]; then
        "$APPLY_SCRIPT" "$1" &
    fi
fi
# END zram-oom-protection
HOOKBLOCK
)

install_hook_block "$HOOK_BLOCK"

zlog "OOM boot apply: complete" INFO
