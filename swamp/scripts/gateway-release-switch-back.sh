#!/bin/sh
# Puts the previous release back after a failed check, then fails the run with its marker. Arguments: release id, previous release id.
# Run by the step "Switch back" of swamp/workflows/workflow-gateway-release-activate.yaml.
"/home/orbit/releases/$1/bin/gateway-rollback" "$2" >&2 \
  && echo "gateway.release_switched_back: the check failed; release $2 is current again" >&2
exit 1
