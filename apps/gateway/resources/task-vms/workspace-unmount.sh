#!/usr/bin/env bash
# Undoes a claim mount of the Incus disk device $1 at $2 that the claim could not finish: removes the cache links
# $4... into $3 on the volume, unmounts it, and drops its fstab line. Runs as the managed user on a task VM.
set -eu
device=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_$1
directory=$2
cache=$3
shift 3
case "$directory" in /*) ;; *) exit 1 ;; esac
for name in "$@"; do
    link=$HOME/.cache/$name
    if [ -L "$link" ] && [ "$(readlink -- "$link")" = "$directory/$cache/$name" ]; then
        rm -f -- "$link"
    fi
done
if mountpoint -q -- "$directory"; then
    sudo -n umount -- "$directory"
fi
if mountpoint -q -- "$directory"; then exit 1; fi
if awk -v d="$device" -v m="$directory" '$1 == d && $2 == m { found = 1 } END { exit found ? 0 : 1 }' /etc/fstab; then
    table=$(mktemp)
    awk -v d="$device" -v m="$directory" '!($1 == d && $2 == m)' /etc/fstab > "$table"
    sudo -n install -o root -g root -m 0644 -- "$table" /etc/fstab
    rm -f -- "$table"
fi
