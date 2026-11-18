#!/usr/bin/python3
"""Apply or inspect one Instance Process in the live guest selected by orbit-instance-release-control."""
import hashlib
import json
import os
import re
import subprocess
import sys

UNIT = re.compile(r"orbit-process-([1-9][0-9]*)-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.service")
INSTALL = """set -eu
mkdir -p -- "${1%/*}"
f=$(mktemp "${1%/*}/.orbit-process.XXXXXXXX")
trap 'rm -f -- "$f"' EXIT
cat > "$f"
chmod "$2" "$f"
if test -f "$1" && ! test -L "$1" && cmp -s "$1" "$f" && test "$(stat -c %a "$1")" = "${2#0}"; then exit 0; fi
mv -fT -- "$f" "$1"
exit 3
"""


def process(instance, sha, machine, action, unit, args, stdin=sys.stdin, run=subprocess.run):
    match = UNIT.fullmatch(unit)
    if (not re.fullmatch(r"[1-9][0-9]*", instance) or not re.fullmatch(r"[0-9a-f]{40}", sha)
            or machine != f"instance-{instance}-{sha[:12]}" or not match):
        raise ValueError("invalid Instance Process")
    prefix = ["incus", "--force-local", "--project", "orbit-releases", "exec", machine, "--"]

    def guest(*argv, content=None, check=True):
        return run([*prefix, *argv], input=content, stdin=subprocess.DEVNULL if content is None else None,
                   text=True, timeout=120, check=check).returncode

    def install(path, mode, content):
        code = guest("sh", "-c", INSTALL, "install", path, mode, content=content, check=False)
        if code not in (0, 3):
            raise ValueError("could not install the Instance Process")
        return code == 3

    if action == "logs" and len(args) == 1 and args[0].isdecimal() and 1 <= int(args[0]) <= 1000:
        return guest("journalctl", "--no-pager", "--quiet", "--unit=" + unit, "--reverse", "--lines=" + args[0], "--output=short-iso", "--utc")
    if args:
        raise ValueError("unexpected process argument")
    if action == "status":
        return guest("systemctl", "show", "--property=LoadState", "--property=ActiveState", "--", unit)
    if action == "is-active":
        return guest("systemctl", "is-active", "--", unit, check=False)
    if action in ("start", "stop", "restart"):
        if action == "stop":
            return guest("systemctl", "disable", "--now", "--", unit)
        guest("systemctl", "enable", "--", unit)
        return guest("systemctl", action, "--", unit)
    file = "/etc/systemd/system/" + unit
    if action == "destroy":
        if guest("test", "-f", file, check=False) == 0:
            guest("systemctl", "disable", "--now", "--", unit)
            guest("rm", "-f", "--", file)
        guest("systemctl", "daemon-reload")
        return 0
    if action not in ("create", "update"):
        raise ValueError("unsupported process action")
    raw = stdin.read(1024 * 1024 + 1)
    if len(raw) > 1024 * 1024:
        raise ValueError("oversize Process definition")
    data = json.loads(raw)
    env, envpath, content, desired = data["environment"], data["environment_path"], data["unit"], data["desired"]
    if not isinstance(content, str) or not isinstance(env, str) or desired not in ("running", "stopped"):
        raise ValueError("invalid Process definition")
    if not re.fullmatch(r"/etc/orbit/processes/" + match[1] + r"/[0-9a-f]{64}\.env", envpath):
        raise ValueError("invalid Process environment path")
    mounts = data.get("mounts", [])
    if not isinstance(mounts, list) or len(mounts) > 64:
        raise ValueError("invalid required paths")
    if desired == "running":
        for mount in mounts:
            if (not isinstance(mount, str) or not mount.startswith("/")
                    or any(piece in (".", "..") for piece in mount.split("/"))):
                raise ValueError("invalid required path")
            guest("mountpoint", "--quiet", "--", mount)
    changed = False
    if env:
        if envpath.rsplit("/", 1)[1] != hashlib.sha256(env.encode()).hexdigest() + ".env":
            raise ValueError("process environment digest changed")
        changed = install(envpath, "0600", env)
    changed = install(file, "0644", content) or changed
    # A retry may find the files already installed even if the first daemon reload failed.
    guest("systemctl", "daemon-reload")
    if desired == "running":
        guest("systemctl", "enable", "--", unit)
        guest("systemctl", "restart" if changed or data.get("activation_pending") else "start", "--", unit)
    else:
        guest("systemctl", "disable", "--now", "--", unit)
    return 0


if __name__ == "__main__":
    try:
        if os.geteuid() != 0 or len(sys.argv) < 6:
            raise ValueError("invalid Process control")
        sys.exit(process(*sys.argv[1:6], sys.argv[6:]))
    except (OSError, KeyError, TypeError, ValueError, subprocess.SubprocessError):
        sys.exit("process.control_failed: could not apply the Instance Process action")
