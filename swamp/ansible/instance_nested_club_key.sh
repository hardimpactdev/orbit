# orbit-club-key: the Club key of a nested fleet's Gateway, from stdin into the outer container's /run
# (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway). Root runs it in the container, through
# `incus exec ... -- sh -c <this> sh <directory> <file> <owner> <group> <tmpfiles> <gateway> <unit>`. It prints one word, never the
# value: `changed`, `unchanged`, or `changed_serve_pending` when it wrote a new key but the inner Incus was not ready, so serve was not
# restarted. Exit 3: the folder is not on a tmpfs, so a snapshot could hold the key. Exit 4: stdin was empty.
set -eu
directory=$1 file=$2 owner=$3 group=$4 tmpfiles=$5 gateway=$6 unit=$7
umask 077
mkdir -p -- "$directory"
[ "$(findmnt --noheadings --output FSTYPE --target "$directory")" = tmpfs ] || exit 3
# Root owns the folder, so its group (the nested Gateway's `orbit`) can read the key but plant no link to the disk in it.
chown -- "0:$group" "$directory"
chmod 0750 -- "$directory"
# The folder must be there before the nested Gateway starts at boot, as its disk device's source. This line holds no secret.
rule="d $directory 0750 0 $group -"
[ "$(cat -- "$tmpfiles" 2>/dev/null || true)" = "$rule" ] || printf '%s\n' "$rule" > "$tmpfiles"
next="$directory/.$file.next"
trap 'rm -f -- "$next"' EXIT
rm -f -- "$next"
# noclobber: the shell creates the file itself and refuses any file or link already there.
set -C
cat > "$next"
set +C
[ -s "$next" ] || exit 4
chown -h -- "$owner:$group" "$next"
if cmp -s -- "$next" "$directory/$file"; then
  echo unchanged
  exit 0
fi
mv -f -- "$next" "$directory/$file"
# Serve reads the key when it starts, and may have given up while it was missing. Right after the container boots, the inner Incus can
# wait behind network-online.target and its client then blocks. So the restart touches the inner Incus only when its service is
# active and it answers within a few seconds; otherwise serve starts later by itself (Restart=on-failure) and reads the key then.
if ! timeout -k 2 5 systemctl is-active --quiet incus.service || ! status=$(timeout -k 2 5 incus list --format csv --columns s -- "^$gateway\$" 2>/dev/null); then
  echo changed_serve_pending
  exit 0
fi
if [ "$status" = RUNNING ]; then
  timeout -k 2 10 incus exec "$gateway" -- systemctl reset-failed -- "$unit" || true
  if ! timeout -k 2 10 incus exec "$gateway" -- systemctl restart --no-block -- "$unit"; then
    echo changed_serve_pending
    exit 0
  fi
fi
echo changed
