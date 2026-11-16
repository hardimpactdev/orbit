#!/bin/sh
# SPIKE(signed-orders) round 2: the test script's freeze between orders. Never on the order's path.
for t in "$@"; do
  echo 1 > "/sys/fs/cgroup/system.slice/orbit-spike-target-$t.service/cgroup.freeze"
done
for t in "$@"; do
  until grep -qx 'frozen 1' "/sys/fs/cgroup/system.slice/orbit-spike-target-$t.service/cgroup.events"; do sleep 0.01; done
done
