"""Dispatch one fixed native removal action; never expose private client diagnostics."""

import ipaddress
import json
import re
import sys

import destroy_sql
import destroy_valkey
from create_common import Refused, system

ACTIONS = {
    "inventory": destroy_sql.inventory,
    "ownership": destroy_sql.ownership,
    "fence": destroy_sql.fence,
    "blockers": destroy_sql.blockers,
    "drop_database": destroy_sql.drop_database,
    "drop_user": destroy_sql.drop_user,
    "restore": destroy_sql.restore,
    "acl_snapshot": destroy_valkey.snapshot,
    "acl_remove": destroy_valkey.remove,
    "acl_save": destroy_valkey.save,
    "acl_restore": destroy_valkey.restore,
    "acl_restore_save": destroy_valkey.save,
}


def validate(i, m, p):
    assert i["driver"] in ("mysql", "pgsql", "redis")
    assert (
        ipaddress.ip_address(i["host"]).version == 4
        and type(i["port"]) is int
        and 1 <= i["port"] <= 65535
    )
    assert (
        set(m) == {"password"}
        and isinstance(m["password"], str)
        and len(m["password"]) <= 16384
        and not any(c in m["password"] for c in "\0\r\n")
    )
    assert isinstance(i["test_prefixes"], list) and len(i["test_prefixes"]) <= 1000
    assert all(
        re.fullmatch(r"[A-Za-z0-9_]{1,64}", n)
        and not system(n)
        and not any(
            s.startswith(n.lower())
            for s in (
                "mysql",
                "sys",
                "performance_schema",
                "information_schema",
                "postgres",
                "template",
            )
        )
        for n in i["test_prefixes"]
    )
    for n in [*i["databases"], *p.get("databases", [])]:
        assert re.fullmatch(r"[A-Za-z0-9_]{1,64}", n) and not system(n)
        assert i["driver"] != "pgsql" or len(n) <= 63
        assert (
            n in i["databases"]
            or i["driver"] == "mysql"
            and any(n.startswith(prefix) for prefix in i["test_prefixes"])
        )
    for user in [*i["users"], *p.get("users", [])]:
        assert isinstance(user, str) and user and not re.search(r"\s|\x00", user)
        if i["driver"] != "redis":
            assert re.fullmatch(
                r"[A-Za-z0-9_]{1," + ("63" if i["driver"] == "pgsql" else "32") + r"}",
                user,
            ) and user not in ("root", "postgres")
    if i["driver"] != "redis":
        assert re.fullmatch(
            r"orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*", i["container"]
        )
    for name, owner in p.get("owners", {}).items():
        assert (
            name in p["databases"]
            and re.fullmatch(
                r"[A-Za-z0-9_]{1," + ("63" if i["driver"] == "pgsql" else "32") + r"}",
                owner,
            )
            and owner not in ("root", "postgres")
        )


if __name__ == "__main__":
    try:
        intent, action, plan, item, scratch = (
            json.loads(sys.argv[1]),
            sys.argv[2],
            json.loads(sys.argv[3]),
            sys.argv[4],
            sys.argv[5],
        )
        material = json.load(sys.stdin)
        validate(intent, material, plan)
        value = ACTIONS[action](intent, material, plan, item, scratch)
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- private client failures must never enter native diagnostics.
        print('{"error_code":"database.destroy_outcome_unknown"}')
        raise SystemExit(1) from None
    print(json.dumps(value))
