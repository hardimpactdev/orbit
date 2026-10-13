#!/usr/bin/env bash
# Rewrites the authorized_keys of the user $1: drops each line whose last field matches the pattern $2, keeps every
# other line, and appends the lines on stdin. An unchanged file is not written. Runs as root on a task VM host.
set -eu
user=$1
drop=$2
home=$(getent passwd -- "$user" | cut -d : -f 6)
group=$(id -gn -- "$user")
if [ -z "$home" ] || [ ! -d "$home" ]; then
    echo "the user $user has no home directory" >&2
    exit 1
fi
[ -d "$home/.ssh" ] || install -d -m 0700 -o "$user" -g "$group" -- "$home/.ssh"
file=$home/.ssh/authorized_keys
if [ -L "$file" ] || { [ -e "$file" ] && [ ! -f "$file" ]; }; then
    echo "$file is not a regular file" >&2
    exit 1
fi
next=$(mktemp -- "$home/.ssh/.authorized_keys.XXXXXX")
trap 'rm -f -- "$next"' EXIT
{ if [ -f "$file" ]; then awk -v drop="$drop" '$NF !~ drop' "$file"; fi; cat; } >"$next"
if [ -f "$file" ] && cmp -s -- "$next" "$file"; then
    exit 0
fi
chown -- "$user:$group" "$next"
chmod 0600 -- "$next"
mv -fT -- "$next" "$file"
