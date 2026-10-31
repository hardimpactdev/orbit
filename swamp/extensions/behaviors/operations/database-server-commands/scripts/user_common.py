"""Fixed SQL user client and admitted input checks; no workflow control."""

import re

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
    if (
        set(intent)
        - {"driver", "container", "username", "replication", "known_user", "ssh"}
        or intent.get("driver") not in CLIENTS
        or not isinstance(intent.get("container"), str)
        or not re.fullmatch(
            r"orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*", intent["container"]
        )
        or not isinstance(intent.get("username"), str)
        or not re.fullmatch(r"[A-Za-z0-9_]{1,32}", intent["username"])
        or type(intent.get("replication")) is not bool
        or type(intent.get("known_user")) is not bool
        or not isinstance(material.get("password"), str)
        or len(material["password"]) > 16384
        or not isinstance(material.get("sql"), str)
        or not material["sql"].strip()
        or len(material["sql"].encode()) > 1024 * 1024
        or "\0" in material["password"] + material["sql"]
    ):
        raise Refused("database.server_admin_invalid")


def execute(intent, material, sql):
    result = client(intent, material, CLIENTS[intent["driver"]], sql)
    if result.returncode:
        raise Refused("database.server_admin_failed")
    return result.stdout
