"""One SQL removal action. Identifiers come only from the admitted public plan."""

from create_common import Refused, grant_name, identifier, literal, sql


def values(names):
    return ",".join(literal(n, True) for n in names) or "NULL"


def owner_check(p):
    return "\n".join(
        f"DO $o$ BEGIN IF EXISTS (SELECT FROM pg_database WHERE datname={literal(n, True)} AND datdba IS DISTINCT FROM (SELECT oid FROM pg_roles WHERE rolname={literal(p['owners'][n], True)})) THEN RAISE EXCEPTION 'database.ownership_unproven'; END IF; END $o$;"
        for n in p["databases"]
    )


def inventory(i, m, _p, _item, _scratch):
    clauses = " OR ".join(
        f"BINARY LEFT(SCHEMA_NAME,{len(n)})=BINARY {literal(n)}"
        for n in i["test_prefixes"]
    )
    rows = sql(
        i,
        m,
        "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE "
        + clauses
        + " ORDER BY SCHEMA_NAME;",
    )
    return {"databases": rows.splitlines()}


def ownership(i, m, p, _item, _scratch):
    if i["driver"] == "pgsql":
        sql(i, m, owner_check(p))
    elif p["databases"]:
        privileges = " AND ".join(
            n + "_priv='Y'"
            for n in [
                "Select",
                "Insert",
                "Update",
                "Delete",
                "Create",
                "Drop",
                "References",
                "Index",
                "Alter",
                "Create_tmp_table",
                "Lock_tables",
                "Create_view",
                "Show_view",
                "Create_routine",
                "Alter_routine",
                "Execute",
                "Event",
                "Trigger",
            ]
        )

        def value(text):
            return "CONVERT(0x" + text.encode().hex() + " USING utf8mb4)"

        queries = []
        for name in p["databases"]:
            scopes = [
                grant_name(name),
                *(
                    grant_name(n, True)
                    for n in i["test_prefixes"]
                    if name.startswith(n)
                ),
            ]
            scope = " OR ".join("BINARY Db=BINARY " + value(n) for n in scopes)
            queries.append(
                f"SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY {value(name)} AND NOT EXISTS (SELECT 1 FROM mysql.db WHERE BINARY User=BINARY {value(p['owners'][name])} AND Host='%' AND ({scope}) AND {privileges})"
            )
        if sql(i, m, " UNION ALL ".join(queries) + ";").strip():
            raise Refused("database.ownership_unproven")
    return {"ready": True}


def fence(i, m, p, _item, _scratch):
    sql(
        i,
        m,
        f"""BEGIN;
SELECT pg_advisory_xact_lock(hashtextextended('orbit.database-removal',0));
{owner_check(p)}
CREATE SCHEMA IF NOT EXISTS orbit_admin;
REVOKE ALL ON SCHEMA orbit_admin FROM PUBLIC;
CREATE TABLE IF NOT EXISTS orbit_admin.database_removal_fences (database_name text PRIMARY KEY,database_oid oid NOT NULL,allow_connections boolean NOT NULL);
REVOKE ALL ON orbit_admin.database_removal_fences FROM PUBLIC;
LOCK TABLE orbit_admin.database_removal_fences IN EXCLUSIVE MODE;
INSERT INTO orbit_admin.database_removal_fences SELECT datname,oid,datallowconn FROM pg_database WHERE datname IN ({values(p["databases"])}) ON CONFLICT (database_name) DO NOTHING;
DO $f$ DECLARE item record; BEGIN
 FOR item IN SELECT f.*,d.oid AS current_oid FROM orbit_admin.database_removal_fences f JOIN pg_database d ON d.datname=f.database_name WHERE f.database_name IN ({values(p["databases"])}) ORDER BY f.database_name LOOP
  IF item.database_oid<>item.current_oid THEN RAISE EXCEPTION 'Database identity changed during removal'; END IF;
  EXECUTE format('ALTER DATABASE %I ALLOW_CONNECTIONS false',item.database_name);
 END LOOP;
END $f$;
COMMIT;
""",
    )
    return {"ready": True}


