# SPIKE: instance:create on the engine and on Swamp, alternately, one at a time, each Instance removed before the next run.
exec 9>/tmp/cmp.lock; flock -w 600 9 || exit 1
P=orbit-instances-nested
X() { timeout 300 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
CLI() { X su - agent -c "cd /srv/workspace/apps/cli; $*" 2>&1; }
pressure() { X incus exec nested-app-dev -- head -1 /proc/pressure/cpu | sed -E 's/.*avg10=([0-9.]+).*/\1/'; }
quiet() { for i in $(seq 1 40); do p=$(pressure); awk "BEGIN{exit !($p < 15)}" && return; sleep 3; done; echo "   (cpu pressure still $p%)"; }
op() { CLI "php orbit operation:show $1 --json 2>/dev/null"; }
field() { op $1 | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('data',d)['$2'])" 2>/dev/null; }
total() { op $1 | python3 -c '
import json,sys
from datetime import datetime as D
d=json.load(sys.stdin); d=d.get("data",d); f=lambda s: D.fromisoformat(s.replace("Z","+00:00"))
print("%s %.1f %s" % (d["status"], (f(d["finished_at"])-f(d["created_at"])).total_seconds(), d["target_id"]))'; }
settle() { for i in $(seq 1 90); do st=$(field $1 status); case "$st" in succeeded|failed|cancelled) return;; esac; sleep 2; done; echo "   (operation $1 still $st)"; }
gone() {
  for n in 1 2 3; do
    out=$(CLI "timeout 280 php orbit instance:destroy $1 --yes --force --no-interaction")
    echo "$out" | grep -q "removal succeeded" && return
    sleep 5
  done
  echo "   destroy $1 failed: $(echo "$out" | grep -iE "error" | head -1)"
}
for inst in "$@"; do printf "  destroy %s: " $inst; gone $inst && echo ok; done
X incus exec nested-app-dev -- incus list --project orbit-instances -c ns -f csv | tr "\n" " "; echo
