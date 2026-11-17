#!/bin/sh
# The app user's name in an Instance container (docs/reference/development-containers.md#the-app-users-name, ADR 0268 second
# amendment). An older image made `orbit` (uid 1000) in its group `orbit` (gid 1000), and the group `work` (gid 2760); now both are
# named `web`: uid 1000 with the primary group `web`, gid 2760, which `agent` is in. Every container holds this file at
# /usr/local/libexec/orbit-app-user (InstanceImage::shared), and root runs it before any command as `web`: the container phase after
# the container starts, a Process write, each `config:clear`, and the native clone's guest before its Node store step.
#
# It reads the state first and does only what is left, so a renamed container changes nothing and a run that stopped half way
# finishes. It never touches `agent`. It prints `unchanged` or `renamed`. Exit 3: a process outside the units that name the old
# account still runs as uid 1000, so `usermod` would refuse; it is named on stderr and nothing has changed. Any other failure exits
# with the failed tool's status.
# Unit and file names hold no space or wildcard, so the lists below are words: no globbing, and SC2086 does not apply.
# shellcheck disable=SC2086
set -euf
units=/etc/systemd/system
workspace=/srv/workspace
# A unit file or drop-in that names the old user, the old groups or a path below the old home. `grep -r` follows no link, so the
# boot links in `*.wants` are not read twice.
pattern='^(User=orbit|Group=(orbit|work))[[:space:]]*$|/home/orbit([/[:space:]"'\'']|$)'

old_user=$(getent passwd orbit | cut -d: -f3)
old_group=$(getent group work | cut -d: -f3)
web_gid=$(getent passwd web | cut -d: -f4)
gid_1000=$(getent group 1000 | cut -d: -f1)
files=$(grep -rlE -- "$pattern" "$units") || [ $? -eq 1 ]

if [ "$old_user" != 1000 ] && [ "$old_group" != 2760 ] && { [ -z "$web_gid" ] || [ "$web_gid" = 2760 ]; } \
  && [ -z "$gid_1000" ] && [ -z "$files" ]; then
  echo unchanged
  exit 0
fi

# Each unit that a file names, a drop-in by its unit, and a Schedule's timer beside its service: one that fires during the rename
# would start a run under the old name.
named=
if [ -n "$files" ]; then
  named=$(printf '%s\n' "$files" | sed -E 's#[.]d/[^/]+$##; s#.*/##' | sed -E 'p; s/[.]service$/.timer/' | sort -u)
fi

if [ "$old_user" = 1000 ]; then
  words=" $(printf '%s ' $named)"
  others=$(ps -u 1000 -o unit=,pid=,args= | while read -r unit pid args; do
    case $words in *" $unit "*) ;; *) printf '%s %s %s\n' "$pid" "$unit" "$args" ;; esac
  done)
  if [ -n "$others" ]; then
    printf 'orbit-app-user: uid 1000 runs processes outside the units that name orbit, so orbit cannot be renamed web. Stop them and run it again:\n%s\n' "$others" >&2
    exit 3
  fi
fi

# The boot started them under the old name; `usermod` refuses a user that still runs a process.
running=''
enabled=''
for unit in $named; do
  state=$(systemctl show --property=LoadState --property=ActiveState --property=UnitFileState -- "$unit")
  if printf '%s\n' "$state" | grep -qxE 'ActiveState=(active|activating|reloading)'; then running="$running $unit"; fi
  if printf '%s\n' "$state" | grep -qx 'UnitFileState=enabled'; then enabled="$enabled $unit"; fi
done
if [ -n "$running" ]; then systemctl stop -- $running; fi

if [ "$old_user" = 1000 ]; then usermod --login web --home /home/web --move-home orbit; fi
if [ "$old_group" = 2760 ]; then groupmod --new-name web work; fi
web_gid=$(getent passwd web | cut -d: -f4)
if [ -n "$web_gid" ] && [ "$web_gid" != 2760 ]; then usermod --gid web web; fi
# `chown -R` follows no link, and `--no-dereference` changes a link itself, never its target. The group goes after its files, so a
# run that stops between the two regroups again.
if [ -n "$gid_1000" ]; then
  chown --recursive --no-dereference --from=:1000 :2760 -- "$workspace" /home/web
  groupdel "$gid_1000"
fi

if [ -n "$files" ]; then
  sed --in-place --regexp-extended -e 's/^User=orbit[[:space:]]*$/User=web/' -e 's/^Group=(orbit|work)[[:space:]]*$/Group=web/' \
    -e 's#/home/orbit([/[:space:]"'\'']|$)#/home/web\1#g' -- $files
  systemctl daemon-reload
fi
# The units this run stopped, and each enabled one, which an earlier run may have left stopped or which failed under a name that
# was gone.
start=$(printf '%s\n' $running $enabled | sort -u)
if [ -n "$start" ]; then systemctl start -- $start; fi
echo renamed
