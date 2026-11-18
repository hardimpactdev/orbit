# SPIKE: instance:create on the engine and on Swamp, alternately, one at a time, each Instance removed before the next run.
exec 9>/tmp/cmp.lock; flock -n 9 || { echo "another comparison holds the lock"; exit 1; }
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
  CLI "timeout 100 php orbit instance:destroy $1 --yes --force --no-interaction" >/dev/null
  for i in $(seq 1 60); do X incus exec nested-app-dev -- incus info --project orbit-instances instance-$1 >/dev/null 2>&1 || return; sleep 2; done
  echo "   instance-$1 still exists after its destroy"
}
run() {
  mode=$1; name=$2
  G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php s2flag.php $mode" >/dev/null
  quiet
  out=$(CLI "timeout 280 php orbit instance:create 2 3 $name --no-interaction")
  id=$(echo "$out" | grep -oE "Operation: [0-9]+" | head -1 | grep -oE "[0-9]+")
  completion=$(echo "$out" | grep -oE "Completion: [0-9]+" | head -1 | grep -oE "[0-9]+")
  read st tot inst <<< "$(total $id)"
  extra=""
  if [ $mode = on ]; then extra=$(A spike:sm last | awk '/^  (clone-0|start-0|t3wait-0|sess-0|project-0|check-0) / {print $1"="$2}' | tr "\n" " "); fi
  printf "  %-7s %-7s op %-4s %-9s %5.1f s  %s\n" $([ $mode = on ] && echo engine || echo swamp) $name $id $st $tot "$extra"
  [ -n "$completion" ] && settle $completion
  gone $inst
}
T=$(date +%H%M)
for i in $(seq 1 ${1:-3}); do run on e$T$i; run off s$T$i; done
