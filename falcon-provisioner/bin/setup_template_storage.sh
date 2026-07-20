#!/usr/bin/env bash
# One-time VPS setup for WP template snapshot storage + sudo capture for Blesta admin.
# Usage: ssh falcons 'bash -s' < falcon-provisioner/bin/setup_template_storage.sh
set -euo pipefail

TEMPLATES_DIR="/var/lib/falcon-provisioner/templates"
SITE_USER="falco6812"
CAPTURE="/opt/falcon-provisioner/bin/snapshot_capture.sh"
CAPTURE_LOG="/var/log/falcon-provisioner-capture.log"
SUDOERS="/etc/sudoers.d/falcon-template-capture"

echo ">> Template storage: ${TEMPLATES_DIR}"
mkdir -p "$TEMPLATES_DIR"
chown root:"$SITE_USER" "$TEMPLATES_DIR"
chmod 2750 "$TEMPLATES_DIR"

echo ">> Capture log: ${CAPTURE_LOG}"
touch "$CAPTURE_LOG"
chown root:"$SITE_USER" "$CAPTURE_LOG"
chmod 664 "$CAPTURE_LOG"

echo ">> Sudoers: ${SITE_USER} may run snapshot_capture.sh as root"
install -m 440 /dev/stdin "$SUDOERS" <<EOF
# Falcon Theme Snapshots — Blesta admin capture (Phase 2)
${SITE_USER} ALL=(root) NOPASSWD: ${CAPTURE}
EOF
visudo -cf "$SUDOERS"

echo "Done. Test: sudo -u ${SITE_USER} sudo ${CAPTURE} --help"
