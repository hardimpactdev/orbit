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
    local tmp

    if grep -qE "^${key}=" "$env_path"; then
        tmp="$(mktemp)"
        # Pass values through ENVIRON so awk does not interpret backslash escapes
        # in -v assignments (e.g. \n, \t, \\) when rewriting existing keys.
        ORBIT_ENV_KEY="$key" ORBIT_ENV_VALUE="$value" awk -F= '
            BEGIN {
                key = ENVIRON["ORBIT_ENV_KEY"]
                value = ENVIRON["ORBIT_ENV_VALUE"]
            }
            $1 == key {
                print key "=" value
                next
            }
            { print }
        ' "$env_path" > "$tmp"
        mv "$tmp" "$env_path"

        return
    fi

    printf '%s=%s\n' "$key" "$value" >> "$env_path"
}

#
# Resolve numeric ownership for the bind-mounted gateway config root.
#
# Production Swarm mounts host /home read-only at ${ORBIT_HOST_PATH_PREFIX}/home
# and sets ORBIT_HOST_PATH_PREFIX. Config roots live at <home>/.config/orbit, so
# the host install user's uid:gid is taken from the host view of that home path.
# Never use the image's orbit username/uid for host bind mounts: image uid 999
# maps to an unrelated host account and breaks host CLI access.
#
# Fail closed when a host path prefix is configured but identity cannot be
# resolved. Without a host path prefix (image-local / dev), fall back to the
# image orbit user when present.
#
resolve_config_root_owner() {
    local host_prefix="${ORBIT_HOST_PATH_PREFIX:-}"
    local home_dir
    local host_identity_path
    local owner

    host_prefix="${host_prefix%/}"

    if [ -n "$host_prefix" ]; then
        if [[ "$host_prefix" != /* ]] || [[ "$host_prefix" == *'..'* ]]; then
            echo "Gateway host path prefix is invalid." >&2

            return 1
        fi

        case "$config_root" in
            */.config/orbit)
                home_dir="${config_root%/.config/orbit}"
                ;;
            *)
                home_dir="$(dirname -- "$config_root")"
                ;;
        esac

        if [[ "$home_dir" != /* ]]; then
            echo "Unable to resolve host ownership for gateway config root." >&2

            return 1
        fi

        host_identity_path="${host_prefix}${home_dir}"

        if [ ! -d "$host_identity_path" ]; then
            echo "Unable to resolve host ownership for gateway config root at ${host_identity_path}." >&2

            return 1
        fi

        owner="$(stat -c '%u:%g' "$host_identity_path" 2>/dev/null || true)"

        # BSD stat fallback for local macOS fixture runs; Linux containers use GNU stat.
        if [[ ! "$owner" =~ ^[0-9]+:[0-9]+$ ]]; then
            owner="$(stat -f '%u:%g' "$host_identity_path" 2>/dev/null || true)"
        fi

        if [[ ! "$owner" =~ ^[0-9]+:[0-9]+$ ]]; then
            echo "Unable to resolve host ownership for gateway config root at ${host_identity_path}." >&2

            return 1
        fi

        printf '%s\n' "$owner"

        return 0
    fi

    if id -u orbit >/dev/null 2>&1; then
        printf '%s:%s\n' "$(id -u orbit)" "$(id -g orbit)"

        return 0
    fi

    return 0
}

apply_config_root_ownership() {
    local owner

    owner="$(resolve_config_root_owner)" || return 1

    if [ -z "$owner" ]; then
        return 0
    fi

    chown -R "$owner" "$config_root"
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

    apply_config_root_ownership

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
