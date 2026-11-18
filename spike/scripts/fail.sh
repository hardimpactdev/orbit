P=orbit-instances-nested
X() { timeout 300 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
F() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php s2fault.php $*"; }
CLI() { X su - agent -c "cd /srv/workspace/apps/cli; $*" 2>&1; }
X sh -c 'cat > /tmp/s2fault.php' < /tmp/s2fault.php; X incus file push /tmp/s2fault.php nested-gateway/home/orbit/orbit/apps/gateway/s2fault.php --mode 0644 >/dev/null
G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php s2flag.php on" >/dev/null
result() { CLI "php orbit operation:show $1 --json 2>/dev/null" | python3 -c '
import json,sys; d=json.load(sys.stdin); d=d.get("data",d); r=d.get("result") or {}
print("   create op %s %s error=%s failed_step=%s error_detail=%s route_withdrawn=%s instance=%s" % (d["id"], d["status"], d.get("error_code"), r.get("failed_step"), r.get("error"), r.get("route_withdrawn"), d["target_id"]))'; }
site() { X incus exec nested-app-dev -- bash -c "printf \"   Caddy has %s: %s; cert folders: %s %s\n\" $1 \$(grep -c \"https://$1\" /etc/caddy/Caddyfile) \$(ls -d /etc/caddy/orbit-certificates/app-instance-$2 2>/dev/null | wc -l) \$(ls -d /home/orbit/.orbit/certificates/app-instance-$2 2>/dev/null | wc -l)"; }
T=$(date +%H%M)
echo "== B1: the Route fails at its probe → withdraw"
F probe-0
id=$(CLI "timeout 280 php orbit instance:create 2 3 f$T --no-interaction" | grep -oE "Operation: [0-9]+" | head -1 | grep -oE "[0-9]+")
sleep 3; result $id; A spike:sm last | head -6 | sed 's/^/   /'
inst=$(CLI "php orbit operation:show $id --json 2>/dev/null" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("data",d)["target_id"])')
site f$T.spike-web.nested-dev $inst
echo "== B2: a guest step fails → the Route stays; then the same create again resumes"
F t3unit-0
id=$(CLI "timeout 280 php orbit instance:create 2 3 g$T --no-interaction" | grep -oE "Operation: [0-9]+" | head -1 | grep -oE "[0-9]+")
sleep 3; result $id
inst2=$(CLI "php orbit operation:show $id --json 2>/dev/null" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("data",d)["target_id"])')
site g$T.spike-web.nested-dev $inst2
F
id=$(CLI "timeout 280 php orbit instance:create 2 3 g$T --no-interaction" | grep -oE "Operation: [0-9]+" | head -1 | grep -oE "[0-9]+")
sleep 3; result $id; A spike:sm last | head -1 | sed 's/^/   retry: /'
A spike:sm last | awk '$2=="done" && $5 ~ /repeat/ {r++} END {print "   steps answered as repeat: " r+0}'
site g$T.spike-web.nested-dev $inst2
echo "== cleanup"
for i in $inst $inst2; do CLI "timeout 280 php orbit instance:destroy $i --yes --force --no-interaction" | grep -oE "Instance removal (succeeded|failed)" | sed "s/^/   $i: /"; done
