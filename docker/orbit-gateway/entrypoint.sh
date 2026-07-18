#!/usr/bin/env bash

set -Eeuo pipefail

app_root="${ORBIT_GATEWAY_APP_ROOT:-/srv/orbit/apps/gateway}"
config_root="${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}"
env_path="${config_root}/.env"
database_path="${config_root}/gateway.sqlite"
operations_websocket_path="${config_root}/operations-websocket"
invoked_as="$(basename "${0}")"

read_env_value() {
    local key="${1}"

    awk -F= -v key="$key" '$1 == key { print substr($0, length(key) + 2); exit }' "$env_path" 2>/dev/null || true
}

set_env_value() {
    local key="${1}"
    local value="${2}"
    local escaped_value="${value//\\/\\\\}"

    escaped_value="${escaped_value//&/\\&}"

    if grep -qE "^${key}=" "$env_path"; then
        sed -i "s|^${key}=.*|${key}=${escaped_value}|" "$env_path"

        return
    fi

    printf '%s=%s\n' "$key" "$value" >> "$env_path"
}

ensure_gateway_state() {
    install -d -m 0700 "$config_root" "$config_root/certs" "$operations_websocket_path"
    chmod 0700 "$config_root" "$config_root/certs" "$operations_websocket_path"

    if [ ! -f "$env_path" ]; then
        install -m 0600 "${app_root}/.env.example" "$env_path"
    else
        chmod 0600 "$env_path"
    fi

    if [ ! -f "$database_path" ]; then
        install -m 0600 /dev/null "$database_path"
    else
        chmod 0600 "$database_path"
    fi

    find "$config_root/certs" -maxdepth 1 -type f -name '*.key' -exec chmod 0600 {} +

    if [ -f "$operations_websocket_path/apps.php" ]; then
        chmod 0600 "$operations_websocket_path/apps.php"
    fi

    set_env_value "APP_ENV" "${APP_ENV:-production}"
    set_env_value "APP_DEBUG" "${APP_DEBUG:-false}"
    set_env_value "DB_CONNECTION" "${DB_CONNECTION:-sqlite}"
    set_env_value "DB_DATABASE" "${DB_DATABASE:-$database_path}"
    set_env_value "DB_BUSY_TIMEOUT" "${DB_BUSY_TIMEOUT:-5000}"
    set_env_value "DB_JOURNAL_MODE" "${DB_JOURNAL_MODE:-wal}"
    set_env_value "DB_SYNCHRONOUS" "${DB_SYNCHRONOUS:-NORMAL}"

    if id orbit >/dev/null 2>&1; then
        chown -R orbit:orbit "$config_root"
    fi

    if [ -z "$(read_env_value APP_KEY)" ]; then
        (cd "$app_root" && php artisan key:generate --force --no-interaction >/dev/null)
    fi
}

run_artisan() {
    cd "$app_root"

    exec php artisan "$@"
}

run_gateway() {
    cd "$app_root"

    exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
}

if [ "$invoked_as" = "orbit" ]; then
    ensure_gateway_state
    run_artisan "$@"
fi

if [ "$#" -eq 0 ]; then
    set -- serve
fi

case "${1:-}" in
    serve)
        ensure_gateway_state
        run_gateway
        ;;
    scheduler)
        shift
        ensure_gateway_state
        run_artisan orbit-scheduler "$@"
        ;;
    artisan|orbit)
        shift
        ensure_gateway_state
        run_artisan "$@"
        ;;
    migrate)
        shift
        ensure_gateway_state
        run_artisan migrate --force "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
