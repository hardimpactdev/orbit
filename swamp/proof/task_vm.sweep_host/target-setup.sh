#!/usr/bin/env bash
# Isolated target VM only. A container orphan avoids unproven nested KVM support.
set -euo pipefail
if ! command -v incus >/dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install --yes incus
fi
systemctl start incus.service
incus project create orbit-proof-sweep -c features.images=false -c features.profiles=false
incus storage create orbit-proof-sweep dir
incus init images:ubuntu/24.04 tvm-6 --project orbit-proof-sweep --storage orbit-proof-sweep --no-profiles \
    -c user.orbit.proof=task_vm.sweep_host \
    -c user.orbit.expires_at=2000-01-01T00:00:00Z
incus storage volume create orbit-proof-sweep tvm-6-topology-seed --project orbit-proof-sweep
