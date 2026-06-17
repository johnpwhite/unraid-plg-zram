#!/bin/bash
# zram_init.sh — Boot initialization for ZRAM Card plugin
# Creates labeled ZRAM swap, reactivates disk swap file, launches collector

LOG_DIR="/tmp/unraid-zram-card"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/boot_init.log"
DEBUG_LOG="$LOG_DIR/debug.log"
CONFIG="/boot/config/plugins/unraid-zram-card/settings.ini"
DEVICE_FILE="$LOG_DIR/device.conf"
ZRAM_LABEL="ZRAM_CARD"
SSD_LABEL="ZRAM_CARD_DISK"
SSD_LEGACY_LABEL="ZRAM_CARD_SSD"

{
echo "--- ZRAM BOOT INIT START: $(date) ---"

# --- Helper functions ---
DEBUG_MODE="no"
if [ -f "$CONFIG" ]; then
    DEBUG_MODE=$(grep "debug=" "$CONFIG" 2>/dev/null | cut -d'"' -f2)
fi

zlog() {
    local msg="$1" level="${2:-INFO}"
    level=$(echo "$level" | tr '[:lower:]' '[:upper:]')
    if [ "$level" = "DEBUG" ] && [ "$DEBUG_MODE" != "yes" ]; then return; fi
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $msg" >> "$DEBUG_LOG"
}

cfg_val() {
    grep "^$1=" "$CONFIG" 2>/dev/null | cut -d'"' -f2
}

# --- Migrate old multi-device config ---
if [ -f "$CONFIG" ]; then
    OLD_DEVS=$(cfg_val "zram_devices")
    if [ -n "$OLD_DEVS" ]; then
        zlog "Migrating legacy multi-device config: $OLD_DEVS" "INFO"
        # Sum all device sizes, take first algo
        TOTAL_MB=0
        FIRST_ALGO=""
        IFS=',' read -ra ENTRIES <<< "$OLD_DEVS"
        for entry in "${ENTRIES[@]}"; do
            IFS=':' read -r ESIZE EALGO EPRIO <<< "$entry"
            if [ -z "$FIRST_ALGO" ] && [ -n "$EALGO" ]; then FIRST_ALGO="$EALGO"; fi
            # Parse size to MB
            NUM=$(echo "$ESIZE" | grep -oE '[0-9]+')
            UNIT=$(echo "$ESIZE" | grep -oE '[A-Za-z]+')
            case "$UNIT" in
                G|g) TOTAL_MB=$((TOTAL_MB + NUM * 1024)) ;;
                M|m) TOTAL_MB=$((TOTAL_MB + NUM)) ;;
                T|t) TOTAL_MB=$((TOTAL_MB + NUM * 1024 * 1024)) ;;
                *)   TOTAL_MB=$((TOTAL_MB + NUM)) ;;
            esac
        done
        if [ -z "$FIRST_ALGO" ]; then FIRST_ALGO="zstd"; fi
        # Write migrated config
        sed -i "s/^zram_devices=.*/zram_size=\"${TOTAL_MB}M\"/" "$CONFIG"
        sed -i "s/^zram_algo=.*/zram_algo=\"$FIRST_ALGO\"/" "$CONFIG"
        # Remove old key if new keys exist
        if ! grep -q "^zram_size=" "$CONFIG"; then
            echo "zram_size=\"${TOTAL_MB}M\"" >> "$CONFIG"
        fi
        if ! grep -q "^zram_algo=" "$CONFIG"; then
            echo "zram_algo=\"$FIRST_ALGO\"" >> "$CONFIG"
        fi
        zlog "Migrated to single device: ${TOTAL_MB}M, $FIRST_ALGO" "INFO"
    fi
fi

if [ ! -f "$CONFIG" ]; then
    zlog "Config not found. Using defaults." "WARN"
fi

# --- Apply swappiness ---
SWAPPINESS=$(cfg_val "swappiness")
if [ -z "$SWAPPINESS" ]; then SWAPPINESS=150; fi
zlog "Setting vm.swappiness=$SWAPPINESS" "INFO"
sysctl -q vm.swappiness="$SWAPPINESS"

# --- Tier 1: ZRAM device ---
ZRAMCTL=$(command -v zramctl || echo "/sbin/zramctl")
MKSWAP=$(command -v mkswap || echo "/sbin/mkswap")
SWAPON=$(command -v swapon || echo "/sbin/swapon")

