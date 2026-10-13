#!/usr/bin/env bash
# Formats the new workspace template volume, the Incus disk device $1, and gives its root to $2:$3. The mount script
# then mounts it, as at a claim. Runs as root in a workspace template builder.
set -eu
device=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_$1
for _ in $(seq 1 100); do
    [ -b "$device" ] && break
    sleep 0.1
done
test -b "$device"
mkfs.ext4 -q -F -m 0 -L orbit-workspace -E lazy_itable_init=1,lazy_journal_init=1 "$device"
staging=$(mktemp -d)
mount -- "$device" "$staging"
rmdir -- "$staging/lost+found"
chown -- "$2:$3" "$staging"
umount -- "$staging"
rmdir -- "$staging"
