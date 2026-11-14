#!/bin/sh
# Chromium's trust store of `agent` in a running Instance container (docs/reference/development-containers.md#trust). It runs as
# `agent` itself (uid and gid 2750), never as root, so a path that `agent` planted gains it nothing. The converge files step runs
# it inside the container under `timeout 30`; a later wake path can run the same file.
#
#   instance-browser-trust.sh write|remove <home> <store> <library line> <name line> <entry> <folder>...
#
# umask 077 makes the folders 0700 and the file 0600. It prints `created`, `appended`, `removed` or `unchanged`. Exit 3: no home;
# exit 4: a path of another type, printed as `<path> <type>`; exit 5: a write failed. `write` makes a missing store with only the
# entry and appends the entry to a store that lacks it. `remove` takes the Orbit entry (its library line, its name line and its
# blank line) out of a store that has it. A store is opened only once it is a regular file and no link; `timeout` bounds one that
# turns into a FIFO after that check.
umask 077
kind() {
  if [ -L "$1" ]; then echo symlink; elif [ -d "$1" ]; then echo directory; elif [ -f "$1" ]; then echo file
  elif [ -e "$1" ]; then echo other; else echo missing; fi
}
mode=$1 home=$2 store=$3 line=$4 name=$5 entry=$6
shift 6
{ [ -d "$home" ] && [ ! -L "$home" ]; } || exit 3
if [ "$mode" = remove ]; then
  case $(kind "$store") in
    missing) echo unchanged; exit 0 ;;
    file) ;;
    *) echo "$store $(kind "$store")"; exit 4 ;;
  esac
  grep -qxF -- "$line" "$store" || { echo unchanged; exit 0; }
  kept=$(mktemp "$store.XXXXXX") || exit 5
  awk -v l="$line" -v n="$name" '
    drop == 1 && $0 == n { drop = 2; next }
    drop >= 1 && $0 == "" { drop = 0; next }
    { drop = 0 }
    $0 == l { drop = 1; next }
    { print }' "$store" > "$kept" || exit 5
  mv -f "$kept" "$store" || exit 5
  echo removed
  exit 0
fi
for dir; do
  case $(kind "$dir") in
    directory) ;;
    missing) mkdir "$dir" || exit 5 ;;
    *) echo "$dir $(kind "$dir")"; exit 4 ;;
  esac
done
case $(kind "$store") in
  missing) printf '%s' "$entry" > "$store" || exit 5; echo created ;;
  file)
    if grep -qxF -- "$line" "$store"; then echo unchanged; exit 0; fi
    # Appended after a blank line, as NSS separates its module entries; the store's own text stays as it is.
    last=$(tail -c 2 "$store" | od -An -c | tr -d ' ')
    case $last in
      '\n\n' | '') sep='' ;;
      *'\n') sep='\n' ;;
      *) sep='\n\n' ;;
    esac
    # shellcheck disable=SC2059 # the separator is the format: printf turns its \n into newlines
    printf "$sep%s" "$entry" >> "$store" || exit 5
    echo appended ;;
  *) echo "$store $(kind "$store")"; exit 4 ;;
esac