def blockers(i, m, p, _item, _scratch):
    names, users = values(p["databases"]), values(p["users"])
    if i["driver"] == "pgsql":
        query = f"""SELECT DISTINCT blocker FROM (
 SELECT datname::text AS blocker FROM pg_stat_activity WHERE datname IN ({names})
 UNION ALL SELECT database::text FROM pg_replication_slots WHERE database IN ({names})
 UNION ALL SELECT database::text FROM pg_prepared_xacts WHERE database IN ({names})
 UNION ALL SELECT d.datname::text FROM pg_subscription s JOIN pg_database d ON d.oid=s.subdbid WHERE d.datname IN ({names})
 UNION ALL SELECT COALESCE(d.datname::text,owned.datname::text,r.rolname::text)
 FROM pg_shdepend dep JOIN pg_roles r ON dep.refclassid='pg_authid'::regclass AND dep.refobjid=r.oid
 LEFT JOIN pg_database d ON d.oid=dep.dbid
 LEFT JOIN pg_database owned ON dep.dbid=0 AND dep.classid='pg_database'::regclass AND dep.objid=owned.oid
 WHERE r.rolname IN ({users}) AND (dep.dbid<>0 AND d.datname NOT IN ({names}) OR dep.dbid=0 AND (owned.oid IS NULL OR owned.datname NOT IN ({names})))
) blockers ORDER BY blocker;"""
    else:
        definers = values([n + "@%" for n in p["users"]])
        query = f"""SELECT DISTINCT blocker FROM (
 SELECT DB AS blocker FROM information_schema.PROCESSLIST WHERE DB IN ({names}) AND ID<>CONNECTION_ID()
 UNION ALL SELECT locks.OBJECT_SCHEMA FROM performance_schema.metadata_locks locks LEFT JOIN performance_schema.threads threads ON threads.THREAD_ID=locks.OWNER_THREAD_ID WHERE locks.OBJECT_SCHEMA IN ({names}) AND (threads.PROCESSLIST_ID IS NULL OR threads.PROCESSLIST_ID<>CONNECTION_ID())
 UNION ALL SELECT TABLE_SCHEMA FROM information_schema.VIEWS WHERE DEFINER IN ({definers}) AND TABLE_SCHEMA NOT IN ({names})
 UNION ALL SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE DEFINER IN ({definers}) AND ROUTINE_SCHEMA NOT IN ({names})
 UNION ALL SELECT TRIGGER_SCHEMA FROM information_schema.TRIGGERS WHERE DEFINER IN ({definers}) AND TRIGGER_SCHEMA NOT IN ({names})
 UNION ALL SELECT EVENT_SCHEMA FROM information_schema.EVENTS WHERE DEFINER IN ({definers}) AND EVENT_SCHEMA NOT IN ({names})
 UNION ALL SELECT TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA IN ({names}) AND TABLE_SCHEMA<>REFERENCED_TABLE_SCHEMA
) blockers ORDER BY blocker;"""
    if sql(i, m, query).strip():
        raise Refused("database.in_use")
    return {"ready": True}


def drop_database(i, m, p, item, _scratch):
    if item not in p["databases"]:
        raise Refused("database.ownership_unproven")
    pg = i["driver"] == "pgsql"
    check = ""
    if pg:
        check = f"""DO $o$ BEGIN
 IF EXISTS (SELECT FROM pg_database d LEFT JOIN orbit_admin.database_removal_fences f ON f.database_name=d.datname WHERE d.datname={literal(item, True)} AND (f.database_oid IS DISTINCT FROM d.oid OR d.datallowconn OR d.datdba IS DISTINCT FROM (SELECT oid FROM pg_roles WHERE rolname={literal(p["owners"][item], True)}))) THEN RAISE EXCEPTION 'database.ownership_unproven'; END IF;
END $o$;
"""
    sql(i, m, check + f"DROP DATABASE IF EXISTS {identifier(item, pg)};\n")
    return {"ready": True}


def drop_user(i, m, p, item, _scratch):
    if item not in p["users"]:
        raise Refused("database.ownership_unproven")
    sql(
        i,
        m,
        f"DROP ROLE IF EXISTS {identifier(item, True)};\n"
        if i["driver"] == "pgsql"
        else f"DROP USER IF EXISTS {literal(item)}@'%';\n",
    )
    return {"ready": True}


def restore(i, m, p, _item, _scratch):
    sql(
        i,
        m,
        f"""DO $r$ DECLARE item record; BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('orbit.database-removal',0));
 IF to_regclass('orbit_admin.database_removal_fences') IS NULL THEN RETURN; END IF;
 LOCK TABLE orbit_admin.database_removal_fences IN EXCLUSIVE MODE;
 FOR item IN SELECT f.*,d.oid AS current_oid FROM orbit_admin.database_removal_fences f LEFT JOIN pg_database d ON d.datname=f.database_name WHERE f.database_name IN ({values(p["databases"])}) ORDER BY f.database_name LOOP
  IF item.current_oid IS NOT NULL THEN
   IF item.database_oid<>item.current_oid THEN RAISE EXCEPTION 'Database identity changed during removal'; END IF;
   EXECUTE format('ALTER DATABASE %I ALLOW_CONNECTIONS %s',item.database_name,CASE WHEN item.allow_connections THEN 'true' ELSE 'false' END);
  END IF;
 END LOOP;
 DELETE FROM orbit_admin.database_removal_fences WHERE database_name IN ({values(p["databases"])});
END $r$;
""",
    )
    return {"ready": True}
