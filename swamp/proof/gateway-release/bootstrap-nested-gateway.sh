#!/bin/bash
# Prepares a copy's nested Gateway for an automatic release proof. Run as root inside nested-gateway.
# Phase "edge": a local CA, a certificate for api.github.com, the hosts entry and NO_PROXY drop-ins, so the Gateway's
# GitHub App calls reach github_stub.py on 127.0.0.1:443 while public github.com reads still use the egress proxy.
# Phase "layout": the release layout of gateway-recovery "Release layout", hand-deployed for the first release <sha>,
# the same steps as swamp/scripts/gateway-release-build.sh without a current release. Arguments: phase [sha web.zip].
set -euo pipefail
phase=$1
proof=/opt/orbit-proof
base=/home/orbit
units=(orbit-gateway-horizon orbit-gateway-schedule orbit-swamp-serve orbit-swamp-worker@ php8.5-fpm orbit-agent orbit-websocket)
as_orbit() { runuser -u orbit -- env HOME=/home/orbit HTTPS_PROXY="$HTTPS_PROXY" HTTP_PROXY="$HTTP_PROXY" NO_PROXY="$NO_PROXY" "$@"; }

case $phase in
edge)
  install -d -m 0755 "$proof"
  if [ ! -f "$proof/ca.pem" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 7 -subj "/CN=orbit proof stub CA" -keyout "$proof/ca.key" -out "$proof/ca.pem" 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -subj "/CN=api.github.com" -keyout "$proof/api.key" -out "$proof/api.csr" 2>/dev/null
    printf 'subjectAltName=DNS:api.github.com\n' > "$proof/san.ext"
    openssl x509 -req -in "$proof/api.csr" -CA "$proof/ca.pem" -CAkey "$proof/ca.key" -CAcreateserial -days 7 \
      -extfile "$proof/san.ext" -out "$proof/api.pem" 2>/dev/null
    chmod 0600 "$proof/ca.key" "$proof/api.key"
  fi
  cp "$proof/ca.pem" /usr/local/share/ca-certificates/orbit-proof-stub.crt && update-ca-certificates >/dev/null
  grep -q ' api.github.com$' /etc/hosts || echo '127.0.0.1 api.github.com' >> /etc/hosts
  no_proxy='localhost,127.0.0.1,::1,.orbit,10.45.0.1,10.45.0.0/24,10.232.2.0/24,api.github.com'
  for unit in "${units[@]}"; do
    install -d "/etc/systemd/system/$unit.service.d"
    printf '[Service]\nEnvironment="NO_PROXY=%s" "no_proxy=%s"\n' "$no_proxy" "$no_proxy" > "/etc/systemd/system/$unit.service.d/60-proof-github-stub.conf"
  done
  sed -i -E "s#^(NO_PROXY|no_proxy)=.*#\1=\"$no_proxy\"#" /etc/environment
  systemctl daemon-reload
  ;;
layout)
  sha=$2 zip=$3 id=${2:0:12}
  release=$base/releases/$id app=$base/releases/$id/apps/gateway repo=$base/shared/orbit.git
  [ -d "$base/orbit/.git" ] || [ -f "$base/orbit/.git" ] || { echo "the workspace checkout is not mounted" >&2; exit 1; }
  install -d -m 0750 -o orbit -g orbit "$base/shared" "$base/shared/gateway-storage"
  [ -d "$repo" ] || as_orbit git -c safe.directory='*' clone --quiet --mirror "$base/orbit" "$repo"
  as_orbit git --git-dir "$repo" config remote.origin.url https://github.com/hardimpactdev/orbit.git
  as_orbit git --git-dir "$repo" config --unset remote.origin.mirror || true
  as_orbit git --git-dir "$repo" cat-file -e "$sha^{commit}"
  install -m 0600 -o orbit -g orbit "$base/orbit/apps/gateway/.env" "$base/shared/gateway.env"
  cp -a "$base/orbit/apps/gateway/storage/." "$base/shared/gateway-storage/"
  chown -R orbit:orbit "$base/shared/gateway-storage"
  ;;
