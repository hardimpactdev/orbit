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

# Recovery also runs after all old account text is gone. Only exactly enabled Orbit unit files resume: a static Schedule
# service runs through its timer, never because is-enabled returned success for "static", "indirect" or "generated".
resume_enabled() {
  resumed=""
  for file in "$SYS"/orbit-*; do
    [ -f "$file" ] || continue
    u=${file##*/}
    enabled=$(systemctl is-enabled "$u" 2>/dev/null || true)
    if [ "$enabled" = enabled ] && ! systemctl is-active --quiet "$u" 2>/dev/null; then
      systemctl start "$u"
      resumed="$resumed $u"
    fi
  done
}

# A nested-fleet container (the image's AppArmor marker, InstanceImage::nestedFiles) keeps `orbit` (1000) and `work` (2760) with
# their files and ACLs: its inner guests are Nodes whose `orbit` the ID map puts on those numbers over the shared checkout. There
# `agent` joins www-data, and www-data joins `work`, the workspace's group, so the app opens the workspace.
if [ -e /etc/orbit/apparmor-off ]; then
  # InstanceImage renders the same guard into this file and the image's nftables configuration.
  guard='@nested-app-guard@'
  firewall=/etc/nftables.conf
  if ! grep -Fq "$guard" "$firewall"; then
    sed -i -E "s@^([[:space:]]*)ip daddr .* meta skuid 1000 drop[[:space:]]*\$@\1$guard@" "$firewall"
  fi
  # Older nested images used a global flush. Convert that reset before loading the updated guard; never flush the inner Incus
  # tables. Also repair a run that wrote the guard before it refused the old reset.
  if grep -Fq "$guard" "$firewall" && grep -Eq '^[[:space:]]*flush[[:space:]]+ruleset[[:space:]]*$' "$firewall"; then
    sed -i -E 's/^[[:space:]]*flush[[:space:]]+ruleset[[:space:]]*$/destroy table inet orbit_agent_guard/' "$firewall"
  fi
  # A nested reload must replace Orbit's table only; a global flush would erase the inner Incus NAT and ACLs.
  if ! grep -Fq "$guard" "$firewall" || grep -Eq '^[[:space:]]*flush[[:space:]]+ruleset' "$firewall"; then
    echo "orbit-app-user: nested firewall is not an Orbit guard" >&2; exit 1
  fi
  live=$(nft --numeric list table inet orbit_agent_guard 2>/dev/null || true)
  if ! printf '%s\n' "$live" | grep -Fq "$guard"; then
    nft -f "$firewall"
  fi
  id -nG agent | tr ' ' '\n' | grep -qx www-data || usermod -a -G www-data agent
  id -nG www-data | tr ' ' '\n' | grep -qx work || usermod -a -G work www-data
  echo "orbit-app-user: nested-fleet container keeps orbit"; exit 0
fi

units=$(old_units)
if ! has_user orbit && ! has_group work && ! getent group 1000 >/dev/null && [ -z "$units" ] \
   && id -nG agent | tr ' ' '\n' | grep -qx www-data; then
  resume_enabled
  echo "orbit-app-user: already www-data"; exit 0
fi

# 1. Stop the units that run as the old account (and their timers).
stopped=""
for u in $units; do
  t="${u%.*}.timer"
  systemctl is-active --quiet "$t" 2>/dev/null && { systemctl stop "$t"; stopped="$stopped $t"; }
  systemctl is-active --quiet "$u" 2>/dev/null && { systemctl stop "$u"; stopped="$stopped $u"; }
done

# 2. Nothing else may still run as uid 1000. A refusal resumes enabled Orbit units and timers, without firing static services.
if pgrep -u 1000 >/dev/null 2>&1; then
  resume_enabled
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

# 6. Units: rewrite, reload, and recover inactive Orbit units and timers whose unit-file state is exactly enabled.
grep -rlE "$OLD" "$SYS" 2>/dev/null | while read -r f; do
  sed -i -E 's/^User=orbit$/User=www-data/; s/^Group=(orbit|work)$/Group=www-data/; s#/home/orbit#/var/www#g' "$f"
done
systemctl daemon-reload
resume_enabled
echo "orbit-app-user: moved to www-data (restarted:${resumed:- none})"
