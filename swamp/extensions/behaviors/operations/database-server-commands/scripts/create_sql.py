"""Single SQL creation actions. Native workflow owns order, fan-out and resume."""

import re

from create_common import (
    Refused,
    grant_name,
    identifier,
    literal,
    sql,
    system,
    verifier,
)


def probe_user(i, m, _item):
    pg = i["driver"] == "pgsql"
    name = literal(i["username"], pg)
    query = (
        f"SELECT rolpassword FROM pg_authid WHERE rolname={name} AND NOT (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication);\n"
        if pg
        else f"SELECT User FROM mysql.user WHERE User={name} AND Host='%' AND plugin IN ('caching_sha2_password','mysql_native_password') AND authentication_string <> '';\n"
    )
    # Return the existence marker separately so a privileged/unusable existing role cannot look absent.
    prefix = (
        f"SELECT rolname FROM pg_roles WHERE rolname={name};\n"
        if pg
        else f"SELECT User FROM mysql.user WHERE User={name};\n"
    )
    values = sql(i, m, prefix + query).strip().splitlines()
    if not values:
        if i["repair"]:
            raise Refused("database.name_conflict")
        return {"ready": True}
    if len(values) < 2:
        raise Refused("database.name_conflict")
    if pg:
        match = re.fullmatch(
            r"SCRAM-SHA-256\$([0-9]{1,6}):([A-Za-z0-9+/=]{1,128})\$[A-Za-z0-9+/=]{44}:[A-Za-z0-9+/=]{44}",
            values[-1],
        )
        if (
            not match
            or not 4096 <= int(match[1]) <= 65536
            or values[-1] != verifier(m["payload"]["password"], match[2], int(match[1]))
        ):
            raise Refused("database.name_conflict")
    elif values[-1] != i["username"]:
        raise Refused("database.name_conflict")
    return {"ready": True}


def probe_databases(i, m, _item):
    pg = i["driver"] == "pgsql"
    names = ",".join(literal(n, pg) for n in i["databases"])
    user = literal(i["username"], pg)
    if pg:
        query = f"SELECT datname FROM pg_database WHERE datname IN ({names}) AND pg_get_userbyid(datdba) <> {user};\n"
        if sql(i, m, query).strip():
            raise Refused("database.name_conflict")
    else:
        prefix = i["test_prefix"]
        selection = f"s.SCHEMA_NAME IN ({names})" + (
            f" OR s.SCHEMA_NAME LIKE {literal(grant_name(prefix, True))}"
            if prefix is not None
            else ""
        )
        grant = "d.Db=REPLACE(s.SCHEMA_NAME,'_'," + literal(r"\_") + ")"
        if prefix is not None:
            grant += f" OR (s.SCHEMA_NAME LIKE {literal(grant_name(prefix, True))} AND d.Db={literal(grant_name(prefix, True))})"
        query = f"SELECT s.SCHEMA_NAME,EXISTS(SELECT 1 FROM mysql.db d JOIN mysql.user u ON u.User=d.User AND u.Host=d.Host WHERE d.User={user} AND d.Host='%' AND ({grant}) AND d.Create_priv='Y') FROM information_schema.SCHEMATA s WHERE {selection};\n"
        for line in sql(i, m, query).strip().splitlines():
            name, owned = line.split("\t")
            if system(name) or owned != "1":
                raise Refused("database.name_conflict")
    return {"ready": True}


def create_user(i, m, _item):
    pg = i["driver"] == "pgsql"
    user = identifier(i["username"], pg)
    if pg:
        # An existing user is never reset. Authentication and policy probes gate grants.
        statement = f"DO $orbit$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname={literal(i['username'], True)}) THEN CREATE ROLE {user} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD {literal(verifier(m['payload']['password'], m['payload']['salt']), True)}; END IF; END $orbit$;\n"
    else:
        statement = f"CREATE USER IF NOT EXISTS {literal(i['username'])}@'%' IDENTIFIED BY {literal(m['payload']['password'])};\n"
    sql(i, m, statement)
    return {"ready": True}


def authenticate(i, m, _item):
    pg = i["driver"] == "pgsql"
    try:
        value = sql(
            i,
            m,
            "SELECT current_user;\n" if pg else "SELECT CURRENT_USER();\n",
            owner=True,
        ).strip()
        if value != (i["username"] if pg else i["username"] + "@%"):
            raise ValueError()
    except (Refused, ValueError):
        raise Refused("database.name_conflict") from None
    return {"ready": True}


def grant_mysql(i, m, item):
    name = item["name"]
    wildcard = item["wildcard"]
    if type(wildcard) is not bool or (
        name != i["test_prefix"] if wildcard else name not in i["databases"]
    ):
        raise Refused("database.server_admin_invalid")
    statement = f"GRANT ALL PRIVILEGES ON {identifier(grant_name(name, wildcard))}.* TO {literal(i['username'])}@'%';\n"
    sql(i, m, statement)
    return {"ready": True}


def create_database(i, m, item):
    name = item["database"]
    if name not in i["databases"]:
        raise Refused("database.server_admin_invalid")
    pg = i["driver"] == "pgsql"
    # One client invocation. psql \gexec keeps CREATE DATABASE outside a transaction.
    if pg:
        locale = (
            ""
            if i["locale"] is None
            else " TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE "
            + literal(i["locale"], True)
        )
        create = f"CREATE DATABASE {identifier(name, True)} OWNER {identifier(i['username'], True)}{locale}"
        statement = f"SELECT {literal(create, True)} WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname={literal(name, True)})\\gexec\n"
    else:
        statement = f"CREATE DATABASE IF NOT EXISTS {identifier(name)};\n"
    sql(i, m, statement)
    return {"ready": True}


def postgres_scope(i, m, item, action):
    name = item["database"]
    if name not in i["databases"]:
        raise Refused("database.server_admin_invalid")
    # Keep the admitted-owner assertion in the same mutating client session.
    proof = f"DO $orbit$ BEGIN IF EXISTS (SELECT FROM pg_database WHERE datname={literal(name, True)} AND datdba IS DISTINCT FROM (SELECT oid FROM pg_roles WHERE rolname={literal(i['username'], True)})) THEN RAISE EXCEPTION 'database.name_conflict'; END IF; END $orbit$;\n"
    commands = {
        "revoke_database": f"REVOKE ALL ON DATABASE {identifier(name, True)} FROM PUBLIC;\n",
        "revoke_schema": f"\\connect {name}\nREVOKE CREATE ON SCHEMA public FROM PUBLIC;\n",
        "grant_schema": f"\\connect {name}\nGRANT ALL ON SCHEMA public TO {identifier(i['username'], True)};\n",
    }
    sql(i, m, proof + commands[action])
    return {"ready": True}
