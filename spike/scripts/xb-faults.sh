P=orbit-instances-nested
X() { timeout 170 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
idle() { for i in $(seq 1 45); do n=$(X su - agent -c "cd /srv/workspace/apps/cli; php orbit operation:list --json 2>/dev/null" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(sum(1 for r in (d.get("operations") or [])[:15] if r.get("status") in ("running","waiting","queued")))'); [ "$n" = 0 ] && return; sleep 2; done; echo "  (still busy after 90 s)"; }
fault() { label=$1; dom=$2; shift 2; idle; sleep 4; ( X su - agent -c "cd /srv/workspace/apps/cli; timeout 100 php orbit route:create $dom --upstream=http://127.0.0.1:18080 --node=3 --no-interaction >/dev/null 2>&1"; printf "  %-50s " "$label"; A spike:sm last | head -1 ) & sleep 0.3; "$@"; wait; }
nx() { timeout 60 incus exec instance-3712 --project orbit-instances-nested -- incus exec "$@"; }
echo "tick: $(G systemctl is-active spike-sm-tick)"
fault "executor on app-dev restarted at +0.3 s" y1.spike.test nx nested-app-dev -- systemctl restart orbit-executor
fault "app-dev NATS connection refused 8 s" y2.spike.test sh -c 'nx() { timeout 60 incus exec instance-3712 --project orbit-instances-nested -- incus exec "$@"; }; nx nested-app-dev -- iptables -I OUTPUT -d 10.45.0.1 -p tcp --dport 4222 -j REJECT --reject-with tcp-reset; nx nested-app-dev -- ss -K dst 10.45.0.1 dport = 4222 >/dev/null 2>&1; sleep 8; nx nested-app-dev -- iptables -D OUTPUT -d 10.45.0.1 -p tcp --dport 4222 -j REJECT --reject-with tcp-reset'
fault "Gateway receipt consumer stopped 8 s" y3.spike.test sh -c 'nx() { timeout 60 incus exec instance-3712 --project orbit-instances-nested -- incus exec "$@"; }; nx nested-gateway -- systemctl stop spike-nats-receipts; sleep 8; nx nested-gateway -- systemctl start spike-nats-receipts'
fault "nats-server restarted at +0.3 s" y4.spike.test nx nested-gateway -- systemctl restart nats-server
idle; sleep 3
echo "  long step (20 s, ack_wait 5 s):"
A spike:sm start --count=1 --long=20 --port=19600 --wait | sed 's/^/    /' | head -6
echo "== duplicates"
X incus exec nested-app-dev -- bash -c 'for d in y1 y2 y3 y4; do printf "  %s caddy-sites:%s  " $d "$(grep -c "$d.spike.test" /etc/caddy/Caddyfile)"; done; echo; printf "  rules 19600-19602 on app-dev: %s (want 4: 19600 v4+v6, 19602 v4+v6)\n" "$(ufw status numbered | grep -cE "1960[02]/tcp")"; echo "  wait-long ran: $(journalctl -u orbit-executor --since -3min --no-pager -o cat | grep -c "spike.wait") log lines with spike.wait (refusals/recoveries only)"'
X incus exec nested-app-prod -- bash -c 'printf "  rule 19601 on app-prod: %s (want 2)\n" "$(ufw status numbered | grep -cE "19601/tcp")"'
G bash -c 'printf "  dns records y1-y4: "; for d in y1 y2 y3 y4; do printf "%s " "$(grep -c "$d.spike.test" /etc/dnsmasq.d/orbit-records.conf)"; done; echo; curl -s 127.0.0.1:8222/varz | python3 -c "import json,sys; v=json.load(sys.stdin); print(\"  nats-server mem\", v[\"mem\"]//1048576, \"MB\")"'
