import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { agentLaunch, type NodeCall } from "../_lib/node-agent.ts";
import { createUser, type PrivateMaterialUse } from "./user_create_action.ts";

const intent = {
  driver: "mysql",
  container: "orbit-process-42-database-proof",
  agent_sha256: "a".repeat(64),
  username: "proof",
  replication: false,
  known_user: true,
};
const password = "private-root-password-sentinel";
const sql =
  "CREATE USER proof IDENTIFIED BY 'private-login-password-sentinel';";
const material: PrivateMaterialUse = (use) => use({ password, sql });

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
    assertEquals(call.pin, intent.agent_sha256);
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
    const launch = agentLaunch(call);
    assertEquals(launch.command, "/usr/bin/sudo");
    assertEquals(launch.args, [
      "-n",
      "--",
      "/usr/local/bin/orbit-agent",
      "command",
      "database.server-admin",
    ]);
    assertEquals(JSON.stringify(launch.args).includes(password), false);
    assertEquals(JSON.stringify(launch.args).includes(sql), false);
  }
});

Deno.test("SQL user action refuses private public fields and caller-selected executables before material read", async () => {
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
    let reads = 0;
    await assertRejects(
      () =>
        createUser(invalid, () => {
          reads++;
          throw new Error("must not read");
        }),
      Error,
      "database.user_create_outcome_unknown",
    );
    assertEquals(reads, 0);
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
        createUser(intent, (use) => use(raw), undefined, () => {
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
  const fetchError = await assertRejects(() =>
    createUser(intent, () => {
      throw new Error(sql);
    }), Error);
  assertEquals(fetchError.message, "database.user_create_outcome_unknown");
});

Deno.test("SQL user action projects only closed refusals and rejects unexpected output", async () => {
  assertEquals(
    await createUser(
      intent,
      material,
      undefined,
      () => Promise.resolve({ error_code: "agent.version_mismatch" }),
    ),
    {
      error_code: "agent.version_mismatch",
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

Deno.test("cancelled SQL user action reads no material and starts no effect", async () => {
  const controller = new AbortController();
  controller.abort(sql);
  let reads = 0;
  const error = await assertRejects(() =>
    createUser(intent, () => {
      reads++;
      throw new Error(password);
    }, controller.signal), Error);
  assertEquals(error.message, "database.user_create_outcome_unknown");
  assertEquals(reads, 0);
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
