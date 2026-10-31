"""One read-only PostgreSQL WAL check before a replication-user SQL step."""

from server_common import Refused, main
from user_common import execute, validate


def check(intent, material):
    validate(intent, material)
    if intent["driver"] != "pgsql" or not intent["replication"]:
        raise Refused("database.server_admin_invalid")
    if execute(intent, material, "SHOW wal_level;\n").strip() != "logical":
        raise Refused("database.wal_level_not_logical")
    return {"ready": True}


if __name__ == "__main__":
    main(check, error_code="database.user_create_outcome_unknown")
