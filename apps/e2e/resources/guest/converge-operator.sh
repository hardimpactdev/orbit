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
  provision)
    # On the Gateway: register the operator as a roleless Node.
    [[ $# -eq 4 && "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ && "$3" =~ ^(x86_64|aarch64)$ && "$4" =~ ^10\.44\.0\.[1-9][0-9]{0,2}$ ]] || exit 64
    db=/home/orbit/.orbit/gateway.sqlite
    active=$(php -r '$pdo=new PDO("sqlite:".$argv[1]); echo $pdo->query("SELECT COUNT(*) FROM nodes WHERE name = '\''operator'\'' AND status = '\''active'\''")->fetchColumn();' -- "$db")
    [[ "$active" != 1 ]] || exit 0
    deadline=$((SECONDS + 60))
    until keys=$(ssh-keyscan -T 5 -t ed25519 -- "$2" 2>/dev/null) && [[ -n "$keys" ]]; do
      (( SECONDS < deadline )) || { echo "converge-operator: no SSH host key from $2" >&2; exit 1; }
      sleep 2
    done
    fingerprint=$(ssh-keygen -lf - -E sha256 <<<"$keys" | awk 'NR == 1 { print $2 }')
    [[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
    cd /home/orbit/orbit/apps/gateway
    "${as_orbit[@]}" ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway php artisan orbit:node-provision operator "$2" --architecture="$3" --user=orbit --wireguard-ip="$4" --host-key-fingerprint="$fingerprint" --no-interaction
    ;;
  grant)
    # On the Gateway: give the operator Node access to the Gateway Node.
    [[ "$(id -u)" -eq 0 ]] && exec "${as_orbit[@]}" bash "$0" "$@"
    [[ $# -eq 3 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || { echo "converge-operator: invalid grant arguments" >&2; exit 64; }
    ca=/home/orbit/.orbit/ca/root.pem
    [[ -s "$ca" ]] || { echo "converge-operator: missing root CA at $ca" >&2; exit 66; }
    if ! output=$("$orbit" gateway:add https://10.44.0.1 --name=e2e --ca="$ca" --use --json 2>&1); then
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
    install -d -m 0700 "$(dirname "$ca")"
    deadline=$((SECONDS + 120))
    until curl --fail --silent --show-error --insecure --max-time 10 "https://$2/api/v1/ca/root" -o "$ca.new"; do
      (( SECONDS < deadline )) || { echo "converge-operator: the Gateway at $2 did not answer" >&2; exit 1; }
      sleep 2
    done
    php -r '$v=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); file_put_contents($argv[2], $v["data"]["root_ca"]);' "$ca.new" "$ca"
    rm -f "$ca.new"
    "$orbit" gateway:add "https://$2" --name=e2e --ca="$ca" --use --json
    ;;
  role-add)
    # On the operator: add one declared role to a workload Node through the topology Gateway. Ingress needs Cluster
    # membership and allows one ingress Node per Cluster, so the Node first joins its own Cluster `e2e-<node>`.
    [[ "$(id -u)" -eq 0 ]] && exec "${as_orbit[@]}" bash "$0" "$@"
    [[ $# -eq 3 && "$2" =~ ^app-(dev|prod|prod-2)$ && "$3" =~ ^(ingress)$ ]] || { echo "converge-operator: invalid role-add arguments" >&2; exit 64; }
    if [[ "$3" == ingress ]]; then
      if ! clusters=$("$orbit" cluster:list --json 2>&1); then
        printf 'cluster:list failed: %s\n' "$clusters" >&2
        exit 1
      fi
      cluster_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach ($v["clusters"] as $c) { if (($c["name"] ?? null) === $argv[1]) { echo $c["id"]; } }' -- "e2e-$2" <<<"$clusters")
      if [[ -z "$cluster_id" ]]; then
        if ! created=$("$orbit" cluster:create "e2e-$2" --json 2>&1); then
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
    if ! output=$("$orbit" node:role:add "$2" "$3" --json 2>&1); then
      printf 'node:role:add %s %s failed: %s\n' "$2" "$3" "$output" >&2
      exit 1
    fi
    ;;
  *) exit 64 ;;
esac
