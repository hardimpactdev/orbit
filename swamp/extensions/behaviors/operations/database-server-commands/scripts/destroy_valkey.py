"""One ACL action. Rollback material stays in a removed0600 target-side file."""

import json
import os
import tempfile
from pathlib import Path

from create_common import Refused, valkey


def snapshot(i, m, p, _item, scratch):
    rows = valkey(i, m, "ACL", "LIST")
    if not isinstance(rows, list) or len(rows) > 10000:
        raise Refused("database.server_command_failed")
    saved = {}
    for row in rows:
        prefix, name, *tokens = row.split(" ")
        if prefix != "user" or not name:
            raise Refused("database.server_command_failed")
        if name in p["users"]:
            saved[name] = tokens
    path = Path(scratch) / "acl-snapshot.json"
    # Retained material belongs to a failed native attempt and must remain usable.
    if path.exists():
        read_snapshot(path, p)
    else:
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(
                mode="w", dir=scratch, delete=False
            ) as target:
                temporary = Path(target.name)
                json.dump(saved, target)
                target.flush()
                os.fsync(target.fileno())
            os.replace(temporary, path)
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)
    return {"ready": True}


def read_snapshot(path, plan):
    try:
        saved = json.loads(path.read_text())
        assert isinstance(saved, dict) and set(saved).issubset(plan["users"])
        assert "default" not in saved
        assert all(
            isinstance(tokens, list) and all(isinstance(token, str) for token in tokens)
            for tokens in saved.values()
        )
        return saved
    except (ValueError, AssertionError, OSError):
        raise Refused("database.server_command_failed") from None


def remove(i, m, p, item, _scratch):
    if item not in p["users"] or item == "default":
        raise Refused("database.ownership_unproven")
    value = valkey(i, m, "ACL", "DELUSER", item)
    if type(value) is not int or value not in (0, 1):
        raise Refused("database.server_command_failed")
    return {"ready": True}


def save(i, m, _p, _item, _scratch):
    if valkey(i, m, "ACL", "SAVE") != "OK":
        raise Refused("database.server_command_failed")
    return {"ready": True}


def restore(i, m, p, item, scratch):
    if item not in p["users"] or item == "default":
        raise Refused("database.ownership_unproven")
    saved = read_snapshot(Path(scratch) / "acl-snapshot.json", p)
    if (
        item in saved
        and valkey(i, m, "ACL", "SETUSER", item, "reset", *saved[item], private=True)
        != "OK"
    ):
        raise Refused("database.server_command_failed")
    return {"ready": True}
