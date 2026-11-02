#!/usr/bin/env bash
# Hub filter fixture, root on the task VM from the staged checkout. Args: target, peer and gateway Node ids.
# The target becomes the vpn hub and the peer the Router of a fresh task VM Cluster; this VM (Node 209) keeps the
# gateway and websocket roles. The reserved range is the unused upper half of the proof bridge, so the filter cuts
# no Node off. The Gateway reads the task VM settings from .env, as in production.
set -euo pipefail
target_id=$1 peer_id=$2 gateway_id=$3
printf '{"roles":{"%s":["vpn"],"%s":["websocket"]},"vpn_subnet":"10.231.0.0/24","clusters":[{"name":"proof-task-vms","node_ids":["%s"],"router_node_id":"%s"}]}' \
  "$target_id" "$gateway_id" "$peer_id" "$peer_id" |
  runuser -u orbit -- env -C apps/gateway php ../../tools/swamp-proof-guest/gateway.php fixture
# shellcheck disable=SC2016 # PHP source: its $app is PHP's, not the shell's.
cluster=$(runuser -u orbit -- env -C apps/gateway php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\Cluster::query()->where("name", "proof-task-vms")->value("id");')
[ -n "$cluster" ] || { echo 'the proof Cluster was not recorded' >&2; exit 1; }
# Rewrite in place: the file keeps its owner and mode.
settings=$(grep -v '^ORBIT_TASK_VMS_' apps/gateway/.env)
printf '%s\nORBIT_TASK_VMS_DEV_CLUSTER_ID=%s\nORBIT_TASK_VMS_WIREGUARD_RANGE=10.231.0.128/25\nORBIT_TASK_VMS_MODEL_PROXY_ORIGIN=http://10.231.0.1:8317\n' \
  "$settings" "$cluster" >apps/gateway/.env
