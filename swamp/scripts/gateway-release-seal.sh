#!/bin/sh
# The clone's boot mounts protect its checkout and web build. Fix only their parent and the env link's parent.
set -eu
home=/home/orbit
for path in "$home" "$home/shared"; do
    test -d "$path" && test ! -L "$path" || exit 1
done
chown root:orbit "$home" "$home/shared"
chmod 1770 "$home"
chmod 0750 "$home/shared"
