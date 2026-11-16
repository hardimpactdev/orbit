#!/bin/sh
# SPIKE(signed-orders) round 3: a stand-in dev clone in one cgroup subtree. Runs as the main process of
# orbit-spike-target-<id>.service (Delegate=yes); the test starts and stops it, the executor never does.
#   app/   node: a big process (~420 MB resident: JS heap and buffers), like T3 or Vite
#   php/   php: a worker holding 250 MB, and `php -S` answering HTTP on 8000 + id (the readiness check)
#   small/ three small node processes and this shell
set -e
id=$1
port=$((8000 + id % 1000))
CG=/sys/fs/cgroup$(sed -n 's/^0:://p' /proc/self/cgroup)
mkdir "$CG/app" "$CG/php" "$CG/small"
echo $$ > "$CG/small/cgroup.procs"
echo +memory > "$CG/cgroup.subtree_control"
# Incus holds swap closed while a container runs; the sleep opens it and the wake closes it again.
for c in "$CG" "$CG/app" "$CG/php" "$CG/small"; do echo 0 > "$c/memory.swap.max"; done
L=/usr/local/libexec/orbit-spike-clone
sh -c "echo \$\$ > $CG/app/cgroup.procs; exec node $L/big.js" &
sh -c "echo \$\$ > $CG/php/cgroup.procs; exec php -d memory_limit=-1 $L/worker.php" &
sh -c "echo \$\$ > $CG/php/cgroup.procs; exec php -S 127.0.0.1:$port -t $L/www" >/dev/null 2>&1 &
for i in 1 2 3; do node $L/small.js & done
wait
