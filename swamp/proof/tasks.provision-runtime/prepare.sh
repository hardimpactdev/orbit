#!/usr/bin/env bash
# tasks.provision workspace runtime fixture, root on the task VM from the staged checkout.
# Args: target, metrics, peer and gateway Node ids.
# As tasks.provision-image-fallback: the target is a configured Incus task VM host with a promoted base image, the
# metrics Node the VPN hub record, the peer the Router of the dev Cluster (TLD proof). Project 92 is a routed
# laravel-app with one development Process definition, created through the API. Group 920 waits for provision. Never prints a secret.
set -euo pipefail
target_id=$1 metrics_id=$2 peer_id=$3 gateway_id=$4
promoted=$(date -u -d '-1 hour' '+%Y-%m-%d %H:%M:%S')
fingerprint=$(printf 'proof-image-fallback' | sha256sum | cut -d' ' -f1)
old_identity="sha256:$(printf 'proof-older-recipe' | sha256sum | cut -d' ' -f1)"
management=$(openssl rand -hex 24)
fixture=$(cat <<JSON
{"roles": {"$metrics_id": ["vpn"]}, "vpn_subnet": "10.231.0.0/24", "extensions": ["tasks"],
 "clusters": [{"name": "proof-task-vms", "tld": "proof", "node_ids": ["$peer_id"], "router_node_id": "$peer_id"}],
 "node_updates": [{"id": $target_id, "attributes": {"settings": {"apps": {"path": "/home/orbit/apps"}, "task_vms": {"cidr": "10.252.10.0/24", "max_vms": 4}}}}],
 "gateway_settings": [{"key": "ai_router.enabled", "value": "1"}, {"key": "ai_router.node_id", "value": "$gateway_id"},
   {"key": "ai_router.router_url", "value": "http://10.231.0.1:8317"}, {"key": "ai_router.management_key", "value": "$management", "secret": true}],
 "task_vm_image_builds": [{"id": 77, "host_node_id": $target_id, "recipe_identity": "$old_identity", "recipe_generation": 1, "state": "succeeded",
   "image_fingerprint": "$fingerprint", "promoted_at": "$promoted",
   "images": {"base": {"project": "orbit-tasks", "alias": "orbit-task-base-proof-77", "fingerprint": "$fingerprint", "type": "virtual-machine"}}}],
 "projects": [{"id": 92, "name": "Proof workspace runtime", "slug": "proof-runtime", "type": "laravel-app", "root": "public",
   "repository_url": "https://github.com/octocat/Hello-World.git", "default_branch": "master", "source_access": "github_app", "task_workspace_routed": true}],
 "task_groups": [{"id": 920, "project_id": 92, "title": "Workspace runtime", "brief": "Proof", "status": "todo", "orchestration": "external",
   "subtasks": [{"id": 9201, "position": 1, "title": "Proof", "brief": "Proof", "status": "todo", "deliverables": [{"id": "review", "type": "review", "description": "Reviewed."}]}]}]}
JSON
)
printf '%s' "$fixture" | runuser -u orbit -- env -C apps/gateway php ../../tools/swamp-proof-guest/gateway.php fixture
# shellcheck disable=SC2016 # PHP source: its $app is PHP's, not the shell's.
cluster=$(runuser -u orbit -- env -C apps/gateway php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\Cluster::query()->where("name", "proof-task-vms")->value("id");')
[ -n "$cluster" ] || { echo 'the proof Cluster was not recorded' >&2; exit 1; }
# The hub public key every peer config names; the Gateway reads it from its WireGuard directory.
home=$(sed -n 's/^ORBIT_HOME=//p' apps/gateway/.env)
install -d -m 0700 -o orbit -g orbit "$home/wireguard"
openssl rand -base64 32 > "$home/wireguard/public.key"
chown orbit:orbit "$home/wireguard/public.key"
# Task VM settings come from .env, as in production. Rewrite in place: the file keeps its owner and mode.
settings=$(grep -v '^ORBIT_TASK_VMS_' apps/gateway/.env)
printf '%s\nORBIT_TASK_VMS_ENABLED=true\nORBIT_TASK_VMS_DEV_CLUSTER_ID=%s\nORBIT_TASK_VMS_WIREGUARD_RANGE=10.231.0.128/25\nORBIT_TASK_VMS_MODEL_PROXY_ORIGIN=http://10.231.0.1:8317\nORBIT_TASK_VMS_PI_ARTIFACT_PATH=/home/orbit/.orbit/proof-pi\nORBIT_TASK_VMS_PI_ARTIFACT_SHA256=%s\nORBIT_TASK_VMS_PI_MODELS=["proof-model"]\n' \
  "$settings" "$cluster" "$(printf 'proof-pi' | sha256sum | cut -d' ' -f1)" >apps/gateway/.env
# The Project's runtime definitions go through the operator's normal API. A failed run is not restaged, so an
# existing definition of the same name counts as recorded.
operator=$(python3 -I -c 'import json; print(json.load(open("swamp/proof/topologies/minimal/topology.json"))["operator"]["address"])')
define() {
  # path, body; the reply decides, so a refused request reaches the check below
  reply=$(printf '{"caller": "%s", "method": "POST", "path": "%s", "body": %s, "key": "proof-runtime-%s", "seconds": 30, "operation": false}' "$operator" "$1" "$2" "$3" \
    | runuser -u orbit -- env -C apps/gateway php ../../tools/swamp-proof-guest/gateway.php request | tail -n 1) || true
  printf '%s' "$reply" | python3 -I -c 'import json, sys; r = json.load(sys.stdin); ok = r.get("http_status") in (200, 201) or r.get("response", {}).get("error", {}).get("code") == "process_definition.name_taken"; sys.exit(0 if ok else "definition refused: " + json.dumps(r))'
}
define projects/92/process-definitions '{"name": "worker", "environments": ["development"], "spec": {"runtime": "systemd", "command": ["/usr/bin/php", "artisan", "queue:work"]}}' process
