#!/usr/bin/env bash
# Proves the Instance egress proxy's nftables table on a real kernel (/reference/development-containers#egress-and-dns).
# Usage, as root: instance-egress-nft-check.sh RULESET PROXY_USER BRIDGE BRIDGE_ADDRESS PROXY_PORT
# RULESET must be rendered for this BRIDGE and BRIDGE_ADDRESS.
#
# A namespace stands in for a container: its veth is a port of BRIDGE, which holds BRIDGE_ADDRESS. Two more namespaces stand in for a
# public host, at 198.51.100.1, and a private one, at 10.99.0.1. A dummy interface named `orbit` stands in for WireGuard, with a public
# range behind it. A listener on BRIDGE_ADDRESS stands in for the proxy. After the table, the container reaches the proxy and root does
# not; PROXY_USER reaches the public host and the stub resolver, and is refused at once by the private host, the Node's own addresses
# and WireGuard. A refusal must be a reject (curl exit 7), not a timeout. Every name this script makes carries `oechk` and goes again.
set -euo pipefail

ruleset=$1 user=$2 bridge=$3 address=$4 port=$5
table=$(sed -n 's/^table inet \([a-z_]*\) {$/\1/p' "$ruleset")
made_user=0
if nft list table inet "$table" >/dev/null 2>&1; then
    echo "The table $table exists on this host already; run the check elsewhere." >&2
    exit 2
fi
for interface in "$bridge" orbit; do
    if ip link show "$interface" >/dev/null 2>&1; then
        echo "The interface $interface exists on this host already; run the check elsewhere." >&2
        exit 2
    fi
done

cleanup() {
    set +e
    for server in "${servers[@]}"; do kill "$server" 2>/dev/null; done
    nft delete table inet "$table" 2>/dev/null
    for name in oechkc oechkp oechkv; do ip netns del "$name" 2>/dev/null; done
    for name in oechkc0 oechkp0 oechkv0 orbit "$bridge"; do ip link del "$name" 2>/dev/null; done
    [[ $made_user == 1 ]] && userdel "$user" 2>/dev/null
}
servers=()
trap cleanup EXIT

# `nsenter`, not `ip -n` or `ip netns exec`: both remount /sys, and so cannot run in an unprivileged container.
inns() { local name=$1; shift; nsenter --net="/run/netns/$name" "$@"; }
# A listener that answers every connection with an empty 200, and never looks a name up, as http.server does.
serve() {
    exec python3 -c 'import socket, sys
s = socket.socket(socket.AF_INET6 if ":" in sys.argv[1] else socket.AF_INET)
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind((sys.argv[1], int(sys.argv[2])))
s.listen()
while True:
    c, _ = s.accept()
    c.recv(4096)
    c.sendall(b"HTTP/1.0 200 OK\r\nContent-Length: 0\r\n\r\n")
    c.close()' "$1" "$2" >/dev/null 2>&1
}

# A host in its own namespace behind a veth, routed from this host.
remote() {
    local name=$1 host=$2 link=$3
    ip netns add "$name"
    ip link add "${name}0" type veth peer name "${name}1" netns "$name"
    ip addr add "$link/32" dev "${name}0"
    ip link set "${name}0" up
    ip route add "$host/32" dev "${name}0"
    inns "$name" ip link set lo up
    inns "$name" ip addr add "$host/32" dev "${name}1"
    inns "$name" ip link set "${name}1" up
    inns "$name" ip route add "$link" dev "${name}1"
    inns "$name" ip route add default via "$link" dev "${name}1" onlink
    inns "$name" bash -c "$(declare -f serve); serve $host 80" &
    servers+=($!)
}
remote oechkp 198.51.100.1 169.254.79.1
remote oechkv 10.99.0.1 169.254.79.2

# The container: a namespace whose veth is a port of the bridge, as an Incus container's is.
ip link add "$bridge" type bridge
ip addr add "$address/24" dev "$bridge"
ip link set "$bridge" up
ip netns add oechkc
ip link add oechkc0 type veth peer name oechkc1 netns oechkc
ip link set oechkc0 master "$bridge" up
inns oechkc ip link set lo up
inns oechkc ip addr add "${address%.*}.2/24" dev oechkc1
inns oechkc ip link set oechkc1 up
inns oechkc ip route add default via "$address"

# WireGuard: a public range behind a dummy `orbit`, where nothing answers, so only a reject ends a connection at once.
ip link add orbit type dummy
ip link set orbit up
ip route add 203.0.113.0/24 dev orbit

serve "$address" "$port" &
servers+=($!)
serve "$address" 80 &
servers+=($!)
serve 127.0.0.1 80 &
servers+=($!)
# The stub resolver: systemd-resolved where it runs, else a stand-in on its TCP port.
if ! (exec 3<>/dev/tcp/127.0.0.53/53) 2>/dev/null; then
    serve 127.0.0.53 53 &
    servers+=($!)
fi
id -u "$user" >/dev/null 2>&1 || { useradd --system --no-create-home --shell /usr/sbin/nologin "$user"; made_user=1; }

get() { curl -s -o /dev/null --max-time 2 "$1"; }
for _ in $(seq 50); do get "http://198.51.100.1/" && get "http://10.99.0.1/" && get "http://$address:$port/" && break; sleep 0.05; done

expect() {
    local want=$1 what=$2 code=0
    shift 2
    "$@" || code=$?
    case $code in 0) got=reached ;; 7) got=refused ;; *) got="failed with exit $code" ;; esac
    [[ $got == "$want" ]] || { echo "FAIL: $what: $got, expected $want" >&2; exit 1; }
    echo "ok: $what: $got"
}
container() { inns oechkc curl -s -o /dev/null --max-time 2 "$1"; }
proxy() { runuser -u "$user" -- curl -s -o /dev/null --max-time 2 "$1"; }
resolver() { runuser -u "$user" -- bash -c 'exec 3<>/dev/tcp/127.0.0.53/53' 2>/dev/null || return 7; }

expect reached 'the proxy user to a private host, without the table' proxy http://10.99.0.1/
nft --file "$ruleset"
expect reached 'container to the proxy, on the bridge' container "http://$address:$port/"
expect refused 'root to the proxy, over lo' get "http://$address:$port/"
expect reached 'the proxy user to a public host' proxy http://198.51.100.1/
expect reached 'the proxy user to the stub resolver' resolver
expect refused 'the proxy user to a private host' proxy http://10.99.0.1/
expect refused 'the proxy user to the bridge address' proxy "http://$address/"
expect refused 'the proxy user to loopback' proxy http://127.0.0.1/
expect refused 'the proxy user to WireGuard' proxy http://203.0.113.9/
expect reached 'root to a private host' get http://10.99.0.1/
nft --file "$ruleset"
expect refused 'the proxy user to a private host, after loading the table again' proxy http://10.99.0.1/
expect reached 'the proxy user to a public host, after loading the table again' proxy http://198.51.100.1/
