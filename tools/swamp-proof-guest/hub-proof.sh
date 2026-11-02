#!/usr/bin/env bash
# Hub converge fixture, root on the task VM from the staged checkout. Args: target, gateway and operator Node ids.
# The target becomes a legacy hub (key inline in orbit.conf) with one real peer, this VM's `wgproof` interface,
# which handshakes every 2 s, and one stale peer. Records the hub public key and the real peer's received bytes,
# so parity can show the key and the session survive the run. Never prints a private key.
set -euo pipefail
target_id=$1 gateway_id=$2 operator_id=$3
state=/root/proof-hub
mkdir -p "$state" && chmod 700 "$state"
ip link del wgproof 2>/dev/null || true
umask 077
wg genkey > "$state/peer.key"
peer_public=$(wg pubkey < "$state/peer.key")
hub_key=$(wg genkey)
hub_public=$(printf '%s' "$hub_key" | wg pubkey)
printf '%s\n' "$hub_public" > "$state/hub.public"
printf '%s\n' "$peer_public" > "$state/peer.public"

# The Gateway records this VM (Node $gateway_id) as the peer with the real key, the operator with a fixed one.
printf '{"roles":{"%s":["vpn"]},"wireguard_keys":{"%s":"%s","%s":"AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI="},"vpn_subnet":"10.231.0.0/24"}' \
  "$target_id" "$gateway_id" "$peer_public" "$operator_id" \
  | runuser -u orbit -- env -C apps/gateway php ../../tools/swamp-proof-guest/gateway.php fixture

{
  printf '[Interface]\nAddress = 10.99.0.1/24\nListenPort = 51820\nPrivateKey = %s\n' "$hub_key"
  printf '\n[Peer]\nPublicKey = %s\nAllowedIPs = 10.231.0.1/32\n' "$peer_public"
  printf '\n[Peer]\nPublicKey = AwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwM=\nAllowedIPs = 10.231.0.99/32\n'
} | incus exec target -- sh -c 'umask 077; cat > /etc/wireguard/orbit.conf; ufw allow 51820/udp comment proof-hub >/dev/null; systemctl enable wg-quick@orbit >/dev/null 2>&1; systemctl restart wg-quick@orbit'

ip link add wgproof type wireguard
wg set wgproof private-key "$state/peer.key" listen-port 51821 \
  peer "$hub_public" endpoint 10.231.0.10:51820 allowed-ips 10.99.0.1/32 persistent-keepalive 2
ip link set wgproof up
handshake() { incus exec target -- wg show orbit latest-handshakes | awk -v k="$peer_public" '$1==k {print $2}'; }
deadline=$((SECONDS + 60))
until [ "$(handshake)" -gt 0 ] 2>/dev/null; do
  [ "$SECONDS" -lt "$deadline" ] || { echo 'proof peer never handshook with the hub' >&2; exit 1; }
  sleep 2
done
incus exec target -- wg show orbit transfer | awk -v k="$peer_public" '$1==k {print $2}' > "$state/rx.before"
