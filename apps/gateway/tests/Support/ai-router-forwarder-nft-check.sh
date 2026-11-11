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
socket_check="$(dirname "$0")/ai-router-forwarder-socket.py"
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
    [[ -n "${ready_directory:-}" ]] && rm -rf "$ready_directory"
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
# Fix the fixture's neighbour mappings: a first-packet ARP exchange is not part of the nftables proof.
ip link set ofchkg0 address 02:00:00:00:77:01
ip -n ofchkg link set ofchkg1 address 02:00:00:00:77:02
ip link set "$bridge" address 02:00:00:00:78:01
ip -n ofchkc link set ofchkc1 address 02:00:00:00:78:02
ip neigh replace "$guard" lladdr 02:00:00:00:77:02 nud permanent dev ofchkg0
ip -n ofchkg neigh replace 169.254.77.1 lladdr 02:00:00:00:77:01 nud permanent dev ofchkg1
ip neigh replace "${address%.*}.2" lladdr 02:00:00:00:78:02 nud permanent dev "$bridge"
ip -n ofchkc neigh replace "$address" lladdr 02:00:00:00:78:01 nud permanent dev ofchkc1

# Each listener acknowledges bind/listen through a pipe. No request or sleep stands in for readiness.
ready_directory=$(mktemp -d -t ofchk-ready.XXXXXX)
mkfifo "$ready_directory/guard" "$ready_directory/forwarder"
exec {guard_ready}<>"$ready_directory/guard"
exec {forwarder_ready}<>"$ready_directory/forwarder"
ip netns exec ofchkg python3 "$socket_check" listen "$guard" "$port" >"$ready_directory/guard" &
guard_server=$!
python3 "$socket_check" listen "$address" "$forwarder_port" >"$ready_directory/forwarder" &
forwarder_server=$!
for descriptor in "$guard_ready" "$forwarder_ready"; do
    if ! read -r -t 2 -u "$descriptor" state || [[ $state != ready ]]; then
        echo 'FAIL: listener did not acknowledge bind/listen' >&2
        exit 1
    fi
done
exec {guard_ready}>&-
exec {forwarder_ready}>&-
id -u "$user" >/dev/null 2>&1 || { useradd --system --no-create-home --shell /usr/sbin/nologin "$user"; made_user=1; }

# Only the TCP handshake is under test. HTTP response scheduling and filesystem access are unrelated to the table.
get() { python3 "$socket_check" connect "$@"; }
container() { ip netns exec ofchkc python3 "$socket_check" connect "$@"; }

expect() {
    local want=$1 what=$2 code=0 got
    shift 2
    "$@" || code=$?
    case $code in 0) got=reached ;; 7) got=refused ;; 28) got=dropped ;; *) got="failed with exit $code" ;; esac
    [[ $got == "$want" ]] || { echo "FAIL: $what: $got, expected $want" >&2; exit 1; }
    echo "ok: $what: $got"
}

expect reached 'container to the guard, without the table' container "$guard" "$port"
expect reached 'root to the guard, without the table' get "$guard" "$port"
expect reached 'root to the forwarder, without the table' get "$address" "$forwarder_port"
nft --file "$ruleset"
expect dropped 'container to the guard, behind a FORWARD accept' container "$guard" "$port"
expect reached 'container to the forwarder, on the bridge' container "$address" "$forwarder_port"
expect refused 'root to the guard' get "$guard" "$port"
expect refused 'root to the forwarder, over lo' get "$address" "$forwarder_port"
expect reached 'the forwarder user to the guard' runuser -u "$user" -- python3 "$socket_check" connect "$guard" "$port"
nft --file "$ruleset"
expect dropped 'container to the guard, after loading the table again' container "$guard" "$port"
expect refused 'root to the forwarder, after loading the table again' get "$address" "$forwarder_port"
