"""Remove only one exact managed Route LAN comment under the canonical UFW host lock.

The parent withdraws host catalogs before this action. Public input is delivered
through ORBIT_ROUTE_LAN_INPUT; this script reads no Gateway or private material.
"""

import contextlib
import fcntl
import ipaddress
import json
import os
import re
import stat
import subprocess
from pathlib import Path

LOCK = Path("/run/lock/orbit-firewall.lock")
LOCK_UID = 0


class Refusal(Exception):
    def __init__(self, code):
        self.code = code


@contextlib.contextmanager
def transaction_lock():
    try:
        parent = LOCK.parent.lstat()
        if not stat.S_ISDIR(parent.st_mode) or parent.st_uid != LOCK_UID:
            raise Refusal("firewall.lock_failed")
        descriptor = os.open(
            LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600
        )
    except OSError:
        raise Refusal("firewall.lock_failed")
    try:
        info = os.fstat(descriptor)
        if (
            not stat.S_ISREG(info.st_mode)
            or info.st_uid != LOCK_UID
            or stat.S_IMODE(info.st_mode) != 0o600
        ):
            raise Refusal("firewall.lock_failed")
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX)
        except OSError:
            raise Refusal("firewall.lock_failed")
        yield
    finally:
        os.close(descriptor)


def endpoint(value):
    if value in ("any", "Anywhere", "0.0.0.0/0", "::/0"):
        return "any"
    try:
        if "/" in value:
            network = ipaddress.ip_network(value, strict=False)
            return (
                str(network.network_address)
                if network.prefixlen == network.max_prefixlen
                else str(network)
            )
        return str(ipaddress.ip_address(value))
    except ValueError:
        return None


def port(value):
    if value == "any":
        return value
    if not re.fullmatch(r"[0-9]{1,5}(?::[0-9]{1,5})?", value):
        return None
    numbers = list(map(int, value.split(":")))
    if (
        any(n < 1 or n > 65535 for n in numbers)
        or len(numbers) == 2
        and numbers[0] > numbers[1]
    ):
        return None
    return ":".join(map(str, numbers))


def interface(value):
    if " on " in value:
        address, device = value.rsplit(" on ", 1)
        if re.fullmatch(r"[A-Za-z0-9_.:-]{1,64}", device):
            return address.strip(), device
    return value, None


def live_row(line):
    line = line.strip()
    comment = line.rsplit("#", 1)[1].strip() if "#" in line else ""
    row = dict(number=None, comment=comment, shape=None)
    match = re.fullmatch(
        r"(?:\[\s*(\d+)\]\s*)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?",
        line,
    )
    if not match or (match[6] or "") != comment:
        return row
    row["number"] = int(match[1]) if match[1] and int(match[1]) > 0 else None
    family = "v6" if "(v6)" in match[2] or "(v6)" in match[5] else "v4"
    target, target_if = interface(match[2].replace("(v6)", "").strip())
    source, source_if = interface(match[5].replace("(v6)", "").strip())
    numbered = re.fullmatch(
        r"(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)(?:/(tcp|udp))?", target
    )
    destination = endpoint(numbered[1] or "any") if numbered else endpoint(target)
    p = port(numbered[2]) if numbered else "any"
    protocol = (numbered[3] or "any") if numbered else "any"
    source = endpoint(source)
    if destination is None or source is None or p is None:
        return row
    direction = match[4].lower()
    incoming, outgoing = (
        (source_if, target_if)
        if direction == "fwd"
        else (
            (None, target_if or source_if)
            if direction == "out"
            else (target_if or source_if, None)
        )
    )
    row["shape"] = dict(
        comment=comment,
        action=match[3].lower(),
        direction=direction,
        source=source,
        destination=destination,
        port=p,
        protocol=protocol,
        in_interface=incoming,
        out_interface=outgoing,
        family=family,
    )
    return row


def live(text):
    statuses = [
        line.strip().lower()[8:]
        for line in text.splitlines()
        if line.strip().lower()
        in ("status: active", "status: inactive", "status: absent")
    ]
    if len(statuses) != 1:
        raise Refusal("firewall.status_unrecognized")
    rows = [live_row(line) for line in text.splitlines()]
    return statuses[0], [row for row in rows if row["shape"] or row["comment"]]


def ufw(args):
    try:
        reply = subprocess.run(
            ["/usr/sbin/ufw", *args],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=110,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise Refusal("firewall.status_failed")
    if len(reply.stdout) > 1048576 or len(reply.stderr) > 65536:
        raise Refusal("firewall.status_failed")
    return reply.returncode, reply.stdout.decode()


def status():
    code, text = ufw(["status", "numbered"])
    if code != 0:
        raise Refusal("firewall.status_failed")
    return live(text)


def change(args):
    if ufw(args)[0] != 0:
        raise Refusal("node.firewall_convergence_failed")


def active():
    backend, rows = status()
    if backend != "active":
        raise Refusal("node.firewall_convergence_failed")
    return rows


def delete_comments(comments, rows=None):
    if not comments:
        return
    rows = active() if rows is None else rows
    selected = [row for row in rows if row["comment"] in comments]
    if any(row["number"] is None for row in selected):
        raise Refusal("firewall.rule_collision")
    for number in sorted({row["number"] for row in selected}, reverse=True):
        change(["--force", "delete", str(number)])


def remove_route_lan(raw):
    if not isinstance(raw, dict) or set(raw) != {"route_id"}:
        raise Refusal("firewall.input_invalid")
    route_id = raw["route_id"]
    if (
        not isinstance(route_id, int)
        or isinstance(route_id, bool)
        or not 1 <= route_id <= 9007199254740991
    ):
        raise Refusal("firewall.input_invalid")
    comment = f"orbit:route-{route_id}-lan"
    with transaction_lock():
        backend, rows = status()
        if backend == "absent":
            raise Refusal("firewall.status_failed")
        delete_comments({comment}, rows)
        if any(row["comment"] == comment for row in status()[1]):
            raise Refusal("firewall.remove_verify_failed")
    return {"ready": True}


def main():
    try:
        result = remove_route_lan(json.loads(os.environ["ORBIT_ROUTE_LAN_INPUT"]))
    except Refusal as error:
        result = {"error_code": error.code}
    except (ValueError, TypeError, KeyError, OSError, UnicodeError):
        result = {"error_code": "firewall.input_invalid"}
    print(json.dumps(result))
    return 1 if "error_code" in result else 0


if __name__ == "__main__":
    raise SystemExit(main())
