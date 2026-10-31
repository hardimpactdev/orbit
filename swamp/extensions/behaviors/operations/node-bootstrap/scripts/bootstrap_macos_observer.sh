#!/bin/sh
set -eu
[ "$(/usr/bin/uname -s)" = Darwin ]
[ -d "$HOME" ] && [ ! -L "$HOME" ]
for path in "$HOME/.orbit" "$HOME/.orbit/bin"; do
    [ ! -L "$path" ]
    if [ ! -e "$path" ]; then /bin/mkdir -m 700 "$path"; fi
    [ -d "$path" ]
done
target="$HOME/.orbit/bin/orbit-agent"
[ ! -L "$target" ]
if [ -f "$target" ] && [ "$(/usr/bin/shasum -a 256 "$target" | /usr/bin/cut -d ' ' -f 1)" = "$OBSERVER_SHA256" ]; then
    /usr/bin/printf '{"installed":true,"changed":false}\n'
    exit 0
fi
candidate=$(/usr/bin/mktemp "$HOME/.orbit/bin/.observer-XXXXXXXX")
trap '/bin/rm -f "$candidate"' EXIT HUP INT TERM
/usr/bin/curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --output "$candidate" "$OBSERVER_URL"
[ "$(/usr/bin/shasum -a 256 "$candidate" | /usr/bin/cut -d ' ' -f 1)" = "$OBSERVER_SHA256" ]
/bin/chmod 755 "$candidate"
/bin/mv -f "$candidate" "$target"
/usr/bin/printf '{"installed":true,"changed":true}\n'
