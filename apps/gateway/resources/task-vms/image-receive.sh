#!/usr/bin/env bash
# Receives one task VM base image on stdin and imports it into the Incus project $1. Runs as root.
#
# Orbit installs this file as /usr/local/libexec/orbit/task-vm-image-receive. It is the forced command of each
# peer host's transfer key in the managed user's authorized_keys:
#   from="<peer address>",restrict,command="sudo -n /usr/local/libexec/orbit/task-vm-image-receive <project>"
# So it never runs a command that the peer asks for. Its only input is the image, a unified Incus image tarball.
#
# Empty input is a probe: it prints `ready` and changes nothing. Otherwise it prints the SHA-256 of the image,
# which is its Incus fingerprint, once Incus has imported it. Errors go to stderr with a non-zero exit code.
set -euo pipefail

project=${1:?usage: task-vm-image-receive <project>}
staging=/var/lib/orbit-task-vm-image/receive
# Larger than any base image. A peer cannot fill the disk with one transfer.
limit=$((32 * 1024 * 1024 * 1024))

umask 077
install -d -m 0700 -o root -g root -- /var/lib/orbit-task-vm-image "$staging"
# A receive that was killed (power loss, SIGKILL) left its file. No receive runs for two hours.
find "$staging" -mindepth 1 -maxdepth 1 -name 'image.*' -mmin +120 -delete

file=$(mktemp -- "$staging/image.XXXXXXXX")
trap 'rm -f -- "$file"' EXIT
trap 'exit 1' HUP INT TERM PIPE

# The image is written once, and hashed while it arrives.
fingerprint=$(head -c "$((limit + 1))" | tee -- "$file" | sha256sum | cut -d ' ' -f 1)
size=$(stat -c %s -- "$file")
if [ "$size" -eq 0 ]; then
    echo ready
    exit 0
fi
if [ "$size" -gt "$limit" ]; then
    echo "task-vm-image-receive: the image is larger than $limit bytes" >&2
    exit 1
fi

incus image import --quiet --project "$project" "$file" >&2
printf '%s\n' "$fingerprint"
