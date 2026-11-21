#!/bin/bash
# The one entrypoint the Gateway runs on the Swamp Node over its managed SSH, for its own Swamp tokens. It adds no grant: it mints
# only the two Gateway bases with their fixed principals (the read token stays read-only), revokes only their generations, lists
# names and expiry with the generations this Node's consumers hold, and starts this Node's own token renewal. A mint prints the
# reveal on stdout for the Gateway installer; nothing else prints a secret.
set -euo pipefail
user=${ORBIT_SWAMP_TOKEN_USER:-orbit}
home=${ORBIT_SWAMP_TOKEN_HOME:-/home/$user}
bin=${ORBIT_SWAMP_TOKEN_BIN:-/usr/local/bin/swamp}
credentials=${ORBIT_SWAMP_TOKEN_CREDENTIALS:-/etc/orbit/swamp-role-worker}
swamp_env=${ORBIT_SWAMP_TOKEN_ENV:-/usr/local/lib/orbit-swamp/swamp-env}
if [ "$(id -un)" != "$user" ] && [ "${1:-}" != renew-node ]; then
  exec sudo -n -u "$user" -- "$0" "$@"
fi
# swamp-env sources the account's Swamp Club credential env; over SSH a swamp call fails with auth_gate_blocked without it.
swamp() {
  env HOME="$home" SWAMP_HOME="$home/.swamp" SWAMP_CONFIG_DIR="$home/.config/swamp" \
    SWAMP_API_KEY_FILE="$home/.config/swamp/api-key" SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1 \
    "$swamp_env" timeout 60 "$bin" "$@" --server ws://127.0.0.1:9418 --token-file "$home/.config/swamp/serve-driver-token" --json
}
# `swamp access token list --json` and `swamp worker token list --json` are plain arrays (swamp 20261114, Node 209); an older
# {"tokens":[...]} envelope is accepted too. Any other shape fails closed.
access_tokens() {
  swamp access token list | jq -c '(if type == "array" then . elif type == "object" and (.tokens | type) == "array" then .tokens
    else error("swamp access token list returned an unknown shape") end)
    | [.[] | {name, principal: .principalId, expiresAt, state: (.effectiveState // .state)}]'
}
held() {
  # Strictly parse `<name>.<secret>` from a token file; an undelimited or malformed value returns empty, never the raw secret.
  local raw name secret
  raw=$(tr -d '\r\n' < "$1" 2>/dev/null) || return 0
  [[ "$raw" == *.* ]] || return 0
  name=${raw%%.*}
  secret=${raw#*.}
  [[ -n "$secret" && "$name" =~ ^[A-Za-z0-9][A-Za-z0-9_-]*$ ]] || return 0
  printf '%s' "$name"
}
case "${1:-}:${2:-}" in
  mint:read) principal=user:orbit-gateway-read ;;
  mint:run) principal=user:orbit-gateway ;;
  revoke:*)
    [[ "$2" =~ ^orbit-gateway-(read|run)(-[A-Za-z0-9_-]+)?$ ]] || { echo "refusing to revoke $2" >&2; exit 2; }
    swamp access token revoke "$2" >/dev/null
    exit 0 ;;
  list:)
    access=$(access_tokens)
    enrollment=$(swamp worker token list | jq -c '[.[] | {name, expiresAt, state: (.effectiveState // .state)}]')
    consumers=$(for slot in a b c; do
      printf '%s\t%s\t%s\n' "$slot" "$(held "$credentials/$slot/server.token")" "$(held "$credentials/$slot/enrollment.token")"
    done | jq -Rc --arg driver "$(held "$home/.config/swamp/serve-driver-token")" \
      '[inputs | split("\t") | {slot: .[0], access: .[1], enrollment: .[2]}] | {driver: $driver, workers: .}' -n)
    jq -nc --argjson access "$access" --argjson enrollment "$enrollment" --argjson held "$consumers" \
      '{access: $access, enrollment: $enrollment, held: $held}'
    exit 0 ;;
  renew-node:)
    exec sudo -n systemctl start orbit-swamp-token-renewal.service ;;
  *) echo "usage: $0 mint read|run | revoke <name> | list | renew-node" >&2; exit 2 ;;
esac
# Today's generation: reuse it when it is active with this principal and far from expiry (a rerun after a lost reply), otherwise
# take the next free -<n> name.
tokens=$(access_tokens)
base="orbit-gateway-$2-$(date -u +%Y%m%d)"
name=$base
attempt=1
while row=$(jq -c --arg name "$name" '.[] | select(.name == $name)' <<<"$tokens") && [ -n "$row" ]; do
  if jq -e --arg principal "$principal" --arg limit "$(date -u -d '+20 days' +%Y-%m-%dT%H:%M:%SZ)" \
    '.state == "active" and .principal == $principal and .expiresAt > $limit' <<<"$row" >/dev/null; then
    break
  fi
  attempt=$((attempt + 1))
  name="$base-$attempt"
done
if [ -z "$row" ]; then
  swamp access token mint "$name" --principal "$principal" --duration 30d >/dev/null
fi
expires=$(access_tokens | jq -er --arg name "$name" '.[] | select(.name == $name) | .expiresAt')
swamp access token reveal "$name" --yes | jq -c --arg expires "$expires" '{name, token, expired: (.expired // false), expiresAt: $expires}'
