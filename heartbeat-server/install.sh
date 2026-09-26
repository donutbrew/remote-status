#!/bin/bash
set -euo pipefail

WEB_ROOT="/var/www/html/status"
DATA_DIR="/var/lib/remote-status"
SCRIPT="heartbeat.php"

echo "Installing remote-status server..."

if [[ "${EUID}" -ne 0 ]]; then
    echo "Please run as root:"
    echo "  sudo ./install.sh"
    exit 1
fi

if [[ ! -f "$SCRIPT" ]]; then
    echo "ERROR: $SCRIPT not found." >&2
    exit 1
fi

# ----------------------------------------------------------------------
# Check dependencies
# ----------------------------------------------------------------------

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: PHP is not installed." >&2
    exit 1
fi

# ----------------------------------------------------------------------
# Create directories
# ----------------------------------------------------------------------

install -d -o www-data -g www-data -m 750 "$WEB_ROOT"
install -d -o www-data -g www-data -m 750 "$DATA_DIR"

# ----------------------------------------------------------------------
# Install endpoint
# ----------------------------------------------------------------------

install -o root -g root -m 644 \
    "$SCRIPT" \
    "$WEB_ROOT/$SCRIPT"

echo "Installed $WEB_ROOT/$SCRIPT"

# ----------------------------------------------------------------------
# Create log file
# ----------------------------------------------------------------------

if [[ ! -f "$DATA_DIR/heartbeat.log" ]]; then
    touch "$DATA_DIR/heartbeat.log"
fi

chown www-data:www-data "$DATA_DIR/heartbeat.log"
chmod 640 "$DATA_DIR/heartbeat.log"

# ----------------------------------------------------------------------
# Validate PHP
# ----------------------------------------------------------------------

echo
echo "Checking PHP syntax..."

php -l "$WEB_ROOT/$SCRIPT"

# ----------------------------------------------------------------------
# Finish
# ----------------------------------------------------------------------

echo
echo "Installation complete."
echo
echo "Endpoint:"
echo "  https://localhost/status/heartbeat.php"
echo
echo "Log:"
echo "  $DATA_DIR/heartbeat.log"
echo
echo "Next step:"
echo "  Configure host keys in heartbeat.php"