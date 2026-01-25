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

    # Parse zram_devices from ini (Format: size:algo,size:algo)
    ZRAM_DEVICES=$(grep "zram_devices=" "$CONFIG" | cut -d'"' -f2)
    
    if [ -z "$ZRAM_DEVICES" ]; then
        echo "No ZRAM devices configured in settings.ini"
        exit 0
    fi

    echo "Initializing ZRAM devices: $ZRAM_DEVICES"
    /sbin/modprobe zram
    
    # Split by comma
    IFS=',' read -ra ADDR <<< "$ZRAM_DEVICES"
    for entry in "${ADDR[@]}"; do
        # Split entry by colon (size:algo)
        SIZE="${entry%%:*}"
        ALGO="${entry##*:}"
        
        echo "Creating ZRAM device (Size: $SIZE, Algo: $ALGO)..."
        DEV=$(/usr/bin/zramctl --find --size "$SIZE" --algorithm "$ALGO")
        
        if [ ! -z "$DEV" ]; then
            echo "  > Created $DEV. Formatting as swap..."
            /sbin/mkswap "$DEV"
            /sbin/swapon "$DEV" -p 100
            echo "  > $DEV is now active."
        else
            echo "  > ERROR: Failed to create ZRAM device for size $SIZE"
        fi
    done

    echo "--- ZRAM BOOT INIT COMPLETE ---"
} >> "$LOG" 2>&1
