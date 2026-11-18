P=orbit-instances-nested
X() { timeout 170 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
for i in 1 2 3; do
  X su - agent -c "cd /srv/workspace/apps/cli; timeout 60 php orbit route:create kv$(date +%H%M)$i.spike.test --upstream=http://127.0.0.1:18080 --node=3 --no-interaction >/dev/null 2>&1"
  printf "  run %s: " $i; A spike:sm last | head -1
done
A spike:sm last | sed 's/^/    /'
G curl -s '127.0.0.1:8222/jsz?streams=true' | python3 -c 'import json,sys; d=json.load(sys.stdin); [print("  ", s["name"], s["state"]["messages"], "msgs", s["state"]["bytes"], "bytes") for a in d["account_details"] for s in a["stream_detail"] if s["name"]=="KV_CONTENT"]'
X incus exec nested-app-dev -- bash -c 'ls -la /var/lib/orbit/executor/blobs | head -4; stat -c "  cache dir %a %U" /var/lib/orbit/executor/blobs'
X sh -c 'cat > /tmp/kv-neg.py' < /tmp/kv-neg.py; X incus file push /tmp/kv-neg.py nested-app-dev/tmp/kv-neg.py >/dev/null; X incus exec nested-app-dev -- python3 /tmp/kv-neg.py; X incus exec nested-app-dev -- rm -f /tmp/kv-neg.py
