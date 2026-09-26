#!/bin/bash
set -euo pipefail

INSTALL_DIR="/usr/local/sbin"
CONFIG_DIR="/etc/remote-status"
CONFIG_FILE="${CONFIG_DIR}/heartbeat.conf"

SERVICE_FILE="/etc/systemd/system/remote-status.service"
TIMER_FILE="/etc/systemd/system/remote-status.timer"

SCRIPT_NAME="remote-status-heartbeat"
SCRIPT_PATH="${INSTALL_DIR}/${SCRIPT_NAME}"

echo "Installing remote-status..."

# ----------------------------------------------------------------------
# Require root
# ----------------------------------------------------------------------

if [[ "${EUID}" -ne 0 ]]; then
    echo "Please run as root:"
    echo "  sudo ./install.sh"
    exit 1
fi

# ----------------------------------------------------------------------
# Check dependencies
# ----------------------------------------------------------------------

for command in curl bash; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "ERROR: required command not found: $command" >&2
        exit 1
    fi
done

# ----------------------------------------------------------------------
# Install directories
# ----------------------------------------------------------------------

install -d -m 755 "$INSTALL_DIR"
install -d -m 750 "$CONFIG_DIR"

# ----------------------------------------------------------------------
# Install heartbeat script
# ----------------------------------------------------------------------

if [[ ! -f "$SCRIPT_NAME" ]]; then
    echo "ERROR: $SCRIPT_NAME not found in repository." >&2
    exit 1
fi

install -o root -g root -m 755 \
    "$SCRIPT_NAME" \
    "$SCRIPT_PATH"

echo "Installed $SCRIPT_PATH"

# ----------------------------------------------------------------------
# Create configuration if it doesn't already exist
# ----------------------------------------------------------------------

if [[ ! -f "$CONFIG_FILE" ]]; then

    cat > "$CONFIG_FILE" <<'EOF'
REMOTE_STATUS_URL="https://padens.us/status/heartbeat.php"
REMOTE_STATUS_HOST="pilr"
REMOTE_STATUS_KEY=""

# Each command returns:
#   0     = healthy
#   other = unhealthy
#
# Examples:
#
# Pi-hole, Docker healthcheck:
# docker inspect --format '{{.State.Health.Status}}' pihole | grep -qx healthy
#
# Pi-hole, native:
# pihole status >/dev/null 2>&1
#
# Homebridge, Docker:
# docker inspect --format '{{.State.Status}}' homebridge-homebridge-1 | grep -qx running
#
# Homebridge, systemd:
# systemctl is-active --quiet homebridge

declare -A CHECKS=(
    [pihole]='docker inspect --format "{{.State.Health.Status}}" pihole | grep -qx healthy'
    [homebridge]='docker inspect --format "{{.State.Status}}" homebridge-homebridge-1 | grep -qx running'
)
EOF

    chown root:root "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"

    echo
    echo "Created $CONFIG_FILE"
    echo
    echo "IMPORTANT: Set REMOTE_STATUS_KEY before starting the timer."
else
    echo "Keeping existing configuration: $CONFIG_FILE"
fi

# ----------------------------------------------------------------------
# Install systemd service
# ----------------------------------------------------------------------

cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=remote-status heartbeat
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=${SCRIPT_PATH}
EOF

# ----------------------------------------------------------------------
# Install systemd timer
# ----------------------------------------------------------------------

cat > "$TIMER_FILE" <<'EOF'
[Unit]
Description=remote-status heartbeat timer

[Timer]
OnBootSec=30s
OnUnitActiveSec=2min
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ----------------------------------------------------------------------
# Enable timer
# ----------------------------------------------------------------------

systemctl daemon-reload
systemctl enable --now remote-status.timer

# ----------------------------------------------------------------------
# Show status
# ----------------------------------------------------------------------

echo
echo "Installation complete."
echo
echo "Timer status:"
systemctl status remote-status.timer --no-pager
echo
echo "Next run:"
systemctl list-timers remote-status.timer --no-pager
echo
echo "Configuration:"
echo "  $CONFIG_FILE"
echo
echo "Manual test:"
echo "  sudo systemctl start remote-status.service"
echo