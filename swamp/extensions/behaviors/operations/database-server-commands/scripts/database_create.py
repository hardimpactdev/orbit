"""database:create on the Database server's Node, one chart phase per call.

Arguments: the public intent as JSON and the phase. `stage` reads the owner secret (JSON with
password and salt) on stdin into a root-only file; `create` reads the server's admin password
on stdin and the staged owner secret; `check` reads the owner secret on stdin; `unstage` removes
the staged file. Prints one public JSON object; exit 1 carries an error_code.
"""

import json
import os
import re
import shutil
import sys

from create_common import Refused, validate, valkey
from create_sql import (
    authenticate,
    create_database,
    create_user,
    grant_mysql,
    postgres_scope,
    probe_databases,
    probe_user,
)
from create_valkey import index, save, set_user, user


def stage_path(i):
    path = i["stage"]
    if not re.fullmatch(r"/run/orbit-database-create-[1-9][0-9]*", path):
        raise Refused("database.server_admin_invalid")
    return path


def stage(i, secret):
    owner = json.loads(secret)
    validate(i, {"password": owner["password"], "payload": owner})
    path = stage_path(i)
    os.makedirs(path, mode=0o700, exist_ok=True)
    descriptor = os.open(path + "/owner", os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "w") as handle:
        handle.write(secret)
    return {"staged": True}


def create(i, secret):
    with open(stage_path(i) + "/owner") as handle:
        m = {"password": secret, "payload": json.load(handle)}
    validate(i, m)
    if i["driver"] == "redis":
        for action in (index, user, set_user, save):
            action(i, m, {})
        return {"created": True}
    probe_user(i, m, {})
    probe_databases(i, m, {})
    create_user(i, m, {})
    for name in i["databases"]:
        create_database(i, m, {"database": name})
    if i["driver"] == "mysql":
        for name in i["databases"]:
            grant_mysql(i, m, {"name": name, "wildcard": False})
        if i["test_prefix"] is not None:
            grant_mysql(i, m, {"name": i["test_prefix"], "wildcard": True})
    else:
        for name in i["databases"]:
            for action in ("revoke_database", "revoke_schema", "grant_schema"):
                postgres_scope(i, m, {"database": name}, action)
    return {"created": True}


def check(i, secret):
    owner = json.loads(secret)
    m = {"password": owner["password"], "payload": owner}
    validate(i, m)
    if i["driver"] == "redis":
        # AUTH runs first; a user without +ping still logged in when PING answers NOPERM.
        reply = valkey(i, m, "PING", user=i["username"], denied_ok=True)
        if reply != "PONG" and not str(reply).startswith("NOPERM"):
            raise Refused("database.owner_login_failed")
    else:
        authenticate(i, m, {})
    return {"login": True}


def unstage(i, _secret):
    shutil.rmtree(stage_path(i), ignore_errors=True)
    return {"removed": True}


def main():
    phase = sys.argv[2]
    try:
        value = json.loads(sys.argv[1])
        secret = (
            sys.stdin.read().rstrip("\n")
            if phase in ("stage", "create", "check")
            else ""
        )
        result = {"stage": stage, "create": create, "check": check, "unstage": unstage}[
            phase
        ](value, secret)
    except Refused as error:
        print(json.dumps({"error_code": error.code}))
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- keep private client exceptions out of native diagnostics.
        print(json.dumps({"error_code": "database.create_outcome_unknown"}))
        raise SystemExit(1) from None
    print(json.dumps(result))


if __name__ == "__main__":
    main()
