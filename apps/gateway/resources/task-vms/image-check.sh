#!/usr/bin/env bash
# Checks a VM that booted from a new base image with the task VM user-data, before the image is promoted. Runs as
# root or as the managed user.
#
# Usage: image-check.sh <user> <uid:gid> <manifest> [--lean-boot] [--ufw]
# The user has the pinned uid and gid, the image manifest that enrollment reads is there, and cloud-init gave the
# user the Gateway's key from the user-data. Each flag adds the check of one provider:
#   --lean-boot  the lean boot held through the first boot, when systemd applies the preset policy (Incus)
#   --ufw        ufw is active (UpCloud)
set -eu
user=$1
owner=$2
manifest=$3
shift 3
test "$(id -u -- "$user"):$(id -g -- "$user")" = "$owner"
test -s "$manifest"
home=$(getent passwd -- "$user" | cut -d : -f 6)
test -s "$home/.ssh/authorized_keys"
for check in "$@"; do
    case $check in
        --lean-boot)
            test "$(systemctl is-enabled systemd-firstboot.service)" = masked
            test "$(systemctl is-active docker.service)" = active
            systemctl cat systemd-networkd-wait-online.service | tail -n 1 | grep -q -- '--ipv4'
            networkctl status --no-pager -- "$(ip -o -4 route show default | awk '{print $5; exit}')" | grep -q '00-orbit-task-vm.network'
            ;;
        --ufw)
            sudo -n ufw status | grep -qx 'Status: active'
            ;;
        *)
            echo "unknown check: $check" >&2
            exit 2
            ;;
    esac
done
