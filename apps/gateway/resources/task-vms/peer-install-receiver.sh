#!/usr/bin/env bash
# Installs the image receiver from stdin at $1 when it differs. Runs as root on a task VM host.
set -eu
next=$(mktemp)
trap 'rm -f -- "$next"' EXIT
cat >"$next"
if ! cmp -s -- "$next" "$1"; then
    install -D -m 0755 -o root -g root -- "$next" "$1"
fi
