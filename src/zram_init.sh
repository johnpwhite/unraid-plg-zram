#!/bin/bash
# zram_init.sh
# Re-applies ZRAM configuration from settings.ini on boot

LOG_DIR="/tmp/unraid-zram-card"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/boot_init.log"

{
    echo "--- ZRAM BOOT INIT START: $(date) ---"
    CONFIG="/boot/config/plugins/unraid-zram-card/settings.ini"
    DEBUG_LOG="$LOG_DIR/debug.log"

    # Load Debug Flag early if possible
    DEBUG_MODE="no"
    if [ -f "$CONFIG" ]; then
        DEBUG_MODE=$(grep "debug=" "$CONFIG" | cut -d'"' -f2)
    fi

    zram_log() {
        local msg="$1"
        local level="${2:-DEBUG}"
        level=$(echo "$level" | tr '[:lower:]' '[:upper:]')
        
        # Only log DEBUG if explicitly enabled
        if [[ "$level" == "DEBUG" && "$DEBUG_MODE" != "yes" ]]; then
            return
        fi

        echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $msg" >> "$DEBUG_LOG"
        chmod 666 "$DEBUG_LOG" 2>/dev/null
    }

    zram_log "Boot init script starting..." "INFO"

    if [ ! -f "$CONFIG" ]; then
        echo "Config file not found: $CONFIG"
        zram_log "ABORT: settings.ini missing. Cannot continue with ZRAM setup." "ERROR"
        # Don't exit yet, still want to try launching collector if it exists
    else
        zram_log "Config found. Proceeding with ZRAM setup." "INFO"
    fi

    # Apply Global Swappiness (Default to 100 for ZRAM performance)
    if [ -f "$CONFIG" ]; then
        SWAPPINESS=$(grep "swappiness=" "$CONFIG" | cut -d'"' -f2)
        if [ -z "$SWAPPINESS" ]; then SWAPPINESS=100; fi
        echo "Setting global vm.swappiness to $SWAPPINESS"
        zram_log "Applying swappiness: $SWAPPINESS" "INFO"
        sysctl vm.swappiness=$SWAPPINESS >> "$LOG" 2>&1
    fi

    # Find binary paths
    ZRAMCTL=$(which zramctl || echo "/sbin/zramctl")
    MKSWAP=$(which mkswap || echo "/sbin/mkswap")
    SWAPON=$(which swapon || echo "/sbin/swapon")
    MODPROBE=$(which modprobe || echo "/sbin/modprobe")

    # Parse zram_devices from ini (Format: size:algo,size:algo)
    ZRAM_DEVICES=""
    if [ -f "$CONFIG" ]; then
        ZRAM_DEVICES=$(grep "zram_devices=" "$CONFIG" | cut -d'"' -f2)
    fi
    
    if [ -z "$ZRAM_DEVICES" ]; then
        echo "No ZRAM devices configured in settings.ini"
        zram_log "No ZRAM devices to initialize." "INFO"
    else
        zram_log "Initializing ZRAM devices: $ZRAM_DEVICES" "INFO"
        $MODPROBE zram
        
        # Split by comma
        IFS=',' read -ra ADDR <<< "$ZRAM_DEVICES"
        for entry in "${ADDR[@]}"; do
            # Split entry by colon (size:algo:prio)
            IFS=':' read -r SIZE ALGO PRIO <<< "$entry"
            if [ -z "$PRIO" ]; then PRIO=100; fi
            
            echo "Creating ZRAM device (Size: $SIZE, Algo: $ALGO, Prio: $PRIO)..."
            zram_log "  > Creating $SIZE ($ALGO) with prio $PRIO" "DEBUG"
            # Combine find, size, and algo into one call as required by kernel
            DEV=$($ZRAMCTL --find --size "$SIZE" --algorithm "$ALGO")
            
            if [ ! -z "$DEV" ]; then
                echo "  > Created $DEV. Formatting as swap..."
                zram_log "  > Formatting $DEV..." "DEBUG"
                $MKSWAP "$DEV"
                $SWAPON "$DEV" -p "$PRIO"
                echo "  > $DEV is now active."
                zram_log "  > $DEV activated." "INFO"
            else
                echo "  > ERROR: Failed to create ZRAM device for size $SIZE"
                zram_log "  > ERROR: Failed to create $DEV" "ERROR"
            fi
        done
    fi

    # --- Launch Background Collector ---
    COLLECTOR="/usr/local/emhttp/plugins/unraid-zram-card/zram_collector.php"
    PIDFILE="/tmp/unraid-zram-card/collector.pid"
    
    # Check if collector is already running
    COLLECTOR_RUNNING=0
    if [ -f "$PIDFILE" ]; then
        PID=$(cat "$PIDFILE")
        if ps -p $PID > /dev/null; then
            COLLECTOR_RUNNING=1
        fi
    fi

    if [ $COLLECTOR_RUNNING -eq 0 ]; then
        echo "Starting Background Collector..."
        # Use nice -n 19 to ensure it has lowest priority
        nohup nice -n 19 php "$COLLECTOR" > /dev/null 2>&1 &
        zram_log "Collector launched with PID $!" "INFO"
        echo "Collector started with PID $!"
    else
        echo "Background Collector is already running."
        zram_log "Collector already running. Skipping launch." "INFO"
    fi

    echo "--- ZRAM BOOT INIT COMPLETE ---"
} >> "$LOG" 2>&1