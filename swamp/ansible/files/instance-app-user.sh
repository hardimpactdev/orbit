#!/bin/sh
# orbit-app-user: move an Instance container's app user from `orbit` (uid 1000) and `work` (gid 2760) to the stock
# `www-data` (uid/gid 33). Idempotent; a half-done run finishes. Runs as root before the first command as the app user.
# Never touches `agent` (2750) except adding it to group www-data.
set -eu
WS=${ORBIT_WORKSPACE:-/srv/workspace}
SYS=/etc/systemd/system
OLD='^(User=orbit|Group=(orbit|work))$|/home/orbit'

has_user() { getent passwd "$1" >/dev/null; }
has_group() { getent group "$1" >/dev/null; }
old_units() {
  grep -rlE "$OLD" "$SYS" 2>/dev/null | while read -r f; do
    case "$f" in
      */*.d/*) u=$(basename "$(dirname "$f")"); echo "${u%.d}" ;;
      *) basename "$f" ;;
    esac
  done | sort -u
}

# A nested-fleet container (the image's AppArmor marker, InstanceImage::nestedFiles) keeps `orbit` (1000) and `work` (2760) with
# their files and ACLs: its inner guests are Nodes whose `orbit` the ID map puts on those numbers over the shared checkout. Only
# `agent` joins www-data there.
if [ -e /etc/orbit/apparmor-off ]; then
  id -nG agent | tr ' ' '\n' | grep -qx www-data || usermod -a -G www-data agent
  echo "orbit-app-user: nested-fleet container keeps orbit"; exit 0
fi

units=$(old_units)
if ! has_user orbit && ! has_group work && ! getent group 1000 >/dev/null && [ -z "$units" ] \
   && id -nG agent | tr ' ' '\n' | grep -qx www-data; then
  echo "orbit-app-user: already www-data"; exit 0
fi

# 1. Stop the units that run as the old account (and their timers).
stopped=""
for u in $units; do
  t="${u%.*}.timer"
  systemctl is-active --quiet "$t" 2>/dev/null && { systemctl stop "$t"; stopped="$stopped $t"; }
  systemctl is-active --quiet "$u" 2>/dev/null && { systemctl stop "$u"; stopped="$stopped $u"; }
done

# 2. Nothing else may still run as uid 1000. A refusal starts the stopped units again, so the app keeps serving as before.
if pgrep -u 1000 >/dev/null 2>&1; then
  for u in $stopped; do systemctl start "$u"; done
  echo "orbit-app-user: processes still run as uid 1000: $(pgrep -u 1000 -d, )" >&2; exit 3
fi

# 3. Hand files over: uid 1000 -> 33, gid 1000/2760 -> 33, on the container's own file system.
find / -xdev \( -uid 1000 -o -gid 1000 -o -gid 2760 \) -print0 2>/dev/null \
  | xargs -0 -r chown -h --from=1000 33 2>/dev/null || true
find / -xdev \( -gid 1000 -o -gid 2760 \) -print0 2>/dev/null | xargs -0 -r chgrp -h 33
# The workspace ACLs name gid 2760: give them to www-data.
if [ -d "$WS" ] && getfacl -pn "$WS" 2>/dev/null | grep -q ':2760:'; then
  setfacl -R -m g:33:rwX -m d:g:33:rwX "$WS"
  setfacl -R -x g:2760 -x d:g:2760 "$WS" 2>/dev/null || true
fi

# 4. Home: www-data keeps its stock home /var/www; carry the old home's files over.
if [ -d /home/orbit ]; then
  mkdir -p /var/www
  cp -a /home/orbit/. /var/www/
  chown -h 33:33 /var/www
  rm -rf /home/orbit
fi

# 5. Accounts.
usermod -a -G www-data agent
has_user orbit && userdel orbit
has_group work && groupdel work
g=$(getent group 1000 | cut -d: -f1 || true); [ -n "$g" ] && groupdel "$g"

# 6. Units: rewrite, reload, start what was stopped and every enabled unit that is not running.
grep -rlE "$OLD" "$SYS" 2>/dev/null | while read -r f; do
  sed -i -E 's/^User=orbit$/User=www-data/; s/^Group=(orbit|work)$/Group=www-data/; s#/home/orbit#/var/www#g' "$f"
done
systemctl daemon-reload
for u in $stopped; do systemctl start "$u"; done
# A unit that failed under a name a half-done run had already removed is enabled but not running.
for u in $units; do
  if systemctl is-enabled --quiet "$u" 2>/dev/null && ! systemctl is-active --quiet "$u" 2>/dev/null; then
    systemctl start "$u"; stopped="$stopped $u"
  fi
done
echo "orbit-app-user: moved to www-data (restarted:${stopped:- none})"
