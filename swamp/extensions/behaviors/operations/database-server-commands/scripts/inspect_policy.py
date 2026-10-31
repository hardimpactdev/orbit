"""Observe Valkey admission and policy before any container or ACL mutation."""

import hashlib

from server_common import Refused, main, valkey


def acl_state(rules, hashes):
    groups = {"flags": [], "keys": [], "channels": [], "commands": []}
    for token in rules.split():
        if token == "sanitize-payload":
            continue
        group = (
            "keys"
            if token.startswith(("~", "%")) or token in ("allkeys", "resetkeys")
            else "channels"
            if token.startswith("&") or token in ("allchannels", "resetchannels")
            else "commands"
            if token.startswith(("+", "-")) or token in ("allcommands", "nocommands")
            else "flags"
        )
        groups[group].append(token)
    for group in ("flags", "keys", "channels"):
        groups[group].sort()
    return groups, sorted(hashes)


def inspect(intent, material):
    if intent["driver"] != "redis":
        return {"rebuild": False}
    if not intent["validate_existing"] and not intent["was_active"]:
        return {"rebuild": True}
    try:
        actual = {}
        for line in valkey(intent, material, "ACL", "LIST").splitlines():
            prefix, name, *tokens = line.split()
            if prefix != "user" or name in actual:
                raise Refused("database.server_adoption_refused")
            actual[name] = acl_state(
                " ".join(t for t in tokens if not t.startswith("#")),
                [t[1:] for t in tokens if t.startswith("#")],
            )
        expected = {
            user["name"]: acl_state(user["rules"], user["hashes"])
            for user in material["payload"]["users"]
            if user["name"] != "default"
        }
        expected["default"] = acl_state(
            "on " + ("nopass " if intent["default_open"] else "") + "~* &* +@all",
            []
            if intent["default_open"]
            else [hashlib.sha256(material["password"].encode()).hexdigest()],
        )
        if intent["validate_existing"]:
            if actual != expected:
                raise Refused("database.server_adoption_refused")
            for key, value in {
                "dir": "/data",
                "appendonly": "yes",
                "appenddirname": "appendonlydir",
                "appendfilename": "appendonly.aof",
                "dbfilename": "dump.rdb",
                "databases": "16",
            }.items():
                if valkey(intent, material, "CONFIG", "GET", key).splitlines() != [
                    key,
                    value,
                ]:
                    raise Refused("database.server_adoption_refused")
            return {"rebuild": True}
        if (
            intent["default_open"]
            and "nopass" not in actual.get("default", ({"flags": []}, []))[0]["flags"]
        ):
            raise Refused("database.server_policy_changed")
        configured = valkey(intent, material, "CONFIG", "GET", "aclfile").splitlines()
        return {
            "rebuild": actual != expected
            or configured != ["aclfile", "/data/users.acl"]
        }
    except Refused:
        if intent["validate_existing"]:
            raise Refused("database.server_adoption_refused") from None
        if intent["default_open"]:
            raise Refused("database.server_policy_changed") from None
        return {"rebuild": True}


if __name__ == "__main__":
    main(inspect)
