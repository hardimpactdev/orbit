P=orbit-instances-nested
X() { timeout 300 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
CLI() { X su - agent -c "cd /srv/workspace/apps/cli; $*" 2>&1; }
quiet() { for i in $(seq 1 40); do l=$(X incus exec nested-app-dev -- cut -d" " -f1 /proc/loadavg); awk "BEGIN{exit !($l < 1.5)}" && return; sleep 3; done; echo "   (load still $l)"; }
op() { CLI "php orbit operation:show $1 --json 2>/dev/null"; }
total() { op $1 | python3 -c '
import json,sys
from datetime import datetime as D
d=json.load(sys.stdin); d=d.get("data",d); f=lambda s: D.fromisoformat(s.replace("Z","+00:00"))
print("%s %.1f %s" % (d["status"], (f(d["finished_at"])-f(d["created_at"])).total_seconds(), d["target_id"]))'; }
run() { mode=$1; name=$2
  G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php s2flag.php $mode" >/dev/null
  quiet
  id=$(CLI "timeout 280 php orbit instance:create 2 3 $name --no-interaction" | grep -oE "Operation: [0-9]+" | head -1 | grep -oE "[0-9]+")
  read st tot inst <<< "$(total $id)"
  extra=""; [ $mode = on ] && extra=$(A spike:sm last | awk '/clone-0|start-0|t3wait|sess-0|project-0|check-0/ {print $1"="$5}' | tr "\n" " ")
  printf "  %-7s %-6s op %-4s %-9s %5.1f s  %s\n" $([ $mode = on ] && echo engine || echo swamp) $name $id $st $tot "$extra"
  CLI "timeout 280 php orbit instance:destroy $inst --yes --force --no-interaction" | grep -qE "succeeded" || echo "   destroy $inst did not report success"
}
T=$(date +%H%M)
for i in 1 2 3; do run on e$T$i; run off s$T$i; done
