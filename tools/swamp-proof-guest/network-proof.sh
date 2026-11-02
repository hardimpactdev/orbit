#!/usr/bin/env bash
# Network converge fixture, root on the task VM from the staged checkout. Args: target and peer Node ids.
# The target becomes a running legacy hub (key inline in orbit.conf, no peers); the peer has no WireGuard state.
# The Gateway records the target as the VPN hub and trusts its public key. Never prints a private key.
set -euo pipefail
target_id=$1
state=/root/proof-network
mkdir -p "$state" && chmod 700 "$state"
umask 077
hub_key=$(wg genkey)
hub_public=$(printf '%s' "$hub_key" | wg pubkey)
printf '%s\n' "$hub_public" > "$state/hub.public"

printf '{"roles":{"%s":["vpn"]},"vpn_subnet":"10.231.0.0/24"}' "$target_id" \
  | runuser -u orbit -- env -C apps/gateway php ../../tools/swamp-proof-guest/gateway.php fixture
# The hub public key every peer config names; the Gateway reads it from its WireGuard directory.
home=$(sed -n 's/^ORBIT_HOME=//p' apps/gateway/.env)
# The Gateway keeps its WireGuard key and its key-assignment lock under ORBIT_HOME, as on a real Gateway.
install -d -m 0700 -o orbit -g orbit "$home/wireguard" "$home/locks"
printf '%s\n' "$hub_public" > "$home/wireguard/public.key"
chown orbit:orbit "$home/wireguard/public.key"

# The hub tunnel uses its own subnet: an address on the LAN subnet would route its handshake replies into the tunnel.
printf '[Interface]\nAddress = 10.99.0.1/24\nListenPort = 51820\nTable = off\nPrivateKey = %s\n' "$hub_key" \
  | incus exec target -- sh -c 'command -v wg-quick >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q wireguard-tools >/dev/null
    umask 077; mkdir -p /etc/wireguard; rm -rf /etc/wireguard/orbit.key /etc/wireguard/orbit-peers.conf /etc/wireguard/orbit-peers.d
    cat > /etc/wireguard/orbit.conf; ufw allow 51820/udp comment proof-hub >/dev/null
    systemctl enable wg-quick@orbit >/dev/null 2>&1; systemctl restart wg-quick@orbit'
