#!/usr/bin/env bash

orbit_repo_root() {
    local source
    local directory

    source="${BASH_SOURCE[0]}"

    while [ -L "$source" ]; do
        directory="$(cd "$(dirname "$source")" >/dev/null 2>&1 && pwd)"
        source="$(readlink "$source")"

        if [[ "$source" != /* ]]; then
            source="${directory}/${source}"
        fi
    done

    cd "$(dirname "$source")/.." >/dev/null 2>&1 && pwd
}

orbit_gateway_app_path() {
    local root="$1"

    if [ -f "${root}/apps/gateway/artisan" ]; then
        printf '%s\n' "${root}/apps/gateway"

        return
    fi

    printf '%s\n' "$root"
}

orbit_gateway_artisan_path() {
    local root="$1"

    if [ -f "${root}/apps/gateway/artisan" ]; then
        printf '%s\n' "${root}/apps/gateway/artisan"

        return
    fi

    printf '%s\n' "${root}/artisan"
}
