import { assertThrows } from "jsr:@std/assert@1.0.19";
import { assertReadSql } from "./read_sql.ts";

Deno.test("native network reads allow ordinary queries and refuse external effects before a client starts", () => {
  for (const driver of ["mysql", "pgsql"] as const) {
    for (
      const sql of [
        "SELECT id, upper(name), count(*) FROM users GROUP BY id,name",
        "SELECT 'INTO OUTFILE lo_export()' AS safe_literal",
        "WITH summary AS (SELECT count(*) AS n FROM users) SELECT n FROM summary;",
      ]
    ) assertReadSql(sql, driver);
    for (
      const sql of [
        "SELECT 'proof' INTO OUTFILE '/tmp/proof'",
        "SELECT 'proof' INTO DUMPFILE '/tmp/proof'",
        "SELECT 1 /*! INTO OUTFILE '/tmp/proof' */",
        "SELECT lo_export(48012,'/tmp/proof')",
        "SELECT pg_catalog.lo_export(48012,'/tmp/proof')",
        'SELECT "pg_catalog"."lo_export"(48012,\'/tmp/proof\')',
        "SELECT unreviewed_function()",
        "SELECT public.lower('name')",
        "COMMIT",
        "SELECT 1; DELETE FROM users",
      ]
    ) assertThrows(() => assertReadSql(sql, driver));
  }
  assertReadSql("SELECT pg_catalog.lower('value')", "pgsql");
});
