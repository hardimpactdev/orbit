#!/usr/bin/env bash
# Exports the image $2 of the Incus project $3 into the empty directory $1, which only root reads, and prints the
# path of the one file it writes. Runs as root on the source host of a base image sync.
set -eu
install -d -m 0700 -o root -g root -- "$(dirname -- "$(dirname -- "$1")")" "$(dirname -- "$1")"
rm -rf -- "$1"
install -d -m 0700 -o root -g root -- "$1"
incus image export --quiet --project "$3" "$2" "$1/image" >&2
set -- "$1"/image*
if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
    echo "the export is not one image file: $*" >&2
    exit 1
fi
printf '%s\n' "$1"
