#!/usr/bin/env bash

set -Eeuo pipefail

source_path="${ORBIT_SOURCE_PATH:-/opt/orbit}"
invoked_as="$(basename "${0}")"

orbit_artisan() {
    printf '%s\n' "${source_path}/apps/gateway/artisan"
}

run_orbit() {
    local artisan

    artisan="$(orbit_artisan)"

    if [ ! -f "$artisan" ]; then
        printf 'orbit-runtime: Orbit source is not mounted at %s\n' "$source_path" >&2
        exit 1
    fi

    exec php "$artisan" "$@"
}

wait_for_source() {
    while [ ! -f "${source_path}/apps/gateway/artisan" ]; do
        printf 'orbit-runtime: waiting for Orbit source at %s\n' "$source_path" >&2
        sleep 1
    done
}

start_gateway_http_server() {
    local host="${ORBIT_API_HOST:-0.0.0.0}"
    local port="${ORBIT_API_PORT:-8080}"
    local workers="${PHP_CLI_SERVER_WORKERS:-4}"
    local artisan

    artisan="$(orbit_artisan)"

    printf 'orbit-runtime: starting gateway API server on %s:%s (workers=%s)\n' "$host" "$port" "$workers" >&2

    PHP_CLI_SERVER_WORKERS="$workers" php "$artisan" serve --host="$host" --port="$port" --no-reload &
}

run_scheduler() {
    scheduler_args=("orbit-scheduler")

    if [ -n "${ORBIT_SCHEDULER_SLEEP_SECONDS:-}" ]; then
        scheduler_args+=("--sleep-seconds=${ORBIT_SCHEDULER_SLEEP_SECONDS}")
    fi

    wait_for_source

    start_gateway_http_server

    run_orbit "${scheduler_args[@]}"
}

if [ "$invoked_as" = "orbit" ]; then
    run_orbit "$@"
fi

if [ "$#" -eq 0 ]; then
    run_scheduler
fi

case "${1:-}" in
    orbit)
        shift
        run_orbit "$@"
        ;;
    sleep)
        if [ "${2:-}" = "infinity" ]; then
            run_scheduler
        fi

        exec "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
