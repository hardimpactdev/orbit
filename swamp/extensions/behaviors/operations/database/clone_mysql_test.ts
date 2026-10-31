import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { copyMysqlClone, prepareMysqlClone } from "./clone_mysql.ts";
import type { DatabaseSqlCall as NodeCall } from "./local_sql.ts";

import { good, mysql, use } from "./clone_fixture.ts";
Deno.test("MySQL copy proves ownership, resets and copies in one replayable native step", async () => {
  const calls: NodeCall[] = [];
  const invoke = (call: NodeCall) => {
    calls.push(call);
    return good(call);
  };
  assertEquals(await prepareMysqlClone(mysql, use, undefined, invoke), {
    backend: "active",
  });
  assertEquals(
    calls.some((c) => String(c.input.sql).startsWith("DROP DATABASE")),
    false,
  );
  assertEquals(calls.some((c) => c.input.operation === "copy"), false);
  assertEquals(await copyMysqlClone(mysql, use, undefined, invoke), {
    backend: "active",
  });
  const reset = calls.findIndex((c) =>
    String(c.input.sql).startsWith("DROP DATABASE")
  );
  assertEquals(reset > 0, true);
  assertEquals(
    calls.slice(0, reset).some((c) =>
      String(c.input.sql).includes("information_schema.SCHEMATA")
    ),
    true,
  );
  assertEquals(
    calls[reset].input.sql,
    "DROP DATABASE IF EXISTS `target`;\nDROP DATABASE IF EXISTS `target_test`;\n",
  );
  assertEquals(calls.at(-1)!.input, {
    container: mysql.container,
    password: "private-root-clone",
    operation: "copy",
    source: "source_database",
    target: "target",
  });
  assertEquals(
    calls.every((c) =>
      c.sudo && c.deadlineMs === 900000 &&
      c.name === "database.server-admin"
    ),
    true,
  );
});
Deno.test("foreign target refuses before reset or any target mutation", async () => {
  const calls: NodeCall[] = [];
  const result = await prepareMysqlClone(mysql, use, undefined, (call) => {
    calls.push(call);
    return Promise.resolve({
      output: String(call.input.sql).includes("SCHEMA_NAME='source_database'")
        ? "source_database\n"
        : String(call.input.sql).includes("information_schema.SCHEMATA")
        ? "target\n"
        : "",
    });
  });
  assertEquals(result, { error_code: "database.name_conflict" });
  assertEquals(calls.some((c) => c.mutates), false);
});
Deno.test("owner password mismatch refuses before dropping an existing database", async () => {
  const calls: NodeCall[] = [];
  const result = await prepareMysqlClone(mysql, use, undefined, (call) => {
    calls.push(call);
    const sql = String(call.input.sql);
    return Promise.resolve(
      sql.includes("CURRENT_USER")
        ? { error_code: "database.server_admin_failed" }
        : {
          output: sql.includes("SCHEMA_NAME='source_database'")
            ? "source_database\n"
            : sql.includes("SELECT User FROM mysql.user")
            ? "target_owner\n"
            : "",
        },
    );
  });
  assertEquals(result, { error_code: "database.name_conflict" });
  assertEquals(calls.some((c) => c.mutates), false);
});
Deno.test("missing source refuses before target mutation", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await prepareMysqlClone(mysql, use, undefined, (call) => {
      calls.push(call);
      return Promise.resolve({ output: "" });
    }),
    { error_code: "database.source_missing" },
  );
  assertEquals(calls.length, 1);
  assertEquals(calls[0].mutates, false);
});
Deno.test("aliases, system schemas, SQL input and secret properties refuse before material", async () => {
  for (
    const raw of [
      { ...mysql, source_database: "target" },
      { ...mysql, source_database: "target_test_2" },
      { ...mysql, source_database: "source;DROP" },
      { ...mysql, source_database: "mysql" },
      { ...mysql, password: "caller-secret" },
    ]
  ) {
    let read = false;
    await assertRejects(
      () =>
        prepareMysqlClone(raw, () => {
          read = true;
          return Promise.resolve();
        }),
      Error,
      "database.clone_prepare_outcome_unknown",
    );
    assertEquals(read, false);
  }
});
Deno.test("agent exception text is discarded before leaving private callback", async () => {
  await assertRejects(
    () =>
      copyMysqlClone(mysql, use, undefined, (call) => {
        throw new Error(JSON.stringify(call.input));
      }),
    Error,
    "database.clone_copy_outcome_unknown",
  );
});
