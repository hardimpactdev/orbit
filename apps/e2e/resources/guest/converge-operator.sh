#!/usr/bin/env bash
set -euo pipefail
umask 077
# incus exec starts in /root, which the orbit account cannot enter; child
# processes spawned by the CLI need a readable working directory.
cd /
orbit=/home/orbit/orbit/apps/cli/orbit
as_orbit=(sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite)
node_id() {
  php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$1"
}
case ${1-} in
  grant)
    # On the Gateway: give the operator Node access to the Gateway Node, through the Gateway at its WireGuard address.
    [[ "$(id -u)" -eq 0 ]] && exec "${as_orbit[@]}" bash "$0" "$@"
    [[ $# -eq 4 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$4" =~ ^10\.44\.0\.[1-9][0-9]{0,2}$ ]] || { echo "converge-operator: invalid grant arguments" >&2; exit 64; }
    ca=/home/orbit/.orbit/ca/root.pem
    [[ -s "$ca" ]] || { echo "converge-operator: missing root CA at $ca" >&2; exit 66; }
    if ! output=$("$orbit" gateway:add "https://$4" --name=e2e --ca="$ca" --use --json 2>&1); then
      printf 'gateway:add failed: %s\n' "$output" >&2
      exit 1
    fi
    if ! nodes=$("$orbit" node:list --json 2>&1); then
      printf 'node:list failed: %s\n' "$nodes" >&2
      exit 1
    fi
    operator_id=$(node_id "$2" <<<"$nodes")
    gateway_id=$(node_id "$3" <<<"$nodes")
    if ! output=$("$orbit" node:access:add "$operator_id" "$gateway_id" --json 2>&1); then
      printf 'node:access:add failed: %s\n' "$output" >&2
      exit 1
    fi
    ;;
  configure-cli)
    # On the operator: trust the topology's CA and point the CLI at its Gateway.
    [[ "$(id -u)" -eq 0 ]] && exec "${as_orbit[@]}" bash "$0" "$@"
    [[ $# -eq 2 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || exit 64
    ca=/home/orbit/.orbit/e2e-gateway-root-ca.pem
    [[ -s "$ca" ]] || { echo 'e2e.fixture_ca_missing' >&2; exit 66; }
    "$orbit" gateway:add "https://$2" --name=e2e --ca="$ca" --use --json
    ;;
  role-add)
    # On the operator: add one declared role to a workload Node through the topology Gateway. With CLUSTER, the Node
    # first joins that Cluster, creating it when it is missing: a role such as ingress needs a Cluster of its own.
    # Usage: role-add NODE ROLE [CLUSTER]
    [[ "$(id -u)" -eq 0 ]] && exec "${as_orbit[@]}" bash "$0" "$@"
    [[ ($# -eq 3 || $# -eq 4) && "$2" =~ ^[a-z][a-z0-9-]{0,22}$ && "$3" =~ ^[a-z][a-z0-9-]{0,31}$ ]] || { echo "converge-operator: invalid role-add arguments" >&2; exit 64; }
    [[ $# -eq 3 || "$4" =~ ^[a-z][a-z0-9-]{0,62}$ ]] || { echo "converge-operator: invalid role-add arguments" >&2; exit 64; }
    if [[ $# -eq 4 ]]; then
      if ! clusters=$("$orbit" cluster:list --json 2>&1); then
        printf 'cluster:list failed: %s\n' "$clusters" >&2
        exit 1
      fi
      cluster_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach ($v["clusters"] as $c) { if (($c["name"] ?? null) === $argv[1]) { echo $c["id"]; } }' -- "$4" <<<"$clusters")
      if [[ -z "$cluster_id" ]]; then
        if ! created=$("$orbit" cluster:create "$4" --json 2>&1); then
          printf 'cluster:create failed: %s\n' "$created" >&2
          exit 1
        fi
        cluster_id=$(php -r 'echo json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR)["id"];' <<<"$created")
      fi
      if ! nodes=$("$orbit" node:list --json 2>&1); then
        printf 'node:list failed: %s\n' "$nodes" >&2
        exit 1
      fi
      node=$(node_id "$2" <<<"$nodes")
      member=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach ($v["nodes"] as $n) { if ($n["id"] === (int) $argv[1]) { echo $n["cluster_id"] ?? ""; } }' -- "$node" <<<"$nodes")
      if [[ "$member" != "$cluster_id" ]]; then
        if ! output=$("$orbit" cluster:node:add "$cluster_id" "$node" --json 2>&1); then
          printf 'cluster:node:add %s %s failed: %s\n' "$cluster_id" "$2" "$output" >&2
          exit 1
        fi
      fi
    fi
    if [[ "$3" == router ]]; then
      # Router is protected from generic role mutation. Its product path owns the Cluster assignment.
      nodes=$("$orbit" node:list --json)
      node=$(node_id "$2" <<<"$nodes")
      cluster_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach ($v["nodes"] as $n) { if ($n["id"] === (int) $argv[1] && is_int($n["cluster_id"] ?? null) && $n["cluster_id"] > 0) { echo $n["cluster_id"]; } }' -- "$node" <<<"$nodes")
      [[ -n "$cluster_id" ]] || { echo 'e2e.router_cluster_required' >&2; exit 65; }
      "$orbit" cluster:router:set "$cluster_id" "$node" --json
    elif ! output=$("$orbit" node:role:add "$2" "$3" --json 2>&1); then
      printf 'node:role:add %s %s failed: %s\n' "$2" "$3" "$output" >&2
      exit 1
    fi
    ;;
  *) exit 64 ;;
esac
