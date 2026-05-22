#!/usr/bin/env bash

set -Eeuo pipefail

source_path="${ORBIT_SOURCE_PATH:-/opt/orbit}"
orbit_artisan="${source_path}/artisan"
invoked_as="$(basename "${0}")"

run_orbit() {
    if [ ! -f "$orbit_artisan" ]; then
        printf 'orbit-runtime: Orbit source is not mounted at %s\n' "$source_path" >&2
        exit 1
    fi

    exec php "$orbit_artisan" "$@"
}

if [ "$invoked_as" = "orbit" ]; then
    run_orbit "$@"
fi

case "${1:-}" in
    orbit)
        shift
        run_orbit "$@"
        ;;
    "")
        exec sleep infinity
        ;;
    *)
        exec "$@"
        ;;
esac