release)
  sha=$2 zip=$3 id=${2:0:12}
  release=$base/releases/$id app=$base/releases/$id/apps/gateway repo=$base/shared/orbit.git
  ! mountpoint -q "$base/orbit" || { echo "unmount the workspace checkout first" >&2; exit 1; }
  install -d -m 0710 -o orbit -g caddy "$base/releases"
  [ -e "$release/.git" ] || as_orbit git -C "$repo" worktree add --quiet --detach --force "$release" "$sha"
  chmod 0700 "$app/bootstrap/cache"
  for dir in app/private app/public framework/cache/data framework/sessions framework/views logs; do
    install -d -m 0750 -o orbit -g orbit "$base/shared/gateway-storage/$dir"
  done
  as_orbit ln -sfn "$base/shared/gateway.env" "$app/.env"
  as_orbit bash -c "git -C '$release' ls-files -z -- apps/gateway/storage | xargs -0 -r git -C '$release' update-index --skip-worktree --"
  [ -L "$app/storage" ] || rm -rf "$app/storage"
  grep -qxF /apps/gateway/storage "$repo/info/exclude" 2>/dev/null || { install -d -m 0755 -o orbit "$repo/info"; echo /apps/gateway/storage >> "$repo/info/exclude"; }
  as_orbit ln -sfn "$base/shared/gateway-storage" "$app/storage"
  for project in apps/cli apps/gateway; do
    as_orbit composer --working-dir="$release/$project" install --no-dev --prefer-dist --no-interaction --no-progress --no-ansi > /dev/null
  done
  install -d -m 0755 -o orbit -g orbit "$release/apps/agent/delivery"
  as_orbit git -C "$repo" show "$sha:apps/gateway/resources/agent/frozen-manifest.json" > "$release/apps/agent/delivery/manifest.json"
  chown orbit:orbit "$release/apps/agent/delivery/manifest.json" && chmod 0444 "$release/apps/agent/delivery/manifest.json"
  chgrp caddy "$release" "$release/apps" "$app" && chmod 0710 "$release" "$release/apps" "$app"
  find "$release" -path "$app/bootstrap/cache" -prune -o -path "$release/apps/cli/storage" -prune -o \
    \( -type f -o -type d \) ! -type l -exec chmod a-w {} +
  chmod u+w "$release"
  printf '%s\n' "$sha" > "$release/REVISION" && chown orbit:orbit "$release/REVISION" && chmod 0644 "$release/REVISION"
  as_orbit env ORBIT_AGENT_DELIVERY="$release/apps/agent/delivery/manifest.json" php8.5 "$app/artisan" config:cache --no-interaction --quiet
  chmod u-w "$release"
  # The web build of the first release, from the same ZIP the stub serves.
  install -d -m 0750 -o orbit -g caddy "$base/web" "$base/web/releases"
  rm -rf "$base/web/releases/$id" && install -d -m 0750 -o orbit -g caddy "$base/web/releases/$id"
  python3 -I -c 'import sys, zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])' "$zip" "$base/web/releases/$id"
  chown -R orbit:caddy "$base/web/releases/$id"
  as_orbit ln -sfn "releases/$id" "$base/web/current"
  rm -rf "$base/orbit" && as_orbit ln -s "releases/$id" "$base/orbit"
  ;;
swamp)
  # A sealed release is read-only, so Swamp serve gets its own writable checkout, as SwampRoleUnits::Checkout gives a real
  # Gateway, with the nested fleet's Swamp state, grants and vaults bound into it.
  sha=$2 checkout=$base/apps/orbit-monorepo-staging/checkout
  if [ ! -e "$checkout/.git" ]; then
    install -d -o orbit -g orbit "$base/apps" "$base/apps/orbit-monorepo-staging"
    as_orbit git -C "$base/shared/orbit.git" worktree add --quiet --detach "$checkout" "$sha"
  fi
  for dir in .swamp grants vaults; do
    rm -rf "${checkout:?}/swamp/$dir" && install -d -o orbit -g orbit "$checkout/swamp/$dir"
  done
  exec=$(systemctl show -p ExecStart --value orbit-swamp-serve | sed -E 's/.*argv\[\]=([^;]*);.*/\1/' | sed "s#--repo-dir $base/orbit/swamp#--repo-dir $checkout/swamp#")
  install -d /etc/systemd/system/orbit-swamp-serve.service.d
  printf '[Unit]\nRequiresMountsFor=\n[Service]\nBindPaths=/var/lib/orbit-checkout-state/swamp/.swamp:%s/swamp/.swamp\nBindPaths=/var/lib/orbit-checkout-state/swamp/grants:%s/swamp/grants\nBindPaths=/var/lib/orbit-checkout-state/swamp/vaults:%s/swamp/vaults\nWorkingDirectory=%s/swamp\nExecStart=\nExecStart=%s\n' \
    "$checkout" "$checkout" "$checkout" "$checkout" "$exec" > /etc/systemd/system/orbit-swamp-serve.service.d/70-proof-swamp-repo.conf
  systemctl daemon-reload
  ;;
esac
