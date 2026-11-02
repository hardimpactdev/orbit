"""One official Valkey CLI action per native step; secret arguments stay on stdin."""

import hashlib

from create_common import Refused, valkey


def index(i, m, _item):
    if i["index"] is not None:
        config = valkey(i, m, "CONFIG", "GET", "databases")
        value = (
            config.get("databases")
            if isinstance(config, dict)
            else config[1]
            if isinstance(config, list)
            and len(config) == 2
            and config[0] == "databases"
            else None
        )
        if not isinstance(value, str) or not value.isdecimal() or int(value) < 1:
            raise Refused("database.server_command_failed")
        if i["index"] >= int(value):
            raise Refused("database.index_invalid")
    return {"ready": True}


def user(i, m, _item):
    prior = valkey(i, m, "ACL", "GETUSER", i["username"])
    if prior is not None:
        if isinstance(prior, list):
            prior = dict(zip(prior[::2], prior[1::2]))
        if not isinstance(prior, dict) or hashlib.sha256(
            m["payload"]["password"].encode()
        ).hexdigest() not in prior.get("passwords", []):
            raise Refused("database.name_conflict")
    return {"ready": True}


def set_user(i, m, _item):
    rules = [
        "reset",
        "on",
        "#" + hashlib.sha256(m["payload"]["password"].encode()).hexdigest(),
        *("~" + p for p in i["patterns"]),
        "resetchannels",
        "-@all",
        *("+" + p for p in i["allow"]),
        "-@admin",
        "-@dangerous",
        "-function",
        "-script",
        # FLUSHDB clears only the client's selected index; give each such user its own index. FLUSHALL stays denied.
        *(["+flushdb"] if "flushdb" in i["allow"] else []),
    ]
    if valkey(i, m, "ACL", "SETUSER", i["username"], *rules, private=True) != "OK":
        raise Refused("database.server_command_failed")
    return {"ready": True}


def save(i, m, _item):
    if valkey(i, m, "ACL", "SAVE") != "OK":
        raise Refused("database.server_command_failed")
    return {"ready": True}
