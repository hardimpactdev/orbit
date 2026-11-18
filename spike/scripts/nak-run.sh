P=orbit-instances-nested
X() { timeout 170 incus exec instance-3712 --project $P -- "$@"; }
G() { X incus exec nested-gateway -- "$@"; }
A() { G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php artisan $*" 2>&1; }
X sh -c 'cat > /tmp/nak-files.tar' < /tmp/nak-files.tar; X su - agent -c 'cd /srv/workspace && tar -xf /tmp/nak-files.tar'
X sh -c "cat > /tmp/fin2.php" < /tmp/fin2.php; X incus file push /tmp/fin2.php nested-gateway/home/orbit/orbit/apps/gateway/fin2.php --mode 0644 >/dev/null; G su - orbit -s /bin/bash -c "cd ~/orbit/apps/gateway; php fin2.php 656 657"
G systemctl restart spike-nats-receipts orbit-gateway-horizon; sleep 6
echo "tick: $(G systemctl is-active spike-sm-tick)  consumer: $(G systemctl is-active spike-nats-receipts)"
for i in 1 2 3; do
  ( X su - agent -c "cd /srv/workspace/apps/cli; timeout 60 php orbit route:create nq2102$i.spike.test --upstream=http://127.0.0.1:18080 --node=3 --no-interaction >/dev/null 2>&1"; printf "  nats-server restart, run %s: " $i; A spike:sm last | head -1; A spike:sm last | grep -E "publish|cert" ) &
  sleep 0.3; G systemctl restart nats-server; wait; sleep 3
done
G journalctl -u spike-nats-receipts --since -3min --no-pager -o cat | grep -ciE "publish_failed|exception" | sed 's/^/  consumer errors logged: /'
G tail -40 /home/orbit/orbit/apps/gateway/storage/logs/spike-publish.log | grep -E "FAILED" ; G wc -l /home/orbit/orbit/apps/gateway/storage/logs/spike-publish.log
