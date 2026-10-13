#!/usr/bin/env bash
# Builds and cleans one E2E role image builder. Runs as root inside the builder, from stdin (`bash -s -- MODE`).
#   base                   the account, SSH, and resolver every role image needs
#   gateway-prerequisites  what the Gateway VM needs to run the mounted Gateway and `orbit:bootstrap`
#   seal                   after the Gateway's base-image-clean.sh: turns cloud-init off and checks no topology
#                          identity remains; prints {"ok":true}
set -Eeuo pipefail
# A command that fails under set -e names its line, so no build step fails silently.
trap 'status=$?; printf "role-image: line %s exited %s: %s\n" "$LINENO" "$status" "$BASH_COMMAND" >&2' ERR
export DEBIAN_FRONTEND=noninteractive

fail() {
    printf 'role-image: %s\n' "$1" >&2
    exit 1
}

apt_install() {
    local attempt
    for attempt in 1 2 3; do
        if apt-get update && apt-get install --yes --no-install-recommends -- "$@"; then
            return 0
        fi
        sleep $((attempt * 5))
    done
    fail "apt-get could not install: $*"
}

wait_for_boot() {
    local _
    for _ in $(seq 1 180); do
        systemctl show --property=SystemState >/dev/null 2>&1 && break
        sleep 1
    done
    systemctl is-system-running --wait >/dev/null 2>&1 || true
    if command -v cloud-init >/dev/null 2>&1; then
        cloud-init status --wait >/dev/null 2>&1 || true
    fi
}

wait_for_dns() {
    local _
    for _ in $(seq 1 120); do
        getent hosts archive.ubuntu.com >/dev/null && return 0
        sleep 1
    done
    fail 'the builder has no DNS after 120 seconds'
}

base() {
    wait_for_boot
    # Topology networks run dnsmasq without DNS (port=0), so every guest keeps public upstreams.
    install -d -m 0755 /etc/systemd/resolved.conf.d
    printf '[Resolve]\nDNS=1.1.1.1 8.8.8.8\n' >/etc/systemd/resolved.conf.d/orbit-e2e-upstream.conf
    systemctl restart systemd-resolved
    if [[ "$(readlink -f /etc/resolv.conf)" != /run/systemd/resolve/stub-resolv.conf ]]; then
        ln -sfn ../run/systemd/resolve/stub-resolv.conf /etc/resolv.conf
    fi
    wait_for_dns
    # The apt timers would hold the dpkg lock while enrollment runs.
    systemctl mask apt-daily.timer apt-daily-upgrade.timer unattended-upgrades.service motd-news.timer >/dev/null 2>&1 || true
    # Ubuntu Server ships ufw, and Orbit manages every Node firewall with it; the stock cloud images do not.
    apt_install sudo openssh-server ca-certificates curl iputils-ping ufw php8.5-cli
    # The mounted worktree is owned by uid and gid 1000 on the host, so orbit owns them in the guest. The
    # stock image gives uid 1000 to the default user `ubuntu`, and the cloud image gid 1000 to `lxd`.
    if id ubuntu >/dev/null 2>&1; then
        userdel --remove ubuntu 2>/dev/null || userdel ubuntu
    fi
    if getent group ubuntu >/dev/null 2>&1; then
        groupdel ubuntu
    fi
    if ! id orbit >/dev/null 2>&1; then
        local holder group gid
        holder=$(getent passwd 1000 | cut -d: -f1 || true)
        [[ -z "$holder" ]] || fail "uid 1000 belongs to [$holder]"
        group=$(getent group 1000 | cut -d: -f1 || true)
        if [[ -n "$group" ]]; then
            for gid in $(seq 999 -1 900); do
                getent group "$gid" >/dev/null || break
            done
            groupmod --gid "$gid" "$group"
        fi
        groupadd --gid 1000 orbit
        useradd --create-home --uid 1000 --gid 1000 --shell /bin/bash orbit
    fi
    [[ "$(id -u orbit):$(id -g orbit)" == 1000:1000 ]] || fail 'orbit must have uid and gid 1000'
    printf 'orbit ALL=(ALL) NOPASSWD: ALL\n' >/etc/sudoers.d/orbit
    chmod 0440 /etc/sudoers.d/orbit
    install -d -o orbit -g orbit -m 0755 /home/orbit/orbit
    install -d -m 0755 /var/lib/orbit-e2e
    # Every guest from the image creates its own SSH host keys before sshd starts. The unit runs in early
    # boot without default dependencies: those order it after basic.target, which follows sockets.target,
    # so Before=ssh.socket would form a cycle that systemd breaks by dropping a random job, often ssh.socket.
    cat >/etc/systemd/system/orbit-e2e-ssh-host-keys.service <<'UNIT'
[Unit]
Description=Create missing SSH host keys
DefaultDependencies=no
After=local-fs.target
Before=ssh.socket ssh.service sockets.target
ConditionPathExistsGlob=!/etc/ssh/ssh_host_*_key

[Service]
Type=oneshot
ExecStart=/usr/bin/ssh-keygen -A

[Install]
WantedBy=sockets.target
UNIT
    systemctl daemon-reload
    systemctl enable orbit-e2e-ssh-host-keys.service ssh.service >/dev/null
    systemctl start ssh.service
}

gateway_prerequisites() {
    apt_install dnsmasq wireguard openssl git unzip composer \
        php8.5-fpm php8.5-curl php8.5-intl php8.5-mbstring php8.5-xml php8.5-sqlite3 php8.5-zip \
        php8.5-bcmath php8.5-mysql php8.5-pgsql
}

# Bash reads all of seal() before it runs it, so seal can close stdin and no command reads the rest.
seal() {
    exec 3>&1 1>&2 </dev/null
    if [[ "$(cat /etc/machine-id)" != uninitialized ]]; then
        fail 'run the Gateway base-image-clean.sh first'
    fi
    # The Gateway's clean keeps known hosts; a composed guest must not trust the builder network's hosts.
    local hosts
    for hosts in /root/.ssh/known_hosts /home/*/.ssh/known_hosts; do
        if [ -e "$hosts" ]; then : >"$hosts"; fi
    done
    if command -v cloud-init >/dev/null 2>&1; then
        # A guest from the image boots once, without cloud-init: Incus would reboot a new VM after the agent
        # writes the cloud-init seed, in the middle of composition. Networking comes from this file instead.
        touch /etc/cloud/cloud-init.disabled
        printf '%s\n' '[Match]' 'Type=ether' '' '[Network]' 'DHCP=ipv4' 'LinkLocalAddressing=no' \
            >/etc/systemd/network/00-orbit-e2e.network
    fi
    sync -f /
    if ! systemd-detect-virt --quiet --container; then
        fstrim --quiet-unsupported / || true
    fi

    # No topology identity may remain either.
    [ ! -e /etc/wireguard/orbit.conf ] || fail 'a WireGuard configuration remains'
    [ ! -e /home/orbit/.orbit/gateway.sqlite ] || fail 'a Gateway database remains'

    printf '{"ok":true}\n' >&3
}

case ${1-} in
    base) base ;;
    gateway-prerequisites) gateway_prerequisites ;;
    seal) seal ;;
    *) exit 64 ;;
esac
