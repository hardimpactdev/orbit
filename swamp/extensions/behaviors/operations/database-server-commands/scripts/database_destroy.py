"""database:destroy on the Database server's Node, one chart phase per call.

Arguments: the public intent as JSON and the phase. Both phases read the server's admin password
on stdin. `drop` refuses blockers before the first drop, then drops the databases and unshared
users; `gone` proves none are left. Prints one public JSON object; exit 1 carries an error_code.
"""

import json
import sys

from create_common import Refused, identifier, literal, sql, valkey
from destroy import validate
from destroy_sql import blockers, inventory, values


def checked(i, m, p):
    try:
        validate(i, m, p)
    except (AssertionError, KeyError, TypeError, ValueError):
        raise Refused("database.server_admin_invalid") from None
    return p


def plan(i, m):
    """The admitted names, plus MySQL test databases found under the admitted test prefixes."""
    checked(i, m, {})
    found = []
    if i["driver"] == "mysql" and i["test_prefixes"]:
        found = inventory(i, m, {}, "", "")["databases"]
    names = list(dict.fromkeys([*i["databases"], *found]))
    return checked(
        i, m, {"databases": names, "users": [u for u in i["users"] if u != "default"]}
    )


def drop(i, m):
    p = plan(i, m)
    if i["driver"] == "redis":
        for user in p["users"]:
            if valkey(i, m, "ACL", "DELUSER", user) not in (0, 1):
                raise Refused("database.server_command_failed")
        if valkey(i, m, "ACL", "SAVE") != "OK":
            raise Refused("database.server_command_failed")
        return {"dropped": True}
    blockers(i, m, p, "", "")
    pg = i["driver"] == "pgsql"
    for name in p["databases"]:
        sql(i, m, f"DROP DATABASE IF EXISTS {identifier(name, pg)};\n")
    for user in p["users"]:
        sql(
            i,
            m,
            f"DROP ROLE IF EXISTS {identifier(user, True)};\n"
            if pg
            else f"DROP USER IF EXISTS {literal(user)}@'%';\n",
        )
    return {"dropped": True}


def gone(i, m):
    p = plan(i, m)
    if i["driver"] == "redis":
        if any(valkey(i, m, "ACL", "GETUSER", user) is not None for user in p["users"]):
            raise Refused("database.destroy_incomplete")
        return {"absent": True}
    pg = i["driver"] == "pgsql"
    names, users = values(p["databases"]), values(p["users"])
    left = sql(
        i,
        m,
        f"SELECT datname FROM pg_database WHERE datname IN ({names}) UNION ALL SELECT rolname FROM pg_roles WHERE rolname IN ({users});\n"
        if pg
        else f"SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ({names}) UNION ALL SELECT User FROM mysql.user WHERE User IN ({users}) AND Host='%';\n",
    )
    if left.strip():
        raise Refused("database.destroy_incomplete")
    return {"absent": True}


def main():
    phase = sys.argv[2]
    try:
        value = json.loads(sys.argv[1])
        m = {"password": sys.stdin.read().rstrip("\n")}
        result = {"drop": drop, "gone": gone}[phase](value, m)
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- keep private client exceptions out of native diagnostics.
        print(json.dumps({"error_code": "database.destroy_outcome_unknown"}))
        raise SystemExit(1) from None
    print(json.dumps(result))


if __name__ == "__main__":
    main()
