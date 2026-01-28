#!/bin/bash
# zram_init.sh
# Re-applies ZRAM configuration from settings.ini on boot

LOG_DIR="/tmp/unraid-zram-card"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/boot_init.log"

{
    echo "--- ZRAM BOOT INIT START: $(date) ---"
    CONFIG="/boot/config/plugins/unraid-zram-card/settings.ini"

    if [ ! -f "$CONFIG" ]; then
        echo "Config file not found: $CONFIG"
        exit 0
    fi

    # Apply Global Swappiness (Default to 100 for ZRAM performance)
    SWAPPINESS=$(grep "swappiness=" "$CONFIG" | cut -d'"' -f2)
    if [ -z "$SWAPPINESS" ]; then SWAPPINESS=100; fi
    echo "Setting global vm.swappiness to $SWAPPINESS"
    sysctl vm.swappiness=$SWAPPINESS >> "$LOG" 2>&1

    # Find binary paths
    ZRAMCTL=$(which zramctl || echo "/sbin/zramctl")
    MKSWAP=$(which mkswap || echo "/sbin/mkswap")
    SWAPON=$(which swapon || echo "/sbin/swapon")
    MODPROBE=$(which modprobe || echo "/sbin/modprobe")

    # Parse zram_devices from ini (Format: size:algo,size:algo)
    ZRAM_DEVICES=$(grep "zram_devices=" "$CONFIG" | cut -d'"' -f2)
    
    if [ -z "$ZRAM_DEVICES" ]; then
        echo "No ZRAM devices configured in settings.ini"
        exit 0
    fi

    echo "Initializing ZRAM devices: $ZRAM_DEVICES"
    $MODPROBE zram
    
    # Split by comma
    IFS=',' read -ra ADDR <<< "$ZRAM_DEVICES"
    for entry in "${ADDR[@]}"; do
        # Split entry by colon (size:algo:prio)
        IFS=':' read -r SIZE ALGO PRIO <<< "$entry"
        if [ -z "$PRIO" ]; then PRIO=100; fi
        
        echo "Creating ZRAM device (Size: $SIZE, Algo: $ALGO, Prio: $PRIO)..."
        # Combine find, size, and algo into one call as required by kernel
        DEV=$($ZRAMCTL --find --size "$SIZE" --algorithm "$ALGO")
        
        if [ ! -z "$DEV" ]; then
            echo "  > Created $DEV. Formatting as swap..."
            $MKSWAP "$DEV"
            $SWAPON "$DEV" -p "$PRIO"
            echo "  > $DEV is now active."
        else
            echo "  > ERROR: Failed to create ZRAM device for size $SIZE"
        fi
    done

    echo "--- ZRAM BOOT INIT COMPLETE ---"
} >> "$LOG" 2>&1