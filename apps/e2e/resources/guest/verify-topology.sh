#!/usr/bin/env bash
# One readiness probe on one Node of a composed topology. Prints one JSON line of passing evidence and
# exits 0, or exits nonzero with the reason on stderr. A per-Node probe is named KIND.NODE, such as
# `caddy.app-prod`; its kind selects the check, so no Node key is written here.
# Usage: verify-topology.sh PROBE SOURCE_SHA INSTANCE [ARGUMENT...]
set -euo pipefail
umask 077
[[ $# -ge 3 ]] || exit 64
probe=$1
identity=$2
instance=$3
shift 3
[[ "$identity" =~ ^[0-9a-f]{40}$ ]] || exit 64
[[ "$instance" =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]] || exit 64
as_orbit=(sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite)
orbit=/home/orbit/orbit/apps/cli/orbit
source_root=/home/orbit/orbit
source_marker=/var/lib/orbit-e2e/source-state
mount_token=$source_root/.e2e/mount-token

# A Node Caddy build writes one live Caddyfile with every site of the Node.
built_caddyfile() {
  local live_main
  live_main=$(readlink -f -- /etc/caddy/Caddyfile) || return 1
  case "$live_main" in
    /etc/caddy/orbit-versions/*/Caddyfile) ;;
    *) return 1 ;;
  esac
  [[ "$(head -n 1 -- "$live_main")" == '# Managed by Orbit: Node Caddy build' ]]
}
caddy_ready() {
  built_caddyfile || { echo 'no Node Caddy build wrote /etc/caddy/Caddyfile' >&2; return 1; }
  [[ "$(systemctl is-active caddy 2>/dev/null)" == active ]] || { echo 'caddy is not active' >&2; return 1; }
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 || { echo 'the Caddyfile is invalid' >&2; return 1; }
}

case "$probe" in
  nodes.registered)
    # Exactly the declared Nodes are active, each with at least its declared roles; the operator has none.
    [[ $# -eq 1 && "$1" =~ ^[A-Za-z0-9+/]*={0,2}$ ]] || exit 64
    nodes=$("${as_orbit[@]}" "$orbit" node:list --json)
    read -r expected observed < <(php -r '
      $required = json_decode(base64_decode($argv[1], true), true, 16, JSON_THROW_ON_ERROR);
      $listed = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR)["nodes"] ?? null;
      if (!is_array($required) || array_is_list($required) || !is_array($listed)) { fwrite(STDERR, "invalid node inventory\n"); exit(65); }
      $observed = [];
      foreach ($listed as $node) {
        $name = $node["name"] ?? null;
        if (!is_string($name) || isset($observed[$name])) { fwrite(STDERR, "invalid or duplicate Node in node:list\n"); exit(65); }
        if (($node["status"] ?? null) !== "active") { fwrite(STDERR, "Node $name is not active\n"); exit(1); }
        $roles = is_array($node["roles"] ?? null) ? $node["roles"] : [];
        sort($roles);
        $observed[$name] = $roles;
      }
      $names = array_keys($observed); sort($names); $declared = array_keys($required); sort($declared);
      if ($names !== $declared) { fwrite(STDERR, "registered Nodes [".implode(",", $names)."] differ from declared [".implode(",", $declared)."]\n"); exit(1); }
      $expected = []; $seen = [];
      foreach ($required as $name => $roles) {
        if (!is_array($roles)) exit(65);
        $missing = array_diff($roles, $observed[$name]);
        if ($missing !== []) { fwrite(STDERR, "Node $name lacks roles [".implode(",", $missing)."]\n"); exit(1); }
        if ($roles === [] && $observed[$name] !== []) { fwrite(STDERR, "Node $name must stay roleless\n"); exit(1); }
        $expected[] = $name.":".($roles === [] ? "none" : implode("+", $roles));
        $seen[] = $name.":".($observed[$name] === [] ? "none" : implode("+", $observed[$name]));
      }
      echo implode(",", $expected), " ", implode(",", $seen), "\n";
    ' -- "$1" <<<"$nodes")
    ;;
  service.gateway)
    caddy_state=$(systemctl is-active caddy 2>/dev/null || true)
    php_state=$(systemctl is-active php8.5-fpm 2>/dev/null || true)
    expected='caddy=active,php8.5-fpm=active'
    observed="caddy=$caddy_state,php8.5-fpm=$php_state"
    [[ "$observed" == "$expected" ]] || { echo "$observed" >&2; exit 1; }
    ;;
  service.vpn)
    vpn_state=$(systemctl is-active wg-quick@orbit 2>/dev/null || true)
    expected='wg-quick@orbit=active'
    observed="wg-quick@orbit=$vpn_state"
    [[ "$observed" == "$expected" ]] || { echo "$observed" >&2; exit 1; }
    ;;
  wireguard.reachability)
    # Every other Node has a WireGuard route on the Gateway and answers a ping on its address. Each argument is
    # NODE=ADDRESS, the address the Node was provisioned with.
    [[ $# -ge 1 ]] || exit 64
    routes=$(wg show orbit allowed-ips)
    names=()
    for node in "$@"; do
      name=${node%%=*} address=${node#*=}
      [[ "$name" =~ ^[a-z][a-z0-9-]{0,22}$ && "$address" =~ ^10\.44\.0\.[1-9][0-9]{0,2}$ ]] || exit 64
      awk -v expected="$address/32" '{ for (i = 1; i <= NF; i++) if ($i == expected) found = 1 } END { exit !found }' <<<"$routes" || { echo "no WireGuard route to $name at $address" >&2; exit 1; }
      ping -c 1 -W 3 "$address" >/dev/null || { echo "$name at $address does not answer over WireGuard" >&2; exit 1; }
      names+=("$name")
    done
    expected="$(IFS=,; printf '%s' "${names[*]}"):wireguard-route+ping"
    observed=$expected
    ;;
  caddy.*)
    caddy_ready
    expected='caddy=active,config=valid,node-caddy-build'
    observed=$expected
    ;;
  operator.gateway)
    [[ -s /etc/wireguard/orbit.conf ]] || { echo 'the operator has no WireGuard configuration' >&2; exit 1; }
    [[ -s /home/orbit/.orbit/e2e-gateway-root-ca.pem ]] || { echo 'the operator does not trust the topology CA' >&2; exit 1; }
    (cd / && "${as_orbit[@]}" "$orbit" gateway:status --json >/dev/null)
    (cd / && "${as_orbit[@]}" "$orbit" node:list --json >/dev/null)
    expected='operator:gateway-access+wireguard+ca'
    observed=$expected
    ;;
  source.*)
    # The worktree is mounted, and its mount token hashes to what the host recorded.
    [[ $# -eq 1 && "$1" =~ ^[0-9a-f]{64}$ ]] || exit 64
    mountpoint -q -- "$source_root" || { echo "$source_root is not a mountpoint" >&2; exit 1; }
    [[ -f "$source_root/apps/gateway/artisan" && -f "$source_marker" && -f "$mount_token" ]] || { echo 'the mounted source or its marker is missing' >&2; exit 1; }
    read -r marker_sha marker_token < <(php -r '$state = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR); $sha = $state["sha"] ?? null; $token = $state["mount_token_sha256"] ?? null; if (($state["mounted"] ?? null) !== true || !is_string($sha) || !is_string($token)) exit(65); echo $sha, " ", $token, "\n";' -- "$source_marker")
    observed_token=$(sha256sum -- "$mount_token" | cut -d ' ' -f 1)
    expected="$identity:mount-token=$1"
    observed="$marker_sha:mount-token=$observed_token"
    [[ "$marker_token" == "$1" && "$observed" == "$expected" ]] || { echo "observed $observed" >&2; exit 1; }
    ;;
  *) exit 64 ;;
esac

checked_at=$(date -u +'%Y-%m-%dT%H:%M:%SZ')
php -r 'echo json_encode(["probe" => $argv[1], "passed" => true, "identity" => $argv[2], "checked_at" => $argv[3], "expected" => $argv[4], "observed" => $argv[5], "evidence_ref" => $argv[6]], JSON_THROW_ON_ERROR), "\n";' -- "$probe" "$identity" "$checked_at" "$expected" "$observed" "incus://$instance/$probe"