# Create + configure a fresh ZRAM device WITHOUT triggering a reset.
#
# `zramctl --find --size --algorithm` writes "1" to the chosen device's `reset`
# sysfs attr as the first step of setup (so it can then set comp_algorithm,
# which the kernel only accepts on an uninitialised device). At early boot the
# kernel auto-creates /dev/zram0 (disksize=0) and a udev worker transiently
# opens it to run blkid; the kernel's reset_store() returns EBUSY whenever the
# device has ANY open fd, so that race makes boot creation fail with
# "failed to reset: Device or resource busy". (The dashboard CREATE works
# because udev is quiescent by then.) See docs/specs/ZRAM_BOOT_RESET_RACE.md.
#
# We sidestep reset entirely: allocate a brand-new device id via
# /sys/class/zram-control/hot_add (always uninitialised) and write
# comp_algorithm then disksize directly through sysfs. Prints the /dev path on
# success; on failure prints the last error text and returns non-zero.
create_zram_device() {
    local size="$1" algo="$2" id dev i

    if [ -e /sys/class/zram-control/hot_add ]; then
        id=""
        for i in 1 2 3; do
            id=$(cat /sys/class/zram-control/hot_add 2>/dev/null)
            case "$id" in (''|*[!0-9]*) id="" ; sleep 0.3 ;; (*) break ;; esac
        done
        if [ -n "$id" ] && [ -d "/sys/block/zram${id}" ]; then
            dev="/dev/zram${id}"
            # comp_algorithm MUST be written before disksize.
            if [ -n "$algo" ] && ! echo "$algo" > "/sys/block/zram${id}/comp_algorithm" 2>/dev/null; then
                zlog "Kernel rejected algorithm '$algo' on $dev; using kernel default" "WARN"
            fi
            # disksize via sysfs uses memparse(), so "8G"/"512M" are accepted verbatim.
            if echo "$size" > "/sys/block/zram${id}/disksize" 2>/dev/null; then
                printf '%s' "$dev"
                return 0
            fi
            zlog "Failed to set disksize=$size on $dev via sysfs; releasing id $id" "WARN"
            echo "$id" > /sys/class/zram-control/hot_remove 2>/dev/null || true
        fi
    fi

    # Fallback: no zram-control (very old kernel) — retry zramctl to ride out
    # the transient udev-probe EBUSY window rather than failing on attempt 1.
    dev=""
    for i in 1 2 3 4 5; do
        if dev=$($ZRAMCTL --find --size "$size" --algorithm "$algo" 2>&1) \
           && [ -n "$dev" ] && [ -b "$dev" ]; then
            printf '%s' "$dev"
            return 0
        fi
        sleep 0.5
    done
    printf '%s' "$dev"
    return 1
}

# Check if we already have a labeled device active
EXISTING_DEV=""
for zdev in /sys/block/zram*; do
    [ -d "$zdev" ] || continue
    ZID=$(basename "$zdev")
    ELABEL=$(blkid -s LABEL -o value "/dev/$ZID" 2>/dev/null || true)
    if [ "$ELABEL" = "$ZRAM_LABEL" ]; then
        EXISTING_DEV="$ZID"
        break
    fi
done

if [ -n "$EXISTING_DEV" ] && grep -q "/dev/$EXISTING_DEV" /proc/swaps 2>/dev/null; then
    zlog "ZRAM device /dev/$EXISTING_DEV already active (labeled $ZRAM_LABEL). Skipping." "INFO"
    echo "$EXISTING_DEV" > "$DEVICE_FILE"
