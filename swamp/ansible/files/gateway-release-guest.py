#!/usr/bin/python3
"""Converge prepare-gateway before exposing the host Gateway's private state."""
import json
import re
import shlex
import subprocess
import sys
from pathlib import Path

PREP = "prepare-gateway"
RUNTIME = "/opt/orbit-runtime/current"
ENV_DIR = "/opt/orbit-gateway-environment"
PROJECTED_ENV = "/home/orbit/shared/.gateway-release-environment"
HOME_STATE = "/home/orbit/.orbit"
STORAGE = "/home/orbit/shared/gateway-storage"


def incus(*args):
    return subprocess.check_output(["incus", "--force-local", "--project", "orbit-releases", *args],
                                   text=True, stdin=subprocess.DEVNULL).strip()


def systemctl(*args):
    return subprocess.check_output(["systemctl", *args], text=True, stdin=subprocess.DEVNULL).strip()


def guest(*args):
    return incus("exec", PREP, "--", *args)


def info():
    return json.loads(subprocess.check_output(
        ["incus", "--force-local", "query", "/1.0/instances/prepare-gateway?project=orbit-releases"],
        text=True, stdin=subprocess.DEVNULL))


def disk(name, src, dst, shift, readonly):
    spec = {"source": src, "path": dst, "type": "disk"}
    if shift:
        spec["shift"] = "true"
    if readonly:
        spec["readonly"] = "true"
    current = info()["devices"].get(name)
    if current is None:
        incus("config", "device", "add", PREP, name, "disk",
            *(f"{k}={v}" for k, v in spec.items() if k != "type"))
    elif current != spec:
        raise ValueError(f"preparer has a different {name} mount")


ACCOUNT = r"""
set -eu
uid=$1 gid=$2
# No shared devices are attached on first boot. Wait for stock cloud-init so it cannot recreate ubuntu afterwards.
if command -v cloud-init >/dev/null && ! test -f /etc/cloud/cloud-init.disabled; then
    timeout 240 cloud-init status --wait >/dev/null
    touch /etc/cloud/cloud-init.disabled
fi
for unit in ssh.socket ssh.service; do
    if systemctl cat "$unit" >/dev/null 2>&1; then systemctl mask --now "$unit"; fi
done
if getent passwd ubuntu >/dev/null; then
    test "$(getent passwd ubuntu | cut -d: -f6)" = /home/ubuntu
    userdel --remove ubuntu
fi
# The Ubuntu cloud image can reserve GID 1000 for the empty lxd group. Preserve that group's
# identity and its files before assigning the host service GID to orbit. No shared devices are present.
if test "$(getent group "$gid" | cut -d: -f1)" = lxd; then
    test -z "$(getent group lxd | cut -d: -f4)"
    if getent passwd | awk -F: -v g="$gid" '$4 == g {found=1} END {exit !found}'; then exit 1; fi
    if systemctl is-active --quiet snap.lxd.daemon.service; then exit 1; fi
    replacement=60000
    while getent group "$replacement" >/dev/null; do replacement=$((replacement + 1)); done
    test "$replacement" -lt 65000
    groupmod -g "$replacement" lxd
    find / -xdev -gid "$gid" -exec chgrp -h lxd {} +
fi
if test -f /etc/sudoers.d/90-cloud-init-users; then sed -i '/^ubuntu[[:space:]]/d' /etc/sudoers.d/90-cloud-init-users; fi
if ! getent group orbit >/dev/null; then groupadd -g "$gid" orbit; fi
if ! getent passwd orbit >/dev/null; then
    useradd --create-home --home-dir /home/orbit --shell /bin/bash -u "$uid" -g "$gid" orbit
fi
test "$(id -u orbit)" = "$uid"
test "$(id -G orbit)" = "$gid"
test "$(getent passwd orbit | cut -d: -f6)" = /home/orbit
if getent passwd | awk -F: '$3 >= 1000 && $3 < 60000 && $1 != "orbit" {bad=1} END {exit !bad}'; then
    echo 'Unexpected stock image login account' >&2; exit 1
fi
if command -v sudo >/dev/null && runuser -u orbit -- sudo -n -l >/dev/null 2>&1; then
    echo 'The service account has a sudo grant' >&2; exit 1
fi
install -d -m 0750 -o orbit -g orbit /home/orbit/shared
export DEBIAN_FRONTEND=noninteractive
apt-get -qq update
apt-get -qq install -y --no-install-recommends ca-certificates curl git jq openssh-client unzip
install -d -m 0755 /etc/systemd/network/10-netplan-eth0.network.d /etc/tmpfiles.d
# Incus gives every clone a new MAC. DHCP must use it even when the disk's machine-id was snapshotted.
printf '[DHCPv4]\nClientIdentifier=mac\n' >/etc/systemd/network/10-netplan-eth0.network.d/dhcp.conf
printf 'd /run/orbit 0755 root root -\nd /run/orbit/ssh-mux 0700 orbit orbit -\n' >/etc/tmpfiles.d/orbit-gateway.conf
systemd-tmpfiles --create /etc/tmpfiles.d/orbit-gateway.conf
"""


