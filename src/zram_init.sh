#!/bin/bash
# zram_init.sh — Boot initialization for ZRAM Card plugin
# Creates labeled ZRAM swap, reactivates SSD swap file, launches collector

LOG_DIR="/tmp/unraid-zram-card"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/boot_init.log"
DEBUG_LOG="$LOG_DIR/debug.log"
CONFIG="/boot/config/plugins/unraid-zram-card/settings.ini"
DEVICE_FILE="$LOG_DIR/device.conf"
ZRAM_LABEL="ZRAM_CARD"
SSD_LABEL="ZRAM_CARD_SSD"

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

    zlog "Creating ZRAM: size=$ZRAM_SIZE, algo=$ZRAM_ALGO" "INFO"
    modprobe zram 2>/dev/null

    DEV=$($ZRAMCTL --find --size "$ZRAM_SIZE" --algorithm "$ZRAM_ALGO" 2>&1)
    if [ $? -eq 0 ] && [ -n "$DEV" ]; then
        zlog "Allocated $DEV, formatting with label $ZRAM_LABEL" "INFO"
        $MKSWAP -L "$ZRAM_LABEL" "$DEV" > /dev/null 2>&1
        $SWAPON "$DEV" -p 100
        echo "$(basename "$DEV")" > "$DEVICE_FILE"
        zlog "Tier 1 active: $DEV" "INFO"
    else
        zlog "Failed to create ZRAM device: $DEV" "ERROR"
    fi
fi

# --- Tier 2: SSD swap file ---
SSD_ENABLED=$(cfg_val "ssd_swap_enabled")
SSD_PATH=$(cfg_val "ssd_swap_path")

if [ "$SSD_ENABLED" = "yes" ] && [ -n "$SSD_PATH" ]; then
    if [ -f "$SSD_PATH" ]; then
        # Check if already active
        if grep -q "$SSD_PATH" /proc/swaps 2>/dev/null; then
            zlog "Disk swap already active: $SSD_PATH" "INFO"
        else
            zlog "Activating disk swap: $SSD_PATH" "INFO"
            $SWAPON "$SSD_PATH" -p 10 2>&1 || zlog "Failed to activate disk swap" "ERROR"
        fi
    else
        SSD_MOUNT=$(cfg_val "ssd_swap_mount")
        if mountpoint -q "$SSD_MOUNT" 2>/dev/null; then
            zlog "Disk swap file missing but mount available. File may need recreation." "WARN"
        else
            zlog "Disk swap mount ($SSD_MOUNT) not available yet. Skipping Tier 2." "WARN"
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

echo "--- ZRAM BOOT INIT COMPLETE ---"
} >> "$LOG" 2>&1
