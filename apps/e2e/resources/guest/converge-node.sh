#!/usr/bin/env bash
# On the topology Gateway: provision one Node with `orbit:node-provision`. A workload Node gets its base role and
# TLD; the operator gets none. Each acquisition composes a fresh Gateway, so every Node is provisioned once.
# Usage: converge-node.sh provision NODE ADDRESS ARCHITECTURE WIREGUARD_IP [ROLE TLD]
set -euo pipefail
umask 077
cd /home/orbit/orbit/apps/gateway
[[ ${1-} == provision ]] || exit 64
shift
[[ ($# -eq 4 || $# -eq 6) && $1 =~ ^[a-z][a-z0-9-]{0,22}$ && $2 =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || exit 64
[[ $3 =~ ^(x86_64|aarch64)$ && $4 =~ ^10\.44\.0\.[1-9][0-9]{0,2}$ ]] || exit 65
role=()
if [[ $# -eq 6 ]]; then
  [[ $5 =~ ^[a-z][a-z0-9-]{0,31}$ && $6 =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]] || exit 64
  role=(--role="$5" --tld="$6")
fi
deadline=$((SECONDS + 60))
until keys=$(ssh-keyscan -T 5 -t ed25519 -- "$2" 2>/dev/null) && [[ -n $keys ]]; do
  ((SECONDS < deadline)) || { echo "converge-node: no SSH host key from $2" >&2; exit 1; }
  sleep 2
done
fingerprint=$(ssh-keygen -lf - -E sha256 <<<"$keys" | awk 'NR == 1 { print $2 }')
[[ $fingerprint =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway \
  DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php artisan orbit:node-provision "$1" "$2" "${role[@]}" \
  --architecture="$3" --user=orbit --wireguard-ip="$4" --host-key-fingerprint="$fingerprint" --diagnostics --no-interaction
