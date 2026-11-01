"""One admitted SQL login action. No remote intent reads or workflow control."""

from create_common import identifier, literal, verifier
from server_common import main
from user_common import execute, validate


def statement(i, m):
    """Compile only the captured login and credential on the target Node."""
    validate(i, m)
    pg = i["driver"] == "pgsql"
    name = i["username"]
    if not pg:
        user = literal(name) + "@'%'"
        secret = literal(m["payload"]["password"])
        return (
            f"CREATE USER IF NOT EXISTS {user} IDENTIFIED BY {secret};\n"
            f"ALTER USER {user} IDENTIFIED BY {secret};\n"
        )
    user = identifier(name, True)
    role = literal(name, True)
    credential = literal(verifier(m["payload"]["password"], m["payload"]["salt"]), True)
    ownership = (
        ""
        if i["known_user"]
        else (
            f"IF EXISTS (SELECT FROM pg_authid WHERE rolname={role} AND rolpassword IS DISTINCT FROM {credential}) "
            "THEN RAISE EXCEPTION 'database.name_conflict'; END IF; "
        )
    )
    attribute = "REPLICATION" if i["replication"] else "NOREPLICATION"
    attributes = (
        f"LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE {attribute} PASSWORD {credential}"
    )
    return (
        f"DO $orbit$ BEGIN {ownership}IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname={role}) "
        f"THEN CREATE ROLE {user} {attributes}; ELSE ALTER ROLE {user} {attributes}; END IF; END $orbit$;\n"
    )


def apply(intent, material):
    execute(intent, material, statement(intent, material))
    return {"ready": True}


if __name__ == "__main__":
    main(apply, error_code="database.user_create_outcome_unknown")
