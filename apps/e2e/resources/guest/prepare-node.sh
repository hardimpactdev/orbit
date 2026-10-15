#!/usr/bin/env bash
set -euo pipefail
umask 077
mode=${1-}
case "$mode" in
  configure-runtime)
    [[ $# -eq 1 ]] || exit 64
    [[ "$(id -u orbit):$(id -g orbit)" == 1000:1000 ]] || { echo 'The recipe base must provide orbit uid/gid 1000.' >&2; exit 67; }
    bash "$0" align-identity
    # Recipe software publishes a launcher; enrollment owns this path as a symlink.
    # Adapt only that exact root-owned launcher, never an unrelated executable.
    if [[ -f /usr/local/bin/bun && ! -L /usr/local/bin/bun ]] \
      && [[ "$(stat -c '%u:%g:%a' /usr/local/bin/bun)" == 0:0:755 ]] \
      && cmp -s /usr/local/bin/bun <(printf '#!/bin/sh\nexec /opt/orbit/bun/bin/bun "$@"\n'); then
      bun_link=$(mktemp -d /usr/local/bin/.orbit-recipe-bun.XXXXXXXX)
      trap 'rm -rf -- "$bun_link"' EXIT
      ln -s /opt/orbit/bun/bin/bun "$bun_link/bun"
      mv -fT -- "$bun_link/bun" /usr/local/bin/bun
      rmdir "$bun_link"
      trap - EXIT
    fi
    systemctl mask apt-daily.timer apt-daily-upgrade.timer unattended-upgrades.service motd-news.timer >/dev/null
    for hosts in /root/.ssh/known_hosts /home/*/.ssh/known_hosts; do
      if [[ -f $hosts ]]; then : >"$hosts"; fi
    done
    # Only replace the harness-owned DHCP file.
    network=/etc/systemd/network/00-orbit-e2e.network
    if [[ -f "$network" ]]; then
      candidate=$(mktemp)
      trap 'rm -f "$candidate"' EXIT
      printf '%s\n' '[Match]' 'Type=ether' 'Name=en* eth*' '' '[Network]' 'DHCP=ipv4' 'LinkLocalAddressing=no' >"$candidate"
      if ! cmp -s "$candidate" "$network"; then
        install -m 0644 "$candidate" "$network"
        # Reload matching rules without disturbing the uplink or restarting Docker.
        networkctl reload
      fi
      rm -f "$candidate"
      trap - EXIT
    fi
    if command -v docker >/dev/null 2>&1 && getent group docker >/dev/null; then
      if [[ " $(id -nG orbit) " != *' docker '* ]]; then
        usermod --append --groups docker orbit
      fi
    fi
    ;;
  compact-storage)
    [[ $# -eq 2 ]] || exit 64
    case "$2" in
      keep-images) ;;
      prune-images)
        if command -v docker >/dev/null 2>&1; then
          docker image prune --all --force
        fi
        ;;
      *) exit 64 ;;
    esac
    apt-get clean
    sync -f /
    if ! systemd-detect-virt --quiet --container; then
      fstrim --quiet-unsupported /
    fi
    ;;
  gateway-authorize)
    [[ $# -eq 2 && "$2" =~ ^ssh-ed25519\ [A-Za-z0-9+/]+={0,2}$ ]] || exit 64
    printf '%s\n' "$2" | ssh-keygen -lf - -E sha256 >/dev/null
    install -d -m 0700 /home/orbit/.ssh
    candidate=$(mktemp /home/orbit/.ssh/authorized_keys.XXXXXX)
    trap 'rm -f "$candidate"' EXIT
    printf '%s\n' "$2" >"$candidate"
    chmod 0600 "$candidate"
    chown orbit:orbit "$candidate"
    mv -f "$candidate" /home/orbit/.ssh/authorized_keys
    chown orbit:orbit /home/orbit/.ssh
    ;;
  align-identity)
    [[ $# -eq 1 ]]
    # Caddy from the Caddy project's own package is built without cgo, so it reads /etc/resolv.conf
    # instead of asking NSS. The stock image leaves a static resolv.conf there that cannot answer
    # the private .orbit zone, which breaks the hibernation forward_auth to gateway.orbit. Point it
    # at the systemd-resolved stub, the way a default Ubuntu install does.
    if systemctl is-active --quiet systemd-resolved && [[ -e /run/systemd/resolve/stub-resolv.conf ]]; then
      # Runtime resolver settings do not survive a snapshot reboot. Keep the base image's
      # public upstreams persistent; Orbit's more specific private DNS routes still apply.
      resolver_directory=/etc/systemd/resolved.conf.d
      install -d -m 0755 "$resolver_directory"
      resolver_candidate=$(mktemp)
      printf '[Resolve]\nDNS=1.1.1.1 8.8.8.8\n' >"$resolver_candidate"
      if ! cmp -s "$resolver_candidate" "$resolver_directory/orbit-e2e-upstream.conf"; then
        install -m 0644 "$resolver_candidate" "$resolver_directory/orbit-e2e-upstream.conf"
        rm -f "$resolver_candidate"
        systemctl restart systemd-resolved
      else
        rm -f "$resolver_candidate"
      fi
      if [[ "$(readlink -f /etc/resolv.conf)" != /run/systemd/resolve/stub-resolv.conf ]]; then
        ln -sfn ../run/systemd/resolve/stub-resolv.conf /etc/resolv.conf
      fi
    fi
    # Feature topologies mount the host worktree (owned by host uid/gid 1000)
    # into /home/orbit/orbit, so the orbit account must own uid/gid 1000.
    # The stock cloud image gives 1000 to the unused ubuntu user.
    current_uid=$(id -u orbit)
    current_gid=$(id -g orbit)
    if [[ "$current_uid" != 1000 || "$current_gid" != 1000 ]]; then
      if id -u ubuntu >/dev/null 2>&1; then
        userdel --remove ubuntu || userdel ubuntu
      fi
      [[ -z "$(getent passwd 1000)" && -z "$(getent group 1000)" ]] || exit 67
      groupmod --gid 1000 orbit
      usermod --uid 1000 --gid 1000 orbit
    fi
    # uutils chown/chgrp -h (Ubuntu 26.04) silently skip symlinks; lchown does not.
    # Ownership left behind by the old ids has no account any more; adopt it for orbit.
    # Stay on the guest filesystem; the mounted checkout belongs to the host.
    find /home/orbit -xdev \( -nouser -o -nogroup \) -exec python3 -c '
import grp, os, pwd, sys
def orphan(lookup, ident):
    try:
        lookup(ident)
    except KeyError:
        return True
    return False
for path in sys.argv[1:]:
    st = os.lstat(path)
    os.lchown(path, 1000 if orphan(pwd.getpwuid, st.st_uid) else -1, 1000 if orphan(grp.getgrgid, st.st_gid) else -1)
' {} + 2>/dev/null || true
    [[ "$current_uid" == 1000 && "$current_gid" == 1000 ]] && exit 0
    find / /run -xdev \( -uid "$current_uid" -o -gid "$current_gid" \) -exec python3 -c '
import os, sys
old_uid, old_gid = int(sys.argv[1]), int(sys.argv[2])
for path in sys.argv[3:]:
    st = os.lstat(path)
    os.lchown(path, 1000 if st.st_uid == old_uid else -1, 1000 if st.st_gid == old_gid else -1)
' "$current_uid" "$current_gid" {} + 2>/dev/null || true
    if systemctl is-active --quiet php8.5-fpm; then systemctl restart php8.5-fpm; fi
    ;;
  *) exit 64 ;;
esac
