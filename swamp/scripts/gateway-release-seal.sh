#!/bin/sh
# Runs as root inside a fresh Gateway release clone before it starts application workloads.
set -eu
home=${1:-/home/orbit}
for path in "$home" "$home/orbit" "$home/web"; do
    test -d "$path" && test ! -L "$path" || exit 1
done
# The service can still create its own cache directories and digest in HOME.
# Sticky ownership prevents it from renaming or replacing the root-owned release trees.
chown root:orbit "$home"
chmod 1770 "$home"
find -P "$home/orbit" "$home/web" -xdev \
    \( -path "$home/orbit/apps/gateway/bootstrap/cache" -o \
       -path "$home/orbit/apps/cli/bootstrap/cache" -o \
       -path "$home/orbit/apps/cli/storage" \) -type d -prune -o \
    -exec chown -h root:orbit {} +
find -P "$home/orbit" "$home/web" -xdev \
    \( -path "$home/orbit/apps/gateway/bootstrap/cache" -o \
       -path "$home/orbit/apps/cli/bootstrap/cache" -o \
       -path "$home/orbit/apps/cli/storage" \) -type d -prune -o \
    \( -type d -o -type f \) -exec chmod g+rX,go-w {} +
