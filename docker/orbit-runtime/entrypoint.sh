#!/usr/bin/env bash

set -Eeuo pipefail

source_path="${ORBIT_SOURCE_PATH:-/opt/orbit}"
orbit_artisan="${source_path}/artisan"
invoked_as="$(basename "${0}")"

truthy() {
    case "${1:-}" in
        1|true|TRUE|yes|YES)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

run_orbit() {
    if [ ! -f "$orbit_artisan" ]; then
        printf 'orbit-runtime: Orbit source is not mounted at %s\n' "$source_path" >&2
        exit 1
    fi

    exec php "$orbit_artisan" "$@"
}

run_scheduler() {
    scheduler_args=("orbit-scheduler")

    if [ -n "${ORBIT_SCHEDULER_SLEEP_SECONDS:-}" ]; then
        scheduler_args+=("--sleep-seconds=${ORBIT_SCHEDULER_SLEEP_SECONDS}")
    fi

    while [ ! -f "$orbit_artisan" ]; do
        printf 'orbit-runtime: waiting for Orbit source at %s\n' "$source_path" >&2
        sleep 1
    done

    run_orbit "${scheduler_args[@]}"
}

if [ "$invoked_as" = "orbit" ]; then
    run_orbit "$@"
fi

if [ "$#" -eq 0 ]; then
    if truthy "${ORBIT_IS_GATEWAY:-}"; then
        run_scheduler
    fi

    exec sleep infinity
fi

case "${1:-}" in
    orbit)
        shift
        run_orbit "$@"
        ;;
    sleep)
        if truthy "${ORBIT_IS_GATEWAY:-}" && [ "${2:-}" = "infinity" ]; then
            run_scheduler
        fi

        exec "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
