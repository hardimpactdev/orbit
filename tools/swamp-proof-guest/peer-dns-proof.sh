#!/usr/bin/env bash
# Old path: do not extend; target: the case runner (swamp/proof/runner) and cases files, docs/reference/verification-cases.mdx. Listed in docs/reference/old-paths.md.
# Peer DNS fixture, root on the task VM from the staged checkout. Args: target and peer Node ids.
# The target is the VPN hub: tunnel address 10.231.0.201 and a DNS server there that answers gateway.orbit.
# The peer runs its orbit tunnel to the hub with no DNS hook, no marker and no DNS policy. Never prints a private key.
set -euo pipefail
target_id=$1
peer_id=$2
umask 077
hub_key=$(wg genkey)
hub_public=$(printf '%s' "$hub_key" | wg pubkey)
peer_key=$(wg genkey)
peer_public=$(printf '%s' "$peer_key" | wg pubkey)

# The peer is a managed peer with its live key; its DNS server is the hub's tunnel address, which resolved reaches over orbit.
printf '{"roles":{"%s":["vpn"],"%s":["app-dev"]},"wireguard_keys":{"%s":"%s"},"vpn_subnet":"10.231.0.0/24","node_updates":[{"id":%s,"attributes":{"dns_server_override":"10.231.0.201"}}]}' \
  "$target_id" "$peer_id" "$peer_id" "$peer_public" "$peer_id" \
  | runuser -u orbit -- env -C apps/gateway php ../../tools/swamp-proof-guest/gateway.php fixture

printf '[Interface]\nAddress = 10.231.0.201/32\nListenPort = 51820\nPrivateKey = %s\n\n[Peer]\nPublicKey = %s\nAllowedIPs = 10.231.0.202/32\n' "$hub_key" "$peer_public" \
  | incus exec target -- sh -c 'command -v wg-quick >/dev/null && command -v dnsmasq >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q wireguard-tools dnsmasq-base >/dev/null
    mkdir -p /etc/wireguard; umask 077; cat > /etc/wireguard/orbit.conf
    ufw allow 51820/udp comment proof-hub >/dev/null; ufw allow from 10.231.0.202 to any port 53 comment proof-dns >/dev/null
    systemctl restart wg-quick@orbit
    pkill -F /run/proof-dns.pid 2>/dev/null || true
    dnsmasq --conf-file=/dev/null --pid-file=/run/proof-dns.pid --bind-interfaces --listen-address=10.231.0.201 --no-resolv --no-hosts --address=/gateway.orbit/10.231.0.1'

printf '[Interface]\nAddress = 10.231.0.202/32\nPrivateKey = %s\n\n[Peer]\nPublicKey = %s\nEndpoint = 10.231.0.10:51820\nAllowedIPs = 10.231.0.201/32\nPersistentKeepalive = 5\n' "$peer_key" "$hub_public" \
  | incus exec peer -- sh -c 'command -v wg-quick >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q wireguard-tools >/dev/null
    systemctl stop wg-quick@orbit 2>/dev/null || true
    mkdir -p /etc/wireguard; rm -f /etc/wireguard/orbit.dns-link; umask 077; cat > /etc/wireguard/orbit.conf
    systemctl restart wg-quick@orbit'
