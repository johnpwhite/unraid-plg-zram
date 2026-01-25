#!/bin/bash
# zram_init.sh
# Re-applies ZRAM configuration from settings.ini on boot

CONFIG="/boot/config/plugins/unraid-zram-card/settings.ini"

if [ -f "$CONFIG" ]; then
    # Parse zram_devices from ini
    ZRAM_DEVICES=$(grep "zram_devices=" "$CONFIG" | cut -d'"' -f2)
    
    if [ ! -z "$ZRAM_DEVICES" ]; then
        echo "Initializing ZRAM devices: $ZRAM_DEVICES"
        modprobe zram
        
        # Split by comma
        IFS=',' read -ra ADDR <<< "$ZRAM_DEVICES"
        for size in "${ADDR[@]}"; do
            echo "Creating ZRAM device of size $size..."
            DEV=$(zramctl --find --size "$size")
            if [ ! -z "$DEV" ]; then
                mkswap "$DEV"
                swapon "$DEV" -p 100
            fi
        done
    fi
fi
