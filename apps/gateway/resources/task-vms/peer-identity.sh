#!/usr/bin/env bash
# Creates the host's image transfer key pair in the directory $1 once, with the comment $2, and prints its public
# key. Runs as root on a task VM host.
set -eu
install -d -m 0700 -o root -g root -- "$1"
if [ ! -s "$1/id_ed25519" ] || [ ! -s "$1/id_ed25519.pub" ]; then
    rm -f -- "$1/id_ed25519" "$1/id_ed25519.pub"
    ssh-keygen -q -t ed25519 -N '' -C "$2" -f "$1/id_ed25519"
fi
cat -- "$1/id_ed25519.pub"
