#!/usr/bin/env bash
# Stops every process of the user $2 in the workspace template builder, which the setup steps or the warm-up check
# may have left running on the volume, such as a test server, then unmounts $1. The builder is deleted afterwards, so
# nothing of value runs there. Runs as root.
set -eu
user=$2
pkill -KILL -u "$user" || true
for _ in $(seq 1 50); do
    pgrep -u "$user" >/dev/null || break
    sleep 0.2
done
sync
umount -- "$1"
