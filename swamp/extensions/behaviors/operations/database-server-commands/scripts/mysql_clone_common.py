"""MySQL clone domain checks; no workflow control or transport."""

import json
import re
import sys

from create_common import literal, sql, system, validate
from server_common import Refused


def checked(raw, material):
    i = dict(raw["mysql"])
    source = i.pop("source_database")
    validate(i, material)
    if (
        i["driver"] != "mysql"
        or not re.fullmatch(r"[A-Za-z0-9_]{1,64}", source)
        or system(source)
        or source in i["databases"]
        or i["test_prefix"]
        and source.startswith(i["test_prefix"])
    ):
        raise Refused("database.server_admin_invalid")
    return i, source


def source_exists(i, m, source):
    if (
        sql(
            i,
            m,
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME="
            + literal(source)
            + ";",
        ).strip()
        != source
    ):
        raise Refused("database.source_missing")


def main(action):
    try:
        raw = json.loads(sys.argv[1])
        material = json.load(sys.stdin)
        i, source = checked(raw, material)
        result = action(i, material, source)
        print(json.dumps(result))
    except Refused as e:
        print(json.dumps({"error_code": e.code}))
        raise SystemExit(1) from None
    except Exception:  # noqa: BLE001 -- private client failures stay out of public results.
        print('{"error_code":"database.clone_outcome_unknown"}')
        raise SystemExit(1) from None