else
    # Calculate size
    ZRAM_SIZE=$(cfg_val "zram_size")
    if [ -z "$ZRAM_SIZE" ] || [ "$ZRAM_SIZE" = "auto" ]; then
        ZRAM_PCT=$(cfg_val "zram_percent")
        if [ -z "$ZRAM_PCT" ]; then ZRAM_PCT=50; fi
        MEM_KB=$(awk '/MemTotal/{print $2}' /proc/meminfo)
        ZRAM_MB=$(( MEM_KB * ZRAM_PCT / 100 / 1024 ))
        ZRAM_SIZE="${ZRAM_MB}M"
        zlog "Auto-sized ZRAM: ${ZRAM_PCT}% of ${MEM_KB}KB = ${ZRAM_SIZE}" "INFO"
    fi

    ZRAM_ALGO=$(cfg_val "zram_algo")
    if [ -z "$ZRAM_ALGO" ]; then ZRAM_ALGO="zstd"; fi

    ZRAM_PRIO=$(cfg_val "zram_priority"); [ -z "$ZRAM_PRIO" ] && ZRAM_PRIO="100"
    # Clamp to kernel range; default if non-numeric
    case "$ZRAM_PRIO" in (*[!0-9]*|"") ZRAM_PRIO="100" ;; esac
    [ "$ZRAM_PRIO" -lt 1 ] 2>/dev/null && ZRAM_PRIO="100"
    [ "$ZRAM_PRIO" -gt 32767 ] 2>/dev/null && ZRAM_PRIO="100"

    zlog "Creating ZRAM: size=$ZRAM_SIZE, algo=$ZRAM_ALGO, priority=$ZRAM_PRIO" "INFO"
    modprobe zram 2>/dev/null

    if DEV=$(create_zram_device "$ZRAM_SIZE" "$ZRAM_ALGO") && [ -n "$DEV" ] && [ -b "$DEV" ]; then
        zlog "Allocated $DEV, formatting with label $ZRAM_LABEL" "INFO"
        $MKSWAP -L "$ZRAM_LABEL" "$DEV" > /dev/null 2>&1
        $SWAPON "$DEV" -p "$ZRAM_PRIO"
        echo "$(basename "$DEV")" > "$DEVICE_FILE"
        zlog "Tier 1 active: $DEV" "INFO"
    else
        zlog "Failed to create ZRAM device: $DEV" "ERROR"
    fi
fi

# --- Tier 2: Disk swap file ---
SSD_ENABLED=$(cfg_val "ssd_swap_enabled")
SSD_PATH=$(cfg_val "ssd_swap_path")
BACKING=$(cfg_val "ssd_swap_backing"); [ -z "$BACKING" ] && BACKING="auto"

# Ensure loop module available when any backing may be loop
modprobe loop 2>/dev/null

# Activate Tier 2 disk swap. Returns 0 on success (or already-active),
# 1 on missing file/device, 2 on swapon failure.
# Backing-aware: reads BACKING from outer scope (cfg_val above).
# Used by the synchronous boot path, the background retry poller, and
# (indirectly) the PHP collector self-heal — loop support lands in all
# three paths from this single change.
activate_disk_swap() {
    local path="$1"
    [ -f "$path" ] || return 1

    local prio
    prio=$(cfg_val "ssd_swap_priority"); [ -z "$prio" ] && prio="10"
    case "$prio" in (*[!0-9]*|"") prio="10" ;; esac
    [ "$prio" -gt 32767 ] 2>/dev/null && prio="10"

    local backing="${BACKING}"
    [ -z "$backing" ] && backing=$(cfg_val "ssd_swap_backing")
    [ -z "$backing" ] && backing="file"

    if [ "$backing" = "loop" ]; then
        # Idempotency guard: is this image already attached as a loop device?
        local LOOP_DEV
        LOOP_DEV=$(losetup -j "$path" 2>/dev/null | awk -F: '{print $1; exit}')
        if [ -n "$LOOP_DEV" ]; then
            # Loop attached — is it already in /proc/swaps?
            if grep -q "^$LOOP_DEV " /proc/swaps 2>/dev/null; then
                return 0  # already active
            fi
            # Stale attach (crash/recovery): re-use it, just swapon
        else
            # Fresh attach
            LOOP_DEV=$(losetup -f --show "$path" 2>/dev/null) || return 2
        fi
        zlog "Activating loop-backed swap: $path via $LOOP_DEV (priority=$prio)" "INFO"
        $SWAPON "$LOOP_DEV" -p "$prio" 2>&1 || return 2
        return 0
    fi

    # --- Direct-file path (single-device btrfs, XFS) ---
    if grep -q "$path" /proc/swaps 2>/dev/null; then
        return 0  # already active — nothing to do
    fi
    # Migrate legacy ZRAM_CARD_SSD label to ZRAM_CARD_DISK while offline.
    if command -v swaplabel >/dev/null 2>&1; then
        local cur
        cur=$(swaplabel "$path" 2>/dev/null | awk '/LABEL:/{print $2}')
        if [ "$cur" = "$SSD_LEGACY_LABEL" ]; then
            if swaplabel -L "$SSD_LABEL" "$path" 2>/dev/null; then
                zlog "Relabeled $path from $SSD_LEGACY_LABEL to $SSD_LABEL" "INFO"
            fi
        fi
    fi
    zlog "Activating disk swap: $path (priority=$prio)" "INFO"
    $SWAPON "$path" -p "$prio" 2>&1 || return 2
    return 0
}

