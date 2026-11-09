#!/usr/bin/env bash
# Proves the container model forwarder's nftables table on a real kernel (/reference/ai-router#container-model-forwarder).
# Usage, as root: ai-router-forwarder-nft-check.sh RULESET GUARD_ADDRESS GUARD_PORT FORWARDER_USER BRIDGE BRIDGE_ADDRESS FORWARDER_PORT
# RULESET must be rendered for this BRIDGE, BRIDGE_ADDRESS and GUARD_ADDRESS.
#
# The guard sits in its own network namespace at GUARD_ADDRESS. A second namespace stands in for a container: its veth is a port of
# BRIDGE, which holds BRIDGE_ADDRESS, and its packets to the guard are forwarded by this host. A FORWARD accept at the top of iptables
# stands in for Docker's. A listener on BRIDGE_ADDRESS stands in for the forwarder. Before the table, the container and root reach the
# guard and the forwarder; after it, only the container reaches the forwarder, only FORWARDER_USER reaches the guard, and neither the
# container nor root reaches the guard directly. Every name this script makes carries `ofchk` and goes again.
set -euo pipefail

ruleset=$1 guard=$2 port=$3 user=$4 bridge=$5 address=$6 forwarder_port=$7
table=$(sed -n 's/^table inet \([a-z_]*\) {$/\1/p' "$ruleset")
made_user=0
if nft list table inet "$table" >/dev/null 2>&1; then
    echo "The table $table exists on this host already; run the check elsewhere." >&2
    exit 2
fi
if ip link show "$bridge" >/dev/null 2>&1; then
    echo "The interface $bridge exists on this host already; run the check elsewhere." >&2
    exit 2
fi

cleanup() {
    set +e
    [[ -n "${guard_server:-}" ]] && kill "$guard_server" 2>/dev/null
    [[ -n "${forwarder_server:-}" ]] && kill "$forwarder_server" 2>/dev/null
    nft delete table inet "$table" 2>/dev/null
    iptables -D FORWARD -i "$bridge" -j ACCEPT -m comment --comment ofchk 2>/dev/null
    iptables -D FORWARD -o "$bridge" -j ACCEPT -m comment --comment ofchk 2>/dev/null
    ip netns del ofchkg 2>/dev/null
    ip netns del ofchkc 2>/dev/null
    ip link del ofchkg0 2>/dev/null
    ip link del ofchkc0 2>/dev/null
    ip link del "$bridge" 2>/dev/null
    [[ $made_user == 1 ]] && userdel "$user" 2>/dev/null
    sysctl -qw net.ipv4.ip_forward="$forward" 2>/dev/null
}
forward=$(sysctl -n net.ipv4.ip_forward)
trap cleanup EXIT

# The guard: a namespace behind a veth, its address on the namespace side, routed from the host.
ip netns add ofchkg
ip link add ofchkg0 type veth peer name ofchkg1 netns ofchkg
ip addr add 169.254.77.1/32 dev ofchkg0
ip link set ofchkg0 up
ip route add "$guard/32" dev ofchkg0
ip -n ofchkg link set lo up
ip -n ofchkg addr add "$guard/32" dev ofchkg1
ip -n ofchkg link set ofchkg1 up
ip -n ofchkg route add 169.254.77.1 dev ofchkg1
ip -n ofchkg route add default via 169.254.77.1 dev ofchkg1 onlink

# The container: a namespace whose veth is a port of the bridge, as an Incus container's is.
ip link add "$bridge" type bridge
ip addr add "$address/24" dev "$bridge"
ip link set "$bridge" up
ip netns add ofchkc
ip link add ofchkc0 type veth peer name ofchkc1 netns ofchkc
ip link set ofchkc0 master "$bridge" up
ip -n ofchkc link set lo up
ip -n ofchkc addr add "${address%.*}.2/24" dev ofchkc1
ip -n ofchkc link set ofchkc1 up
ip -n ofchkc route add default via "$address"

sysctl -qw net.ipv4.ip_forward=1
iptables -I FORWARD 1 -i "$bridge" -j ACCEPT -m comment --comment ofchk
iptables -I FORWARD 1 -o "$bridge" -j ACCEPT -m comment --comment ofchk
ip netns exec ofchkg python3 -m http.server "$port" --bind "$guard" >/dev/null 2>&1 &
guard_server=$!
python3 -m http.server "$forwarder_port" --bind "$address" >/dev/null 2>&1 &
forwarder_server=$!
id -u "$user" >/dev/null 2>&1 || { useradd --system --no-create-home --shell /usr/sbin/nologin "$user"; made_user=1; }

get() { curl -s -o /dev/null --max-time 3 "$1"; }
for _ in $(seq 50); do get "http://$guard:$port/" && get "http://$address:$forwarder_port/" && break; sleep 0.1; done

expect() {
    local want=$1 what=$2
    shift 2
    if "$@"; then got=reached; else got=refused; fi
    [[ $got == "$want" ]] || { echo "FAIL: $what: $got, expected $want" >&2; exit 1; }
    echo "ok: $what: $got"
}
container() { ip netns exec ofchkc curl -s -o /dev/null --max-time 3 "$1"; }

expect reached 'container to the guard, without the table' container "http://$guard:$port/"
expect reached 'root to the guard, without the table' get "http://$guard:$port/"
expect reached 'root to the forwarder, without the table' get "http://$address:$forwarder_port/"
nft --file "$ruleset"
expect refused 'container to the guard, behind a FORWARD accept' container "http://$guard:$port/"
expect reached 'container to the forwarder, on the bridge' container "http://$address:$forwarder_port/"
expect refused 'root to the guard' get "http://$guard:$port/"
expect refused 'root to the forwarder, over lo' get "http://$address:$forwarder_port/"
expect reached 'the forwarder user to the guard' runuser -u "$user" -- curl -s -o /dev/null --max-time 3 "http://$guard:$port/"
nft --file "$ruleset"
expect refused 'container to the guard, after loading the table again' container "http://$guard:$port/"
expect refused 'root to the forwarder, after loading the table again' get "http://$address:$forwarder_port/"
