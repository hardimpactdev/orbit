import hashlib
import ipaddress
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path("/")
LIMIT = 4 * 1024 * 1024


class Unavailable(Exception):
    pass


def bounded(path):
    with open(path, "rb") as f:
        data = f.read(LIMIT + 1)
        if len(data) > LIMIT:
            raise Unavailable()
        return data.decode("utf-8")


def run(argv):
    process_read = argv[:2] == ["/usr/bin/systemctl", "show"]
    value = subprocess.run(
        argv,
        check=False,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE if process_read else subprocess.DEVNULL,
        timeout=3 if process_read else 15,
    )
    if len(value.stdout) > LIMIT or process_read and value.stderr:
        raise Unavailable()
    return value.returncode, value.stdout.decode("utf-8").strip()


def hash_(value):
    return hashlib.sha256(value.encode()).hexdigest()


def subset(value, prefixes):
    return "\n".join(
        line for line in value.splitlines() if any(line.startswith(p) for p in prefixes)
    )


def endpoint(value):
    if value in ("any", "Anywhere", "0.0.0.0/0", "::/0"):
        return "any"
    try:
        return (
            str(ipaddress.ip_network(value, strict=False))
            if "/" in value and int(value.split("/")[1]) not in (32, 128)
            else str(ipaddress.ip_interface(value).ip)
        )
    except ValueError:
        return None


def port(value):
    if not re.fullmatch(r"\d{1,5}(?::\d{1,5})?", value):
        return None
    numbers = [int(v) for v in value.split(":")]
    if (
        any(v < 1 or v > 65535 for v in numbers)
        or len(numbers) == 2
        and numbers[0] > numbers[1]
    ):
        return None
    return ":".join(str(v) for v in numbers)


def interface(value):
    result = value.rsplit(" on ", 1)
    if len(result) == 2 and re.fullmatch(r"[a-zA-Z0-9_.:-]{1,64}", result[1]):
        return result[0].strip(), result[1]
    return value.strip(), None


def firewall_row(line):
    comment = line.rsplit("#", 1)[1].strip() if "#" in line else ""
    row = {"comment": comment, "shape": None}
    match = re.fullmatch(
        r"(?:\[\s*(\d+)\]\s*)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?",
        line.strip(),
    )
    if not match or (match[6] or "") != comment:
        return row
    family = "v6" if "(v6)" in match[2] or "(v6)" in match[5] else "v4"
    destination, out_interface = interface(match[2].replace("(v6)", "").strip())
    source, in_interface = interface(match[5].replace("(v6)", "").strip())
    target = re.fullmatch(
        r"(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)(?:/(tcp|udp))?", destination
    )
    if target:
        destination, number, protocol = (
            endpoint(target[1] or "any"),
            port(target[2]),
            target[3] or "any",
        )
    else:
        destination, number, protocol = endpoint(destination), "any", "any"
    source = endpoint(source)
    if destination is None or source is None or number is None:
        return row
    direction = match[4].lower()
    if direction == "in":
        in_interface, out_interface = out_interface or in_interface, None
    elif direction == "out":
        in_interface, out_interface = None, out_interface or in_interface
    row["shape"] = {
        "comment": comment,
        "action": match[3].lower(),
        "direction": direction,
        "source": source,
        "destination": destination,
        "port": number,
        "protocol": protocol,
        "in_interface": in_interface,
        "out_interface": out_interface,
        "family": family,
    }
    return row


def firewall(data):
    if data.get("stored") is not False:
        raise Unavailable()
    try:
        code, text = run(["/usr/sbin/ufw", "status", "numbered"])
    except FileNotFoundError:
        return {
            "backend": "absent",
            "ownerships": ["missing"] * len(data["expected"]),
            "matches": False,
            "live": [],
        }
    if code:
        raise Unavailable()
    backend = [
        line.strip().lower()[8:]
        for line in text.splitlines()
        if line.strip().lower()
        in ("status: active", "status: inactive", "status: absent")
    ]
    if len(backend) != 1:
        raise Unavailable()
    actual = [firewall_row(line) for line in text.splitlines()]
    actual = [r for r in actual if r["comment"] or r["shape"]]

    def ownership(expected):
        owned = [r for r in actual if r["comment"] == expected["comment"]]
        if not owned:
            return "missing"
        families = set()
        for row in owned:
            shape = row["shape"]
            if (
                not shape
                or shape
                != {**expected, "family": expected["family"] or shape["family"]}
                or shape["family"] in families
            ):
                return "drift"
            families.add(shape["family"])
        return (
            "exact"
            if families
            == ({expected["family"]} if expected["family"] else {"v4", "v6"})
            else "drift"
        )

    owners = [ownership(e) for e in data["expected"]]
    exclusive = all(
        not any(
            r["comment"] == prefix or r["comment"].startswith(prefix + ":")
            for prefix in data["exclusive_families"]
        )
        or r["shape"] is not None
        and any(
            r["shape"] == {**e, "family": e["family"] or r["shape"]["family"]}
            for e in data["expected"]
        )
        for r in actual
    )
    return {
        "backend": backend[0],
        "ownerships": owners,
        "matches": backend[0] == "active"
        and exclusive
        and all(v == "exact" for v in owners),
        "live": [
            r["shape"] for r in actual if r["shape"] and r["shape"]["family"] != "v6"
        ],
    }


def runtime_read(name, data):
    if name != "firewall.inspect":
        raise Unavailable()
    return firewall(data)


if __name__ == "__main__":
    try:
        payload = json.loads(sys.argv[1])
        print(json.dumps(runtime_read(payload["name"], payload["input"])))
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError, Unavailable):
        print('{"error_code":"observation.inspection_unavailable"}')
