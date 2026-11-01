"""One exact database grant replacement; native steps own ordering and recovery."""

from create_common import grant_name, identifier, literal
from server_common import main
from user_common import execute, validate


def statement(i, m):
    validate(i, m)
    database = i["database"]
    if i["driver"] == "mysql":
        user = literal(i["username"]) + "@'%'"
        db = identifier(grant_name(database))
        privilege = "SELECT" if i["read_only"] else "ALL PRIVILEGES"
        return (
            f"GRANT SELECT ON {db}.* TO {user};\n"
            f"REVOKE ALL PRIVILEGES ON {db}.* FROM {user};\n"
            f"GRANT {privilege} ON {db}.* TO {user};\n"
        )
    db = identifier(database, True)
    user = identifier(i["username"], True)
    owner = identifier(i["owner_username"], True)
    tables = (
        "SELECT"
        if i["read_only"]
        else "SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER"
    )
    sequences = "SELECT" if i["read_only"] else "USAGE, SELECT, UPDATE"
    return (
        f"BEGIN;\nREVOKE ALL ON DATABASE {db} FROM {user};\nGRANT CONNECT ON DATABASE {db} TO {user};\nCOMMIT;\n"
        f"\\connect {database}\n"
        f"BEGIN;\nREVOKE ALL ON SCHEMA public FROM {user};\nGRANT USAGE ON SCHEMA public TO {user};\n"
        f"REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {user};\n"
        f"REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {user};\n"
        f"ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA public REVOKE ALL ON TABLES FROM {user};\n"
        f"ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA public REVOKE ALL ON SEQUENCES FROM {user};\n"
        f"GRANT {tables} ON ALL TABLES IN SCHEMA public TO {user};\n"
        f"GRANT {sequences} ON ALL SEQUENCES IN SCHEMA public TO {user};\n"
        f"ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA public GRANT {tables} ON TABLES TO {user};\n"
        f"ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA public GRANT {sequences} ON SEQUENCES TO {user};\nCOMMIT;\n"
    )


def apply(intent, material):
    execute(intent, material, statement(intent, material))
    return {"backend": "active"}


if __name__ == "__main__":
    main(apply, error_code="database.user_create_outcome_unknown")
