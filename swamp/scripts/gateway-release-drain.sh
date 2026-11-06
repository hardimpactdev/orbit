#!/bin/sh
# Stops the Horizon unit, which waits for running jobs, and checks that it is inactive. Argument: unit name.
# Run by the step "Drain Horizon" of swamp/workflows/workflow-gateway-release-activate.yaml.
set -u
[ "$(systemctl show -p KillMode -p SendSIGKILL "$1" | sort | tr '\n' ' ')" = 'KillMode=mixed SendSIGKILL=no ' ] \
  || { echo "gateway.release_horizon_unit_stale: $1 lacks the stop policy; update the Horizon Process" >&2; exit 1; }
systemctl stop "$1" || :
[ "$(systemctl show -p ActiveState --value "$1")" = inactive ] || { echo "gateway.release_horizon_not_drained: $1 is not stopped" >&2; exit 1; }