if [ "$SSD_ENABLED" = "yes" ] && [ -n "$SSD_PATH" ]; then
    if [ -f "$SSD_PATH" ]; then
        if [ "$BACKING" = "loop" ]; then
            EXIST_LOOP=$(losetup -j "$SSD_PATH" 2>/dev/null | awk -F: '{print $1; exit}')
            if [ -n "$EXIST_LOOP" ] && grep -q "^$EXIST_LOOP " /proc/swaps 2>/dev/null; then
                zlog "Loop-backed swap already active: $SSD_PATH ($EXIST_LOOP)" "INFO"
            else
                activate_disk_swap "$SSD_PATH" || zlog "Failed to activate disk swap" "ERROR"
            fi
        elif grep -q "$SSD_PATH" /proc/swaps 2>/dev/null; then
            zlog "Disk swap already active: $SSD_PATH" "INFO"
        else
            activate_disk_swap "$SSD_PATH" || zlog "Failed to activate disk swap" "ERROR"
        fi
    else
        SSD_MOUNT=$(cfg_val "ssd_swap_mount")
        if mountpoint -q "$SSD_MOUNT" 2>/dev/null; then
            zlog "Disk swap file missing but mount available. File may need recreation." "WARN"
        else
            # Boot race: UD / pool mounts come up AFTER plugin start. Spawn a
            # background poller that retries every 5s for up to 5 minutes.
            # See docs/specs/TIER2_BOOT_RETRY.md.
            zlog "Disk swap mount ($SSD_MOUNT) not ready — scheduling background retry (60 x 5s)" "INFO"
            (
                for i in $(seq 1 60); do
                    sleep 5
                    if [ -f "$SSD_PATH" ]; then
                        if [ "$BACKING" = "loop" ]; then
                            EXIST_LOOP=$(losetup -j "$SSD_PATH" 2>/dev/null | awk -F: '{print $1;exit}')
                            if [ -n "$EXIST_LOOP" ] && grep -q "^$EXIST_LOOP " /proc/swaps 2>/dev/null; then
                                zlog "Tier 2 active by external trigger after $((i*5))s wait" "INFO"
                                exit 0
                            fi
                        else
                            if grep -q "$SSD_PATH" /proc/swaps 2>/dev/null; then
                                zlog "Tier 2 active by external trigger after $((i*5))s wait" "INFO"
                                exit 0
                            fi
                        fi
                        if activate_disk_swap "$SSD_PATH"; then
                            zlog "Tier 2 activated after $((i*5))s wait" "INFO"
                            exit 0
                        fi
                    fi
                done
                zlog "Disk swap mount ($SSD_MOUNT) never appeared after 5 minutes — Tier 2 stays inactive. Check that the mount comes up on boot." "WARN"
            ) > /dev/null 2>&1 &
            disown
        fi
    fi
fi

# --- Launch collector ---
COLLECTOR="/usr/local/emhttp/plugins/unraid-zram-card/zram_collector.php"
PIDFILE="$LOG_DIR/collector.pid"

if [ -f "$PIDFILE" ]; then
    OLD_PID=$(cat "$PIDFILE")
    if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then
        zlog "Stopping old collector (PID $OLD_PID)" "INFO"
        kill "$OLD_PID" 2>/dev/null
        # Wait up to 3 seconds, then force kill
        for i in 1 2 3; do
            kill -0 "$OLD_PID" 2>/dev/null || break
            sleep 1
        done
        kill -0 "$OLD_PID" 2>/dev/null && kill -9 "$OLD_PID" 2>/dev/null
    fi
    rm -f "$PIDFILE"
fi

if [ -f "$COLLECTOR" ]; then
    nohup nice -n 19 php "$COLLECTOR" > /dev/null 2>&1 &
    disown
    zlog "Collector launched (PID $!)" "INFO"
fi

# --- OOM Protection apply ---
OOM_APPLY="/usr/local/emhttp/plugins/unraid-zram-card/zram_oom_apply.sh"
if [ -x "$OOM_APPLY" ]; then
    zlog "Calling zram_oom_apply.sh for boot-time OOM apply" "INFO"
    "$OOM_APPLY" >> "$LOG" 2>&1
fi

echo "--- ZRAM BOOT INIT COMPLETE ---"
} >> "$LOG" 2>&1
