import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type DatabaseSqlCall as NodeCall,
  SqlInput,
  sqlProcess,
} from "./local_sql.ts";
import { createUser } from "./user_create_action.ts";

const intent = {
  driver: "mysql",
  container: "orbit-process-42-database-proof",
  username: "proof",
  replication: false,
  known_user: true,
};
const password = "private-root-password-sentinel";
const sql =
  "CREATE USER proof IDENTIFIED BY 'private-login-password-sentinel';";
const material = { password, sql };

Deno.test("SQL user action keeps private material in stdin and drops native SQL output", async () => {
  for (const driver of ["mysql", "pgsql"]) {
    const calls: NodeCall[] = [];
    const result = await createUser(
      { ...intent, driver },
      material,
      undefined,
      (call) => {
        calls.push(call);
        return Promise.resolve({ output: `${password}\n${sql}` });
      },
    );
    assertEquals(result, { backend: "active" });
    assertEquals(calls.length, 1);
    const call = calls[0];
    assertEquals(call.name, "database.server-admin");
    assertEquals(call.sudo, true);
    assertEquals(call.deadlineMs, 900_000);
    assertEquals(call.input.password, password);
    assertEquals(call.input.sql, sql);
    assertEquals(
      call.input.password_variable,
      driver === "pgsql" ? "PGPASSWORD" : "MYSQL_PWD",
    );
    assertEquals(
      (call.input.client as string[])[0],
      driver === "pgsql" ? "psql" : "mysql",
    );
    const launch = sqlProcess(SqlInput.parse(call.input), call.deadlineMs);
    assertEquals(launch.command, "/usr/bin/sudo");
    assertEquals(launch.args[2], "/usr/bin/docker");
    assertEquals(JSON.stringify(launch.args).includes(password), false);
    assertEquals(JSON.stringify(launch.args).includes(sql), false);
  }
});

Deno.test("SQL user action refuses private public fields and caller-selected executables before SQL", async () => {
  for (
    const invalid of [
      { ...intent, password },
      { ...intent, sql },
      { ...intent, client: ["sh"] },
      { ...intent, command: "process.remove" },
      { ...intent, container: "--privileged" },
      { ...intent, driver: "sqlite" },
      { ...intent, agent_sha256: "not-a-digest" },
    ]
  ) {
    let effects = 0;
    await assertRejects(
      () =>
        createUser(invalid, material, undefined, () => {
          effects++;
          throw new Error("must not execute");
        }),
      Error,
      "database.user_create_outcome_unknown",
    );
    assertEquals(effects, 0);
  }
});

Deno.test("SQL user action redacts parser, transport and execution failures without retry", async () => {
  for (
    const raw of [
      { password: { [password]: sql }, sql },
      { password, sql, unexpected: sql },
      { password, sql: "\0" + sql },
    ]
  ) {
    let calls = 0;
    const error = await assertRejects(
      () =>
        createUser(intent, raw, undefined, () => {
          calls++;
          return Promise.resolve({ output: "" });
        }),
      Error,
    );
    assertEquals(error.message, "database.user_create_outcome_unknown");
    assertEquals(calls, 0);
  }
  let attempts = 0;
  const error = await assertRejects(
    () =>
      createUser(intent, material, undefined, () => {
        attempts++;
        throw new Error(`${password}: ${sql}`);
      }),
    Error,
  );
  assertEquals(error.message, "database.user_create_outcome_unknown");
  assertEquals(attempts, 1);
});

Deno.test("SQL user action projects only closed refusals and rejects unexpected output", async () => {
  assertEquals(
    await createUser(
      intent,
      material,
      undefined,
      () => Promise.resolve({ error_code: "database.server_admin_failed" }),
    ),
    {
      error_code: "database.server_admin_failed",
    },
  );
  for (
    const reply of [
      { error_code: password },
      { output: "", rows: [password] },
      {},
      { output: password, password },
    ]
  ) {
    const error = await assertRejects(
      () =>
        createUser(intent, material, undefined, () => Promise.resolve(reply)),
      Error,
    );
    assertEquals(error.message, "database.user_create_outcome_unknown");
  }
});

Deno.test("cancelled SQL user action starts no effect with resolved material", async () => {
  const controller = new AbortController();
  controller.abort(sql);
  let effects = 0;
  const error = await assertRejects(
    () =>
      createUser(intent, material, controller.signal, () => {
        effects++;
        throw new Error(password);
      }),
    Error,
  );
  assertEquals(error.message, "database.user_create_outcome_unknown");
  assertEquals(effects, 0);
});

Deno.test("PostgreSQL checks WAL before executing its admitted atomic role SQL without a separate name lookup", async () => {
  for (
    const [wal, expected] of [
      ["replica\n", "database.wal_level_not_logical"],
      ["logical\n", "active"],
    ]
  ) {
    const calls: NodeCall[] = [];
    const result = await createUser(
      { ...intent, driver: "pgsql", replication: true, known_user: false },
      material,
      undefined,
      (call) => {
        calls.push(call);
        return Promise.resolve({
          output: call.input.sql === "SHOW wal_level;\n" ? wal : "",
        });
      },
    );
    assertEquals(
      result,
      expected === "active" ? { backend: "active" } : { error_code: expected },
    );
    assertEquals(
      calls.filter((call) => call.mutates).length,
      expected === "active" ? 1 : 0,
    );
    assertEquals(calls[0].input.sql, "SHOW wal_level;\n");
    if (wal.trim() === "logical") {
      assertEquals(
        calls[1].input.sql,
        sql,
      );
      assertEquals(calls.length, 2);
    }
  }
});
