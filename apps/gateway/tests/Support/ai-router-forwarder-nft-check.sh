#!/usr/bin/env bash
# Proves the container model forwarder's nftables table on a real kernel (/reference/ai-router#container-model-forwarder).
# Usage, as root: ai-router-forwarder-nft-check.sh RULESET GUARD_ADDRESS GUARD_PORT FORWARDER_USER
#
# The guard sits in its own network namespace at GUARD_ADDRESS. A second namespace stands in for a container: its packets to the guard are
# forwarded by this host, and a FORWARD accept at the top of iptables stands in for Docker's. Before the table, the container and root
# reach the guard; after it, neither does, and only FORWARDER_USER does. Every name this script makes carries `ofchk` and goes again.
set -euo pipefail

ruleset=$1 guard=$2 port=$3 user=$4
table=$(sed -n 's/^table inet \([a-z_]*\) {$/\1/p' "$ruleset")
made_user=0
if nft list table inet "$table" >/dev/null 2>&1; then
    echo "The table $table exists on this host already; run the check elsewhere." >&2
    exit 2
fi

cleanup() {
    set +e
    [[ -n "${server:-}" ]] && kill "$server" 2>/dev/null
    nft delete table inet "$table" 2>/dev/null
    iptables -D FORWARD -i ofchkc0 -j ACCEPT -m comment --comment ofchk 2>/dev/null
    iptables -D FORWARD -o ofchkc0 -j ACCEPT -m comment --comment ofchk 2>/dev/null
    ip netns del ofchkg 2>/dev/null
    ip netns del ofchkc 2>/dev/null
    ip link del ofchkg0 2>/dev/null
    ip link del ofchkc0 2>/dev/null
    [[ $made_user == 1 ]] && userdel "$user" 2>/dev/null
    sysctl -qw net.ipv4.ip_forward="$forward" 2>/dev/null
}
forward=$(sysctl -n net.ipv4.ip_forward)
trap cleanup EXIT

# A namespace behind a veth, its address on the namespace side, routed from the host.
attach() {
    local ns=$1 host_side=$2 ns_side=$3 address=$4 gateway=$5
    ip netns add "$ns"
    ip link add "$host_side" type veth peer name "$ns_side" netns "$ns"
    ip addr add "$gateway/32" dev "$host_side"
    ip link set "$host_side" up
    ip route add "$address/32" dev "$host_side"
    ip -n "$ns" link set lo up
    ip -n "$ns" addr add "$address/32" dev "$ns_side"
    ip -n "$ns" link set "$ns_side" up
    ip -n "$ns" route add "$gateway" dev "$ns_side"
    ip -n "$ns" route add default via "$gateway" dev "$ns_side" onlink
}

attach ofchkg ofchkg0 ofchkg1 "$guard" 169.254.77.1
attach ofchkc ofchkc0 ofchkc1 169.254.78.2 169.254.78.1
sysctl -qw net.ipv4.ip_forward=1
iptables -I FORWARD 1 -i ofchkc0 -j ACCEPT -m comment --comment ofchk
iptables -I FORWARD 1 -o ofchkc0 -j ACCEPT -m comment --comment ofchk
ip netns exec ofchkg python3 -m http.server "$port" --bind "$guard" >/dev/null 2>&1 &
server=$!
id -u "$user" >/dev/null 2>&1 || { useradd --system --no-create-home --shell /usr/sbin/nologin "$user"; made_user=1; }

reach() { curl -s -o /dev/null --max-time 3 "http://$guard:$port/"; }
for _ in $(seq 50); do reach && break; sleep 0.1; done

expect() {
    local want=$1 what=$2
    shift 2
    if "$@"; then got=reached; else got=refused; fi
    [[ $got == "$want" ]] || { echo "FAIL: $what: $got, expected $want" >&2; exit 1; }
    echo "ok: $what: $got"
}

expect reached 'container, without the table' ip netns exec ofchkc curl -s -o /dev/null --max-time 3 "http://$guard:$port/"
expect reached 'root, without the table' reach
nft --file "$ruleset"
expect refused 'container, behind a FORWARD accept' ip netns exec ofchkc curl -s -o /dev/null --max-time 3 "http://$guard:$port/"
expect refused 'root on the Node' reach
expect reached 'the forwarder user' runuser -u "$user" -- curl -s -o /dev/null --max-time 3 "http://$guard:$port/"
nft --file "$ruleset"
expect refused 'container, after loading the table again' ip netns exec ofchkc curl -s -o /dev/null --max-time 3 "http://$guard:$port/"
