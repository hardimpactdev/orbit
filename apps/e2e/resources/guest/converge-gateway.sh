#!/usr/bin/env bash
set -euo pipefail
umask 077
checkout=/home/orbit/orbit
gateway=$checkout/apps/gateway
orbit_env=(sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite)
# PHP is never installed here: the recipe base image carries the recipe's exact PHP build (ADR 0216).
gateway_prerequisites() {
  local package
  local -a missing=()
  for package in caddy dnsmasq; do
    if [[ "$(dpkg-query -W -f='${Status}' "$package" 2>/dev/null || true)" != 'install ok installed' ]]; then
      missing+=("$package")
    fi
  done
  if ((${#missing[@]} > 0)); then
    printf 'e2e.gateway_prerequisite_missing: %s\n' "${missing[*]}" >&2
    return 68
  fi
}
case ${1-} in
  prerequisites)
    [[ $# -eq 1 ]]
    gateway_prerequisites
    # A restored environment may still name the retired standalone checkout.
    if [[ -f "$gateway/.env" ]]; then
      sed -i '/^ORBIT_GATEWAY_CHECKOUT=/d' "$gateway/.env"
      printf 'ORBIT_GATEWAY_CHECKOUT=%s\n' "$gateway" >>"$gateway/.env"
    fi
    ;;
  migrate)
    [[ $# -eq 1 ]]
    install -d -o orbit -g orbit -m 0700 /home/orbit/.orbit
    touch /home/orbit/.orbit/gateway.sqlite
    chown orbit:orbit /home/orbit/.orbit/gateway.sqlite
    chmod 0600 /home/orbit/.orbit/gateway.sqlite
    # The Gateway reads its encryption key from ORBIT_HOME, as on any Gateway (bin/bootstrap).
    if ! "${orbit_env[@]}" "$checkout/bin/gateway-app-key"; then
      exit 69
    fi
    cd "$gateway"
    if ! "${orbit_env[@]}" php artisan migrate --force; then
      exit 70
    fi
    ;;
  seed)
    [[ $# -eq 1 ]]
    cd "$gateway"
    "${orbit_env[@]}" php "$checkout/tools/swamp-proof-seed.php" "$gateway"
    ;;
  fixture-gateway-leaf)
    [[ $# -eq 1 ]] || exit 64
    "${orbit_env[@]}" php /usr/local/bin/topology-gateway-certificate.php
    ;;
  fixture-ca)
    [[ $# -eq 1 ]] || exit 64
    "${orbit_env[@]}" php /usr/local/bin/topology-ca.php
    ;;
  configure-mounted)
    [[ $# -eq 1 ]] || exit 64
    cd "$gateway"
    candidate=$(mktemp /etc/caddy/orbit-topology.XXXXXX)
    trap 'rm -f -- "$candidate"' EXIT
    "${orbit_env[@]}" php /usr/local/bin/topology-gateway-services.php >"$candidate"
    caddy validate --config "$candidate" --adapter caddyfile >/dev/null
    install -o root -g caddy -m 0644 "$candidate" /etc/caddy/Caddyfile
    systemctl enable --now php8.5-fpm caddy
    systemctl reload caddy
    printf '%s\n' '{"gateway_install":"mounted_source","release_runner_exercised":false}'
    ;;
  services)
    [[ $# -eq 1 ]] || exit 64
    systemctl is-active --quiet caddy php8.5-fpm dnsmasq
    curl --fail --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 10 \
      --cacert /home/orbit/.orbit/ca/root.pem --resolve gateway.orbit:443:10.44.0.1 https://gateway.orbit/up >/dev/null
    ;;
  *) exit 64 ;;
esac
