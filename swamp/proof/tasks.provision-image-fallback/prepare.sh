#!/usr/bin/env bash
# tasks.provision image fallback fixture, root on the task VM from the staged checkout.
# Args: target, metrics, peer and gateway Node ids.
# The target becomes a configured Incus task VM host whose only promoted base image belongs to an older recipe,
# promoted one hour ago. The metrics Node is the VPN hub record, the peer the Router of the dev Cluster, and the
# ai-router settings name this VM (Node 209) as the model router owner. Three todo groups wait for provision:
# 910 and 912 take the default, 911 declares current_recipe. No GitHub App is configured, so the start commit
# comes from a public repository through git ls-remote. Never prints a secret.
set -euo pipefail
target_id=$1 metrics_id=$2 peer_id=$3 gateway_id=$4
promoted=$(date -u -d '-1 hour' '+%Y-%m-%d %H:%M:%S')
fingerprint=$(printf 'proof-image-fallback' | sha256sum | cut -d' ' -f1)
old_identity="sha256:$(printf 'proof-older-recipe' | sha256sum | cut -d' ' -f1)"
management=$(openssl rand -hex 24)
group() {
  # id, current_recipe
  printf '{"id": %s, "project_id": 91, "title": "Image fallback %s", "brief": "Proof", "status": "todo", "orchestration": "external", "current_recipe": %s, "subtasks": [{"id": %s1, "position": 1, "title": "Proof", "brief": "Proof", "status": "todo", "deliverables": [{"id": "review", "type": "review", "description": "Reviewed."}]}]}' "$1" "$1" "$2" "$1"
}
fixture=$(cat <<JSON
{"roles": {"$metrics_id": ["vpn"]}, "vpn_subnet": "10.231.0.0/24", "extensions": ["tasks"],
 "clusters": [{"name": "proof-task-vms", "node_ids": ["$peer_id"], "router_node_id": "$peer_id"}],
 "node_updates": [{"id": $target_id, "attributes": {"settings": {"apps": {"path": "/home/orbit/apps"}, "task_vms": {"cidr": "10.252.10.0/24", "max_vms": 4}}}}],
 "gateway_settings": [{"key": "ai_router.enabled", "value": "1"}, {"key": "ai_router.node_id", "value": "$gateway_id"},
   {"key": "ai_router.router_url", "value": "http://10.231.0.1:8317"}, {"key": "ai_router.management_key", "value": "$management", "secret": true}],
 "task_vm_image_builds": [{"id": 77, "host_node_id": $target_id, "recipe_identity": "$old_identity", "recipe_generation": 1, "state": "succeeded",
   "image_fingerprint": "$fingerprint", "promoted_at": "$promoted",
   "images": {"base": {"project": "orbit-tasks", "alias": "orbit-task-base-proof-77", "fingerprint": "$fingerprint", "type": "virtual-machine"}}}],
 "projects": [{"id": 91, "name": "Proof image fallback", "slug": "proof-image-fallback", "type": "node-package", "root": ".",
   "repository_url": "https://github.com/octocat/Hello-World.git", "default_branch": "master", "source_access": "github_app", "task_workspace_routed": false}],
 "task_groups": [$(group 910 false), $(group 911 true), $(group 912 false)]}
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
