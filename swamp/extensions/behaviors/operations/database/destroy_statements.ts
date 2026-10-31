/** SQL is built only from the native action's validated admitted identifiers. */
const literal = (name: string) => `'${name}'`;
const list = (names: string[]) => names.map(literal).join(",") || "NULL";
const identifier = (name: string) => `"${name}"`;
export function mysqlInventory(prefixes: string[]) {
  // LEFT/BINARY keeps underscore literal and preserves exact case without LIKE escaping.
  return `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE ${
    prefixes.map((p) =>
      `BINARY LEFT(SCHEMA_NAME,${p.length})=BINARY ${literal(p)}`
    ).join(" OR ")
  } ORDER BY SCHEMA_NAME;`;
}
export function postgresRemoval(
  databases: string[],
  users: string[],
  owners: Record<string, string>,
) {
  const names = list(databases), roles = list(users);
  const ownership = databases.map((n) =>
    `DO $orbit_owner$ BEGIN IF EXISTS (SELECT FROM pg_database WHERE datname=${
      literal(n)
    } AND datdba IS DISTINCT FROM (SELECT oid FROM pg_roles WHERE rolname=${
      literal(owners[n])
    })) THEN RAISE EXCEPTION 'database.name_conflict'; END IF; END $orbit_owner$;`
  ).join("\n");
  const restore = `DO $orbit$
DECLARE item record;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('orbit.database-removal',0));
 IF to_regclass('orbit_admin.database_removal_fences') IS NULL THEN RETURN; END IF;
 LOCK TABLE orbit_admin.database_removal_fences IN EXCLUSIVE MODE;
 FOR item IN SELECT f.*,d.oid AS current_oid FROM orbit_admin.database_removal_fences f LEFT JOIN pg_database d ON d.datname=f.database_name WHERE f.database_name IN (${names}) ORDER BY f.database_name LOOP
  IF item.current_oid IS NOT NULL THEN
   IF item.database_oid<>item.current_oid THEN RAISE EXCEPTION 'Database identity changed during removal'; END IF;
   EXECUTE format('ALTER DATABASE %I ALLOW_CONNECTIONS %s',item.database_name,CASE WHEN item.allow_connections THEN 'true' ELSE 'false' END);
  END IF;
 END LOOP;
 DELETE FROM orbit_admin.database_removal_fences WHERE database_name IN (${names});
END $orbit$;\n`;
  const blockers = `SELECT DISTINCT blocker FROM (
 SELECT datname::text AS blocker FROM pg_stat_activity WHERE datname IN (${names})
 UNION ALL SELECT database::text FROM pg_replication_slots WHERE database IN (${names})
 UNION ALL SELECT database::text FROM pg_prepared_xacts WHERE database IN (${names})
 UNION ALL SELECT d.datname::text FROM pg_subscription s JOIN pg_database d ON d.oid=s.subdbid WHERE d.datname IN (${names})
 UNION ALL SELECT COALESCE(d.datname::text,owned.datname::text,r.rolname::text)
 FROM pg_shdepend dep JOIN pg_roles r ON dep.refclassid='pg_authid'::regclass AND dep.refobjid=r.oid
 LEFT JOIN pg_database d ON d.oid=dep.dbid
 LEFT JOIN pg_database owned ON dep.dbid=0 AND dep.classid='pg_database'::regclass AND dep.objid=owned.oid
 WHERE r.rolname IN (${roles}) AND (dep.dbid<>0 AND d.datname NOT IN (${names}) OR dep.dbid=0 AND (owned.oid IS NULL OR owned.datname NOT IN (${names})))
) blockers ORDER BY blocker`;
  const drops =
    databases.map((n) => `DROP DATABASE IF EXISTS ${identifier(n)};\n`).join(
      "",
    ) + users.map((n) => `DROP ROLE IF EXISTS ${identifier(n)};\n`).join("");
  const drop =
    `DO $lock$ BEGIN PERFORM pg_advisory_lock(hashtextextended('orbit.database-removal',0)); END $lock$;
BEGIN;
${ownership}
CREATE SCHEMA IF NOT EXISTS orbit_admin;
REVOKE ALL ON SCHEMA orbit_admin FROM PUBLIC;
CREATE TABLE IF NOT EXISTS orbit_admin.database_removal_fences (database_name text PRIMARY KEY,database_oid oid NOT NULL,allow_connections boolean NOT NULL);
REVOKE ALL ON orbit_admin.database_removal_fences FROM PUBLIC;
LOCK TABLE orbit_admin.database_removal_fences IN EXCLUSIVE MODE;
INSERT INTO orbit_admin.database_removal_fences SELECT datname,oid,datallowconn FROM pg_database WHERE datname IN (${names}) ON CONFLICT (database_name) DO NOTHING;
DO $orbit$
DECLARE item record;
BEGIN
 FOR item IN SELECT f.*,d.oid AS current_oid FROM orbit_admin.database_removal_fences f JOIN pg_database d ON d.datname=f.database_name WHERE f.database_name IN (${names}) ORDER BY f.database_name LOOP
  IF item.database_oid<>item.current_oid THEN RAISE EXCEPTION 'Database identity changed during removal'; END IF;
  EXECUTE format('ALTER DATABASE %I ALLOW_CONNECTIONS false',item.database_name);
 END LOOP;
END $orbit$;
COMMIT;
WITH blockers AS (${blockers}) SELECT EXISTS (SELECT FROM blockers) AS blocked
\\gset orbit_
\\if :orbit_blocked
${blockers};
\\else
${drops}\\endif
${restore}`;
  return { drop, restore };
}

/** MySQL has no owner field: require the recorded user's full exact/prefix grant. */
export function mysqlOwnership(
  names: string[],
  owners: string[],
  prefixes: string[],
) {
  const value = (s: string) =>
    "CONVERT(0x" + Array.from(new TextEncoder().encode(s), (b) =>
      b.toString(16).padStart(2, "0")).join("") +
    " USING utf8mb4)";
  const privileges = [
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
  ].map((p) => `${p}_priv='Y'`).join(" AND ");
  return names.map((name, i) => {
    const scopes = [
      name.replaceAll("_", "\\_"),
      ...prefixes.filter((prefix) => name.startsWith(prefix)).map((p) =>
        p.replaceAll("_", "\\_") + "%"
      ),
    ];
    return `SELECT SCHEMA_NAME AS unowned FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY ${
      value(name)
    } AND NOT EXISTS (SELECT 1 FROM mysql.db WHERE BINARY User=BINARY ${
      value(owners[i])
    } AND Host='%' AND (${
      scopes.map((scope) => `BINARY Db=BINARY ${value(scope)}`).join(" OR ")
    }) AND ${privileges})`;
  }).join(" UNION ALL ") + ";";
}