def push(source, path, mode):
    guest("mkdir", "-p", str(Path(path).parent))
    incus("file", "push", "--uid", "0", "--gid", "0", "--mode", mode, str(source), PREP + path)


def install_units():
    listed = systemctl("list-unit-files", "--plain", "--no-legend", "orbit-*.service", "orbit-*.timer")
    units = {line.split()[0] for line in listed.splitlines() if line}
    required = {"orbit-agent-view.service", "orbit-runtime-hibernator.service", "orbit-runtime-hibernator.timer"}
    if not required.issubset(units):
        raise ValueError("converge the host Gateway runtime services before preparing the container")
    managed = re.compile(r"orbit-(?:process-[1-9][0-9]*-(?:horizon|schedule-work)|gateway-(?:schedule|horizon))\.service")
    for unit in sorted(required | {name for name in units if managed.fullmatch(name)}):
        fragment = systemctl("show", "-p", "FragmentPath", "--value", unit)
        if not fragment.startswith("/etc/systemd/system/") or Path(fragment).name != unit or not Path(fragment).is_file():
            raise ValueError(f"unexpected Gateway unit location: {unit}")
        push(fragment, "/etc/systemd/system/" + unit, "0644")
        for override in shlex.split(systemctl("show", "-p", "DropInPaths", "--value", unit)):
            if override.startswith("/run/systemd/system/"):
                continue
            if Path(override).parent != Path(fragment + ".d"):
                raise ValueError(f"unexpected drop-in for {unit}")
            push(override, override, "0644")
        environment = systemctl("show", "-p", "EnvironmentFiles", "--value", unit)
        for path in re.findall(r"(/\S+) \(ignore_errors=(?:yes|no)\)", environment):
            if path == "/home/orbit/shared/gateway.env":
                continue
            if not re.fullmatch(r"/etc/orbit/processes/[1-9][0-9]*/[a-f0-9]{64}\.env", path):
                raise ValueError(f"unexpected environment file for {unit}")
            push(path, path, "0600")
        # The release handover starts only the verified candidate's active workload.
        guest("systemctl", "disable", "--now", unit)
    guest("systemctl", "daemon-reload")


def main(plan):
    current = info()
    if current["status"] == "Stopped":
        incus("start", PREP)
    elif current["status"] != "Running":
        raise ValueError("prepare-gateway must be running or stopped before initial setup")
    # A failed first boot resumes before any secrets are exposed. Never alter guest accounts after attaching state.
    if current["config"].get("user.orbit.release.account") != "ready":
        if set(current["devices"]) != {"root", "eth0"}:
            raise ValueError("unexpected devices before guest account setup")
        guest("/bin/bash", "-ec", ACCOUNT, "account", str(plan["uid"]), str(plan["gid"]))
        incus("config", "set", PREP, "user.orbit.release.account=ready")
    if guest("id", "-u", "orbit") != str(plan["uid"]) or guest("id", "-G", "orbit") != str(plan["gid"]):
        raise ValueError("guest and host service account identities differ")
    subprocess.run(["/usr/local/libexec/orbit-gateway-environment", "refresh"], check=True)
    disk("runtime", plan["runtime_dir"], RUNTIME, False, True)
    disk("env", PROJECTED_ENV, ENV_DIR, True, True)
    disk("state", HOME_STATE, HOME_STATE, True, False)
    disk("storage", STORAGE, STORAGE, True, False)
    # The guest always sees the current name in the projected directory, even after atomic host replacement.
    guest("ln", "-sfnT", ENV_DIR + "/gateway.env", "/home/orbit/shared/gateway.env")
    for path in ["/usr/bin/php8.5", "/usr/bin/php"]:
        guest("ln", "-sfnT", RUNTIME + "/php/8.5/bin/php", path)
    guest("runuser", "-u", "orbit", "--", "/bin/sh", "-ec",
          "test -r /home/orbit/shared/gateway.env && test ! -w /home/orbit/shared/gateway.env && "
          "test -w /home/orbit/shared/gateway-storage && test -w /home/orbit/.orbit/gateway.sqlite && "
          "test -x /opt/orbit-runtime/current/bin/composer && test -x /opt/orbit-runtime/current/php/8.5/bin/frankenphp")
    guest("/usr/bin/php8.5", "-r", "exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 ? 0 : 1);")
    push("/etc/orbit/agent/ca.pem", "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt", "0644")
    guest("update-ca-certificates")
    assets = Path(plan["source"]) / "swamp/ansible/files/gateway-web"
    push(assets / "Caddyfile", "/etc/orbit/gateway-web/Caddyfile", "0644")
    push(assets / "orbit-gateway-web.service", "/etc/systemd/system/orbit-gateway-web.service", "0644")
    install_units()


if __name__ == "__main__":
    try:
        main(json.loads(sys.argv[1]))
    except (ValueError, OSError, KeyError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
