#!/usr/bin/env bash
# Sends the file $1 to the image receiver of $5@$6 with the key $2, from the address $3, and pins the target's host
# key $4. Prints what the receiver prints. Runs as root on the source host of a base image sync.
set -eu
input=$1
key=$2
bind=$3
hostkey=$4
user=$5
address=$6
known=$(mktemp)
trap 'rm -f -- "$known"' EXIT
printf '%s %s\n' "$address" "$hostkey" >"$known"
ssh -F /dev/null -T -i "$key" -b "$bind" -l "$user" -p 22 \
    -o BatchMode=yes -o IdentitiesOnly=yes -o IdentityAgent=none \
    -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known" -o GlobalKnownHostsFile=/dev/null \
    -o HostKeyAlgorithms=ssh-ed25519 -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 \
    -o LogLevel=ERROR -- "$address" <"$input"
