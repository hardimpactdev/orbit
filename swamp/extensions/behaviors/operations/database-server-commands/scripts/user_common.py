"""Fixed SQL user client and admitted input checks; no workflow control."""

import base64
import re

from create_common import system
from server_common import Refused, client

CLIENTS = {
    "mysql": ["mysql", "--user=root", "--batch", "--skip-column-names"],
    "pgsql": [
        "psql",
        "--no-psqlrc",
        "--host=127.0.0.1",
        "--username=postgres",
        "--dbname=postgres",
        "--no-align",
        "--tuples-only",
        "--quiet",
        "--set=ON_ERROR_STOP=1",
    ],
}


def validate(intent, material):
    try:
        assert set(intent) <= {
            "driver",
            "container",
            "database",
            "owner_username",
            "username",
            "read_only",
            "replication",
            "known_user",
            "ssh",
        }
        assert intent["driver"] in CLIENTS
        assert re.fullmatch(
            r"orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*", intent["container"]
        )
        assert re.fullmatch(r"[A-Za-z0-9_]{1,32}", intent["username"])
        limit = 63 if intent["driver"] == "pgsql" else 64
        assert re.fullmatch(r"[A-Za-z0-9_]{1," + str(limit) + r"}", intent["database"])
        assert not system(intent["database"])
        assert re.fullmatch(r"[A-Za-z0-9_]{1,63}", intent["owner_username"])
        assert intent["username"] != intent["owner_username"]
        assert all(
            type(intent[k]) is bool for k in ("read_only", "replication", "known_user")
        )
        assert (
            not intent["replication"]
            or intent["driver"] == "pgsql"
            and intent["read_only"]
        )
        assert set(material) == {"password", "payload"}
        assert (
            isinstance(material["password"], str) and len(material["password"]) <= 16384
        )
        assert not any(c in material["password"] for c in "\0\r\n")
        assert set(material["payload"]) == {"password", "salt"}
        password = material["payload"]["password"]
        assert isinstance(password, str) and 1 <= len(password) <= 1024
        assert not any(c in password for c in "\0\r\n")
        assert intent["driver"] != "pgsql" or all(32 <= ord(c) <= 126 for c in password)
        assert len(base64.b64decode(material["payload"]["salt"], validate=True)) == 16
    except (AssertionError, KeyError, TypeError, ValueError):
        raise Refused("database.server_admin_invalid") from None


def execute(intent, material, sql):
    result = client(intent, material, CLIENTS[intent["driver"]], sql)
    if result.returncode:
        raise Refused("database.server_admin_failed")
    return result.stdout
