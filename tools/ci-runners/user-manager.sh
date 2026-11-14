#!/usr/bin/env bash
# Run as root inside an Orbit CI runner container. The runner remains unprivileged.
set -euo pipefail
runner_uid=$(id -u runner)
loginctl enable-linger runner
systemctl start "user@${runner_uid}.service"
runuser -u runner -- env XDG_RUNTIME_DIR="/run/user/${runner_uid}" \
  DBUS_SESSION_BUS_ADDRESS="unix:path=/run/user/${runner_uid}/bus" \
  timeout 10 systemctl --user is-active default.target
