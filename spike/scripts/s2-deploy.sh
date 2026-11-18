set -e
P=orbit-instances-nested
X() { timeout 120 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
X sh -c 'cat > /tmp/s2-files.tar' < /tmp/s2-files.tar; X su - agent -c 'cd /srv/workspace && tar -xf /tmp/s2-files.tar'
X sh -c 'cat > /tmp/s2flag.php' < /tmp/s2flag.php; X incus file push /tmp/s2flag.php nested-gateway/home/orbit/orbit/apps/gateway/s2flag.php --mode 0644 >/dev/null
X sh -c 'cat > /tmp/orbit-executor' < ~/dw-spike-orbit/apps/agent/target/release/orbit-executor
for n in nested-app-dev nested-app-prod nested-gateway; do
  X incus exec $n -- systemctl stop orbit-executor
  X incus file push /tmp/orbit-executor $n/usr/local/libexec/orbit-executor --mode 0755
  X incus exec $n -- bash -c 'systemctl start orbit-executor; sleep 1; echo "$(hostname): $(systemctl is-active orbit-executor) $(journalctl -u orbit-executor --since -3s --no-pager -o cat | grep "nats bus" | tail -1)"'
done
G systemctl restart spike-nats-receipts orbit-gateway-horizon; sleep 5
G su - orbit -s /bin/bash -c 'cd ~/orbit/apps/gateway; php s2flag.php on'
