#!/bin/sh
set -eu
: "${PROOF_TARGET_USER:?proof target user is required}"
: "${PROOF_TARGET_HOME:?proof target home is required}"
[ "$PROOF_TARGET_USER" != root ]

if ! command -v caddy >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get -o DPkg::Lock::Timeout=300 update
    apt-get -o DPkg::Lock::Timeout=300 install --yes --no-install-recommends caddy
fi
install -d -m 0755 /etc/caddy
printf '{\n admin localhost:2019\n}\n' > /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
systemctl restart caddy

proof_group=$(id -gn "$PROOF_TARGET_USER")
for scope in route-791327 route-791328; do
    install -d -m 0700 -o "$PROOF_TARGET_USER" -g "$proof_group" \
        "$PROOF_TARGET_HOME/.orbit" "$PROOF_TARGET_HOME/.orbit/certificates" \
        "$PROOF_TARGET_HOME/.orbit/certificates/$scope"
    install -d -m 0755 "/etc/caddy/orbit-certificates/$scope"
    printf 'public-proof-marker\n' > "$PROOF_TARGET_HOME/.orbit/certificates/$scope/proof-marker"
    printf 'public-proof-marker\n' > "/etc/caddy/orbit-certificates/$scope/proof-marker"
done

# Same directory and SSH authorized_keys; the NSS spelling violates legacy validHome.
usermod --home "$PROOF_TARGET_HOME/." "$PROOF_TARGET_USER"
