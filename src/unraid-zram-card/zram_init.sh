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

    log_debug() {
        if [ "$DEBUG_MODE" == "yes" ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] DEBUG: $1" >> "$DEBUG_LOG"
            chmod 666 "$DEBUG_LOG" 2>/dev/null
        fi
    }

    log_debug "Boot init script starting..."

    if [ ! -f "$CONFIG" ]; then
        echo "Config file not found: $CONFIG"
        log_debug "ABORT: settings.ini missing. Cannot continue with ZRAM setup."
        # Don't exit yet, still want to try launching collector if it exists
    else
        log_debug "Config found. Proceeding with ZRAM setup."
    fi

    # Apply Global Swappiness (Default to 100 for ZRAM performance)
    if [ -f "$CONFIG" ]; then
        SWAPPINESS=$(grep "swappiness=" "$CONFIG" | cut -d'"' -f2)
        if [ -z "$SWAPPINESS" ]; then SWAPPINESS=100; fi
        echo "Setting global vm.swappiness to $SWAPPINESS"
        log_debug "Applying swappiness: $SWAPPINESS"
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
        log_debug "No ZRAM devices to initialize."
    else
        log_debug "Initializing ZRAM devices: $ZRAM_DEVICES"
        $MODPROBE zram
        
        # Split by comma
        IFS=',' read -ra ADDR <<< "$ZRAM_DEVICES"
        for entry in "${ADDR[@]}"; do
            # Split entry by colon (size:algo:prio)
            IFS=':' read -r SIZE ALGO PRIO <<< "$entry"
            if [ -z "$PRIO" ]; then PRIO=100; fi
            
            echo "Creating ZRAM device (Size: $SIZE, Algo: $ALGO, Prio: $PRIO)..."
            log_debug "  > Creating $SIZE ($ALGO) with prio $PRIO"
            # Combine find, size, and algo into one call as required by kernel
            DEV=$($ZRAMCTL --find --size "$SIZE" --algorithm "$ALGO")
            
            if [ ! -z "$DEV" ]; then
                echo "  > Created $DEV. Formatting as swap..."
                log_debug "  > Formatting $DEV..."
                $MKSWAP "$DEV"
                $SWAPON "$DEV" -p "$PRIO"
                echo "  > $DEV is now active."
                log_debug "  > $DEV activated."
            else
                echo "  > ERROR: Failed to create ZRAM device for size $SIZE"
                log_debug "  > ERROR: Failed to create $DEV"
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
        log_debug "Collector launched with PID $!"
        echo "Collector started with PID $!"
    else
        echo "Background Collector is already running."
        log_debug "Collector already running. Skipping launch."
    fi

    echo "--- ZRAM BOOT INIT COMPLETE ---"
} >> "$LOG" 2>&1