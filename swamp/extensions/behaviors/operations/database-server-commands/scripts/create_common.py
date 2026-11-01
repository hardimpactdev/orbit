"""Database creation input checks and fixed client primitives, without orchestration."""

import base64
import hashlib
import hmac
import ipaddress
import json
import re
import sys

from server_common import DOCKER, SOCKET, Refused, client, command, credentials

SYSTEM = {"mysql", "sys", "performance_schema", "information_schema", "postgres"}


def system(name):
    return name.lower() in SYSTEM or name.lower().startswith("template")


def validate(i, m):
    try:
        names = i["databases"]
        user = i["username"]
        assert i["driver"] in ("mysql", "pgsql", "redis") and type(i["repair"]) is bool
        assert re.fullmatch(r"[A-Za-z0-9_-]{1,63}", user)
        assert (
            isinstance(names, list)
            and len(names) <= 34
            and len(names) == len(set(names))
        )
        assert all(
            isinstance(n, str)
            and re.fullmatch(r"[A-Za-z0-9_]{1,64}", n)
            and not system(n)
            for n in names
        )
        assert (
            ipaddress.ip_address(i["host"]).version == 4
            and type(i["port"]) is int
            and 1 <= i["port"] <= 65535
        )
        assert isinstance(m["password"], str) and 1 <= len(m["password"]) <= 16384
        assert not any(c in m["password"] for c in "\0\r\n")
        assert set(m) == {"password", "payload"} and set(m["payload"]) == {
            "password",
            "salt",
        }
        assert re.fullmatch(r"[A-Za-z0-9]{40}", m["payload"]["password"])
        assert len(base64.b64decode(m["payload"]["salt"], validate=True)) == 16
        assert i["locale"] in (None, "C", "C.UTF-8")
        assert isinstance(i["patterns"], list) and len(i["patterns"]) <= 100
        assert all(
            isinstance(p, str) and 1 <= len(p) <= 1024 and not re.search(r"\s|\x00", p)
            for p in i["patterns"]
        )
        assert isinstance(i["allow"], list) and len(i["allow"]) <= 100
        assert all(
            isinstance(p, str) and re.fullmatch(r"@?[a-z][a-z0-9|_-]*", p)
            for p in i["allow"]
        )
        if i["driver"] == "redis":
            assert not names and i["container"] is None and i["test_prefix"] is None
            assert i["index"] is None or type(i["index"]) is int and i["index"] >= 0
        else:
            assert names and re.fullmatch(r"[A-Za-z0-9_]+", user)
            assert re.fullmatch(
                r"orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*", i["container"]
            )
            if i["driver"] == "mysql":
                assert len(user) <= 32
                prefix = i["test_prefix"]
                assert (
                    prefix is None
                    or re.fullmatch(r"[A-Za-z0-9_]{1,64}", prefix)
                    and not any(
                        n.startswith(prefix.lower()) for n in SYSTEM | {"template"}
                    )
                    and not prefix.lower().startswith("template")
                )
            else:
                assert all(len(n) <= 63 for n in names) and i["test_prefix"] is None
    except (AssertionError, KeyError, TypeError, ValueError):
        raise Refused("database.server_admin_invalid") from None


def literal(value, pg=False):
    return "'" + (value if pg else value.replace("\\", "\\\\")).replace("'", "''") + "'"


def identifier(value, pg=False):
    return ('"' + value + '"') if pg else ("`" + value + "`")


def grant_name(value, wildcard=False):
    return value.replace("_", r"\_") + ("%" if wildcard else "")


def verifier(password, salt, iterations=4096):
    salted = hashlib.pbkdf2_hmac(
        "sha256", password.encode(), base64.b64decode(salt, validate=True), iterations
    )
    stored = hashlib.sha256(hmac.digest(salted, b"Client Key", "sha256")).digest()
    server = hmac.digest(salted, b"Server Key", "sha256")
    return f"SCRAM-SHA-256${iterations}:{salt}${base64.b64encode(stored).decode()}:{base64.b64encode(server).decode()}"


def sql(i, m, statement, *, owner=False):
    pg = i["driver"] == "pgsql"
    user = i["username"] if owner else "postgres" if pg else "root"
    argv = (
        [
            "psql",
            "--no-psqlrc",
            "--host=127.0.0.1",
            "--username=" + user,
            "--dbname=postgres",
            "--no-align",
            "--tuples-only",
            "--quiet",
            "--set=ON_ERROR_STOP=1",
        ]
        if pg
        else [
            "mysql",
            "--protocol=TCP",
            "--host=127.0.0.1",
            "--user=" + user,
            "--batch",
            "--skip-column-names",
        ]
    )
    material = {"password": m["payload"]["password"] if owner else m["password"]}
    reply = client(i, material, argv, statement)
    if reply.returncode or len(reply.stdout.encode()) > 1024 * 1024:
        raise Refused("database.server_admin_failed")
    return reply.stdout


def valkey(i, m, *argv, private=False):
    # Use the official Valkey client image; no host package or custom network protocol.
    with credentials(i, m) as path:
        args = [
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
            i["host"],
            "-p",
            str(i["port"]),
            "--user",
            "default",
        ]
        data = (
            " ".join(json.dumps(a, ensure_ascii=False) for a in argv) + "\n"
            if private
            else None
        )
        reply = command(args if private else [*args, *argv], data)
    try:
        value = json.loads(reply.stdout)
        if (
            reply.returncode
            or "AUTH failed" in reply.stderr
            or isinstance(value, str)
            and value.startswith(("ERR ", "NOAUTH", "NOPERM", "WRONGPASS"))
        ):
            raise ValueError()
        return value
    except (ValueError, TypeError):
        raise Refused("database.server_command_failed") from None


def main(action):
    try:
        i = json.loads(sys.argv[1])
        item = json.loads(sys.argv[2]) if len(sys.argv) > 2 else {}
        m = json.load(sys.stdin)
        validate(i, m)
        result = action(i, m, item)
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- keep private client exceptions out of native diagnostics.
        print('{"error_code":"database.create_outcome_unknown"}')
        raise SystemExit(1) from None
    print(json.dumps(result))
