# orbit-club-key: the Club key of a nested fleet's Gateway, from stdin into the outer container's /run
# (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway). Root runs it in the container, through
# `incus exec ... -- sh -c <this> sh <directory> <file> <owner> <group> <tmpfiles> <gateway> <unit>`. It prints one word, never the
# value: `changed` or `unchanged`. Exit 3: the folder is not on a tmpfs, so a snapshot could hold the key. Exit 4: stdin was empty.
set -eu
directory=$1 file=$2 owner=$3 group=$4 tmpfiles=$5 gateway=$6 unit=$7
umask 077
mkdir -p -- "$directory"
[ "$(findmnt --noheadings --output FSTYPE --target "$directory")" = tmpfs ] || exit 3
chown -- "$owner:$group" "$directory"
chmod 0700 -- "$directory"
# The folder must be there before the nested Gateway starts at boot, as its disk device's source. This line holds no secret.
rule="d $directory 0700 $owner $group -"
[ "$(cat -- "$tmpfiles" 2>/dev/null || true)" = "$rule" ] || printf '%s\n' "$rule" > "$tmpfiles"
next="$directory/.$file.next"
trap 'rm -f -- "$next"' EXIT
cat > "$next"
[ -s "$next" ] || exit 4
chown -- "$owner:$group" "$next"
if cmp -s -- "$next" "$directory/$file"; then
  echo unchanged
  exit 0
fi
mv -f -- "$next" "$directory/$file"
# Serve reads the key when it starts, and may have given up while it was missing.
if [ "$(incus list --format csv --columns s -- "^$gateway\$" 2>/dev/null || true)" = RUNNING ]; then
  incus exec "$gateway" -- systemctl reset-failed -- "$unit" || true
  incus exec "$gateway" -- systemctl restart --no-block -- "$unit"
fi
echo changed
