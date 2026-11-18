set -e
P=orbit-instances-nested
X() { timeout 120 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
X sh -c 'cat > /tmp/kv-files.tar' < /tmp/kv-files.tar; X su - agent -c 'cd /srv/workspace && tar -xf /tmp/kv-files.tar'
X sh -c 'cat > /tmp/kv-perm.py' < /tmp/kv-perm.py; X incus file push /tmp/kv-perm.py nested-gateway/tmp/kv-perm.py >/dev/null
G python3 /tmp/kv-perm.py; G bash -c 'kill -HUP $(systemctl show -p MainPID --value nats-server); sleep 1; journalctl -u nats-server --since -5s --no-pager -o cat | grep -iE "reload|error" | tail -2'
X sh -c "cat > /tmp/kvsetup.php" < /tmp/kvsetup.php; X incus file push /tmp/kvsetup.php nested-gateway/home/orbit/orbit/apps/gateway/kvsetup.php --mode 0644 >/dev/null; G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php kvsetup.php"
X sh -c 'cat > /tmp/orbit-executor' < ~/dw-spike-orbit/apps/agent/target/release/orbit-executor
for n in nested-app-dev nested-app-prod nested-gateway; do
  X incus exec $n -- systemctl stop orbit-executor
  X incus file push /tmp/orbit-executor $n/usr/local/libexec/orbit-executor --mode 0755
  X incus exec $n -- bash -c 'systemctl start orbit-executor; sleep 1; echo "$(hostname): $(systemctl is-active orbit-executor) $(journalctl -u orbit-executor --since -3s --no-pager -o cat | grep "nats bus" | tail -1)"'
done
G systemctl restart spike-nats-receipts orbit-gateway-horizon; sleep 5
G curl -s '127.0.0.1:8222/jsz?streams=true' | python3 -c 'import json,sys; d=json.load(sys.stdin); [print(" ", s["name"], s["state"]["messages"], "msgs") for a in d["account_details"] for s in a["stream_detail"]]'
