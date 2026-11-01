"""One native Valkey closure action through its official CLI; no raw protocol."""

import hashlib
import ipaddress
import json
import sys

from server_common import DOCKER, SOCKET, Refused, command, credentials


def run(intent, material, *commands):
    with credentials({"driver": "redis"}, material) as path:
        reply = command(
            [
                DOCKER,
                SOCKET,
                "run",
                "--rm",
                "-i",
                "--network=host",
                "--env-file",
                path,
                "valkey/valkey:8.1",
                "valkey-cli",
                "--json",
                "-h",
                intent["host"],
                "-p",
                str(intent["port"]),
                "--user",
                "default",
            ],
            "".join(
                " ".join(json.dumps(word) for word in row) + "\n" for row in commands
            ),
        )
    lines = reply.stdout.splitlines()
    if len(commands) == 3:
        # The official CLI prints CLIENT LIST as raw lines even with --json.
        rows = [json.loads(lines[0]), "\n".join(lines[1:-1]), json.loads(lines[-1])]
    else:
        rows = [json.loads(line) for line in lines]
    if (
        reply.returncode
        or "AUTH failed" in reply.stderr
        or len(rows) != len(commands)
        or len(reply.stdout) > 1024 * 1024
        or any(
            isinstance(row, str)
            and row.startswith(("ERR ", "NOAUTH", "WRONGPASS", "NOPERM"))
            for row in rows
        )
    ):
        raise Refused("database.server_command_failed")
    return rows


def probe(intent, material):
    if not intent["default_open"]:
        if run(intent, material, ["PING"]) != ["PONG"]:
            raise Refused("database.server_command_failed")
        return {"ready": True, "was_open": False}
    own, clients, acl = run(
        intent,
        material,
        ["CLIENT", "ID"],
        ["CLIENT", "LIST", "USER", "default"],
        ["ACL", "GETUSER", "default"],
    )
    assert type(own) is int and isinstance(clients, str)
    if isinstance(acl, list):
        assert len(acl) <= 32 and len(acl) % 2 == 0
        acl = dict(zip(acl[::2], acl[1::2]))
    assert isinstance(acl, dict)
    flags = acl["flags"]
    assert (
        isinstance(flags, list)
        and len(flags) <= 32
        and all(isinstance(x, str) for x in flags)
    )
    if any(
        next((x for x in line.split(" ") if x.startswith("id=")), None) != f"id={own}"
        for line in clients.splitlines()
        if line
    ):
        raise Refused("database.server_in_use")
    return {"ready": True, "was_open": "nopass" in flags}


def action(intent, material, phase):
    assert ipaddress.ip_address(intent["host"]).version == 4
    assert type(intent["port"]) is int and 1 <= intent["port"] <= 65535
    assert type(intent["default_open"]) is bool and set(material) == {"password"}
    assert (
        isinstance(material["password"], str)
        and 1 <= len(material["password"]) <= 16384
    )
    assert not any(c in material["password"] for c in "\0\r\n")
    if phase == "probe":
        return probe(intent, material)
    if phase == "close":
        # Recheck clients immediately before changing access, including native retry.
        probe(intent, material)
        digest = hashlib.sha256(material["password"].encode()).hexdigest()
        expected = "OK"
        row = ["ACL", "SETUSER", "default", "resetpass", "#" + digest]
    elif phase == "save":
        row, expected = ["ACL", "SAVE"], "OK"
    elif phase == "restore":
        row, expected = ["ACL", "SETUSER", "default", "resetpass", "nopass"], "OK"
    elif phase == "disconnect":
        row, expected = ["CLIENT", "KILL", "USER", "default", "SKIPME", "yes"], None
    else:
        raise ValueError("Unknown closure action")
    value = run(intent, material, row)[0]
    if expected is None:
        assert type(value) is int and value >= 0
    elif value != expected:
        raise Refused("database.server_command_failed")
    return {"ready": True}


if __name__ == "__main__":
    try:
        value = action(json.loads(sys.argv[1]), json.load(sys.stdin), sys.argv[2])
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- never return private CLI diagnostics.
        print('{"error_code":"database.server_command_failed"}')
        raise SystemExit(1) from None
    print(json.dumps(value))
