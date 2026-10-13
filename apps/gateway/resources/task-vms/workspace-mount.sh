#!/usr/bin/env bash
# Mounts the workspace volume, the Incus disk device $1, at the Project apps directory $2 as the managed user, at a
# claim and in a template builder. It refuses before it mounts when the user is not the template owner $3 (uid:gid),
# and after it mounts when the volume root or the template checkout $4 is not owned by it. Only then does it keep the
# mount in /etc/fstab and point the caches $6... at $5 on the volume. It prints "template" when the checkout is there.
set -eu
device=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_$1
directory=$2
owner=$3
template=$4
cache=$5
shift 5
case "$directory" in /*) ;; *) exit 1 ;; esac
case "$directory" in *[[:space:]]*) exit 1 ;; esac
if [ "$(id -u):$(id -g)" != "$owner" ]; then
    printf 'owner: the template belongs to [%s], but this user is [%s]\n' "$owner" "$(id -u):$(id -g)" >&2
    exit 1
fi
for _ in $(seq 1 100); do
    [ -b "$device" ] && break
    sleep 0.1
done
test -b "$device"
if ! mountpoint -q -- "$directory"; then
    if [ -e "$directory" ] || [ -L "$directory" ]; then
        test -d "$directory" && test ! -L "$directory"
        test -z "$(ls -A -- "$directory")"
    fi
    sudo -n install -d -o "$(id -u)" -g "$(id -g)" -m 0755 -- "$directory"
    sudo -n mount -o noatime -- "$device" "$directory"
fi
test "$(findmnt -n -o SOURCE --target "$directory")" = "$(readlink -f -- "$device")"
if [ "$(stat -c '%u:%g' -- "$directory")" != "$owner" ]; then
    printf 'owner: the volume root belongs to [%s], not [%s]\n' "$(stat -c '%u:%g' -- "$directory")" "$owner" >&2
    exit 1
fi
found=0
if [ -d "$directory/$template" ] && [ ! -L "$directory/$template" ]; then
    if [ "$(stat -c '%u:%g' -- "$directory/$template")" != "$owner" ]; then
        printf 'owner: the template checkout belongs to [%s], not [%s]\n' "$(stat -c '%u:%g' -- "$directory/$template")" "$owner" >&2
        exit 1
    fi
    found=1
fi
line="$device $directory ext4 noatime,nofail,x-systemd.device-timeout=10s,x-systemd.before=multi-user.target 0 2"
grep -qxF -- "$line" /etc/fstab || printf '%s\n' "$line" | sudo -n tee -a /etc/fstab >/dev/null
install -d -m 0755 -- "$HOME/.cache"
for name in "$@"; do
    install -d -m 0755 -- "$directory/$cache/$name"
    if [ -e "$HOME/.cache/$name" ] && [ ! -L "$HOME/.cache/$name" ]; then
        rm -rf -- "${HOME:?}/.cache/$name"
    fi
    ln -sfn -- "$directory/$cache/$name" "$HOME/.cache/$name"
done
if [ "$found" = 1 ]; then
    printf template
fi
