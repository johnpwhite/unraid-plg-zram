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

[ -f "$CONFIG" ] || { zlog "Config not found — OOM apply skipped" WARN; exit 0; }

OOM_ENABLED=$(cfg_val "oom_protect_enabled")
[ "$OOM_ENABLED" = "yes" ] || { zlog "OOM protection disabled — skipping" INFO; exit 0; }

OOM_LEVELS=$(cfg_val "oom_levels")
OOM_DEFAULT=$(cfg_val "oom_default_level")
[ -z "$OOM_DEFAULT" ] && OOM_DEFAULT="normal"
OOM_GROUP=$(cfg_val "oom_oom_group")
OOM_PROC_PATTERNS=$(cfg_val "oom_proc_patterns")

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
    mkdir -p /etc/libvirt/hooks
    if ! grep -q "$HOOK_MARKER_START" "$HOOK_PATH" 2>/dev/null; then
        if [ ! -f "$HOOK_PATH" ]; then
            printf '#!/bin/bash\n# Libvirt qemu hook\n' > "$HOOK_PATH"
        fi
        printf '\n%s\n' "$HOOK_BLOCK" >> "$HOOK_PATH"
        chmod +x "$HOOK_PATH"
        if bash -n "$HOOK_PATH" 2>/dev/null; then
            zlog "OOM hook-only: installed at $HOOK_PATH" INFO
        else
            zlog "OOM hook-only: syntax check FAILED — reverting" ERROR
            sed -i "/$HOOK_MARKER_START/,/$HOOK_MARKER_END/d" "$HOOK_PATH"
        fi
    else
        TMP=$(mktemp)
        sed "/$HOOK_MARKER_START/,/$HOOK_MARKER_END/d" "$HOOK_PATH" > "$TMP"
        printf '\n%s\n' "$HOOK_BLOCK" >> "$TMP"
        if bash -n "$TMP" 2>/dev/null; then
            mv "$TMP" "$HOOK_PATH"
            chmod +x "$HOOK_PATH"
            zlog "OOM hook-only: updated at $HOOK_PATH" INFO
        else
            rm -f "$TMP"
            zlog "OOM hook-only: update syntax check FAILED — unchanged" ERROR
        fi
    fi
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

mkdir -p /etc/libvirt/hooks

if ! grep -q "$HOOK_MARKER_START" "$HOOK_PATH" 2>/dev/null; then
    # Hook is absent or our block is not there — create or append
    if [ ! -f "$HOOK_PATH" ]; then
        printf '#!/bin/bash\n# Libvirt qemu hook\n' > "$HOOK_PATH"
    fi
    printf '\n%s\n' "$HOOK_BLOCK" >> "$HOOK_PATH"
    chmod +x "$HOOK_PATH"
    # Syntax check
    if bash -n "$HOOK_PATH" 2>/dev/null; then
        zlog "OOM: libvirt hook installed at $HOOK_PATH" INFO
    else
        zlog "OOM: libvirt hook syntax check FAILED — reverting" ERROR
        # Remove our block so the hook stays functional
        sed -i "/$HOOK_MARKER_START/,/$HOOK_MARKER_END/d" "$HOOK_PATH"
    fi
else
    # Update the block in-place: remove old block, append new one
    # Use temp file for safety
    TMP=$(mktemp)
    sed "/$HOOK_MARKER_START/,/$HOOK_MARKER_END/d" "$HOOK_PATH" > "$TMP"
    printf '\n%s\n' "$HOOK_BLOCK" >> "$TMP"
    if bash -n "$TMP" 2>/dev/null; then
        mv "$TMP" "$HOOK_PATH"
        chmod +x "$HOOK_PATH"
        zlog "OOM: libvirt hook updated at $HOOK_PATH" INFO
    else
        rm -f "$TMP"
        zlog "OOM: libvirt hook update syntax check FAILED — previous hook unchanged" ERROR
    fi
fi

zlog "OOM boot apply: complete" INFO
