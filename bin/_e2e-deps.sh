#!/usr/bin/env bash
# shellcheck shell=bash
#
# Single source of truth for the E2E base-image apt package list. Sourced by
# bin/install-orbit and bin/e2e-provision-node, and read by
# IncusBaseImagePreparer to install the same list during direct image bootstrap.
#
# When sourced, exports ORBIT_E2E_BASE_PACKAGES + ORBIT_E2E_PHP_PACKAGES as
# Bash arrays. When invoked directly, prints one of the lists, one per line.

ORBIT_E2E_PHP_VERSION="${ORBIT_E2E_PHP_VERSION:-8.5}"

ORBIT_E2E_BASE_PACKAGES=(
    ca-certificates
    composer
    curl
    bind9-dnsutils
    git
    gnupg
    openssh-client
    openssh-server
    supervisor
    tar
    unzip
    ufw
    wireguard
    wireguard-tools
)

ORBIT_E2E_PHP_PACKAGES=(
    "php${ORBIT_E2E_PHP_VERSION}-bcmath"
    "php${ORBIT_E2E_PHP_VERSION}-cli"
    "php${ORBIT_E2E_PHP_VERSION}-common"
    "php${ORBIT_E2E_PHP_VERSION}-curl"
    "php${ORBIT_E2E_PHP_VERSION}-intl"
    "php${ORBIT_E2E_PHP_VERSION}-mbstring"
    "php${ORBIT_E2E_PHP_VERSION}-sqlite3"
    "php${ORBIT_E2E_PHP_VERSION}-xml"
    "php${ORBIT_E2E_PHP_VERSION}-zip"
)

if [ "${BASH_SOURCE[0]:-$0}" = "$0" ]; then
    case "${1:-}" in
        --base)
            printf '%s\n' "${ORBIT_E2E_BASE_PACKAGES[@]}"
            ;;
        --php)
            printf '%s\n' "${ORBIT_E2E_PHP_PACKAGES[@]}"
            ;;
        --all|"")
            printf '%s\n' "${ORBIT_E2E_BASE_PACKAGES[@]}" "${ORBIT_E2E_PHP_PACKAGES[@]}"
            ;;
        *)
            printf 'usage: %s [--base|--php|--all]\n' "$0" >&2
            exit 2
            ;;
    esac
fi
