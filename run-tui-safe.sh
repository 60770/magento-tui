#!/bin/bash
# Safe wrapper to run TUI with proper cleanup on exit/interrupt

# Cleanup function
cleanup() {
    echo "Cleaning up terminal..."
    printf '\033[?25h'    # Show cursor
    stty sane
    clear
    exit 0
}

# Trap various signals to ensure cleanup
trap cleanup EXIT INT TERM

# Run the TUI command
warden env exec php-fpm bin/magento tidycode:tui

# Cleanup will be called automatically by trap
