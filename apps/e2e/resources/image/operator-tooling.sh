#!/usr/bin/env bash
# The operator's web tooling, after `role-image.sh base`: PHP 8.5 and Composer for the mounted CLI and web
# proxy, WireGuard for its own peer, and Vite+ with Node LTS for `vp dev`. Runs as root.
set -Eeuo pipefail
trap 'status=$?; printf "operator-tooling: line %s exited %s: %s\n" "$LINENO" "$status" "$BASH_COMMAND" >&2' ERR
export DEBIAN_FRONTEND=noninteractive
for attempt in 1 2 3; do
    if apt-get update && apt-get install --yes --no-install-recommends git curl ca-certificates python3 iproute2 \
        wireguard-tools resolvconf composer unzip php8.5-cli php8.5-fpm php8.5-curl php8.5-mbstring php8.5-xml \
        php8.5-sqlite3 php8.5-zip php8.5-bcmath php8.5-intl; then
        break
    fi
    [[ $attempt -lt 3 ]] || { echo 'operator-tooling: apt-get failed' >&2; exit 1; }
    sleep $((attempt * 5))
done
systemctl enable --now php8.5-fpm
sudo -u orbit -H bash -o pipefail -c 'curl -fsSL https://vite.plus | bash'
vp=/home/orbit/.local/share/vite-plus/bin/vp
[[ -x "$vp" ]]
sudo -u orbit -H "$vp" env setup
sudo -u orbit -H "$vp" env on
sudo -u orbit -H "$vp" env install lts
sudo -u orbit -H "$vp" env default lts
ln -sfn "$vp" /usr/local/bin/vp
