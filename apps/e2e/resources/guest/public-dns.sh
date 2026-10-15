#!/usr/bin/env bash
# Only nested topology guests need these upstreams before cloud-init waits for network-online.
set -euo pipefail
install -d -m 0755 /etc/systemd/resolved.conf.d
candidate=$(mktemp)
trap 'rm -f "$candidate"' EXIT
printf '[Resolve]\nDNS=1.1.1.1 8.8.8.8\n' >"$candidate"
if ! cmp -s "$candidate" /etc/systemd/resolved.conf.d/orbit-public-upstream.conf; then
    install -m 0644 "$candidate" /etc/systemd/resolved.conf.d/orbit-public-upstream.conf
    if systemctl is-active --quiet systemd-resolved; then
        systemctl restart systemd-resolved
    fi
fi
