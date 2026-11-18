P=orbit-instances-nested
X() { timeout 300 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
show() { X su - agent -c "cd /srv/workspace/apps/cli; php orbit operation:show $1 --json 2>/dev/null" | python3 -c '
import json,sys
from datetime import datetime as D
d=json.load(sys.stdin); d=d.get("data",d); f=lambda s: D.fromisoformat(s.replace("Z","+00:00"))
tot=(f(d["finished_at"])-f(d["created_at"])).total_seconds() if d.get("finished_at") else -1
print("  op %s %s error=%s total %.1fs" % (d["id"], d["status"], d.get("error_code"), tot))
for s in d.get("steps",[]):
  if s.get("started_at") and s.get("finished_at"): print("     %-28s %5.2fs" % (s["name"], (f(s["finished_at"])-f(s["started_at"])).total_seconds()))
'; }
T=$(date +%H%M)
G su - orbit -s /bin/bash -c 'cd ~/orbit/apps/gateway; php s2flag.php off' >/dev/null
G su - orbit -s /bin/bash -c 'cd ~/orbit/apps/gateway; php s2flag.php on' >/dev/null
echo "== Engine"
X su - agent -c "cd /srv/workspace/apps/cli; timeout 280 php orbit instance:create 2 3 en$T --no-interaction 2>&1" | tail -6
A spike:sm last
