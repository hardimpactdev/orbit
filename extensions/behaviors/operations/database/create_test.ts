import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1";
import { type NodeCall } from "../_lib/node-agent.ts";
import { createDatabase } from "./create.ts";

const intent = {
  repair: false,
  driver: "pgsql",
  username: "app_owner",
  databases: ["app", "app_test", "app_test_test_1"],
  test_prefix: null,
  locale: "C.UTF-8",
  host: "10.44.0.9",
  port: 5434,
  container: "orbit-process-42-proof",
  agent_sha256: "a".repeat(64),
  index: null,
  patterns: [],
  allow: [],
};
const storedVerifier =
  "SCRAM-SHA-256$4096:YWFhYWFhYWFhYWFhYWFhYQ==$2qQsV9GfwFmj0qVexvYaopopK+gAzzo8xP18Uo3bmZ8=:fDfnVsREThjblq2p3DIeMCGsfhdvYWlbi5hKKVD+1Cc=";
const privateUse = (effect: (m: Record<string, unknown>) => Promise<void>) =>
  effect({
    password: "admin-sentinel",
    payload: { password: "a".repeat(40), salt: "YWFhYWFhYWFhYWFhYWFhYQ==" },
  });
const sql = (call: NodeCall) => String(call.input.sql);

Deno.test("PostgreSQL create runs locally with protected stdin and bounded public result", async () => {
  const calls: NodeCall[] = [];
  const result = await createDatabase(intent, privateUse, undefined, (call) => {
    calls.push(call);
    return Promise.resolve({ output: "" });
  });
  assertEquals(result, { backend: "active" });
  assertEquals(
    calls.every((c) =>
      c.name === "database.server-admin" && c.pin === intent.agent_sha256 &&
      c.sudo === true && c.deadlineMs === 900_000
    ),
    true,
  );
  const role = calls.find((c) => sql(c).startsWith("CREATE ROLE"))!;
  assertStringIncludes(
    sql(role),
    "NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION",
  );
  assertStringIncludes(sql(role), "SCRAM-SHA-256$");
  assertEquals(sql(role).includes("a".repeat(40)), false);
  assertEquals(
    calls.filter((c) => sql(c).startsWith("CREATE DATABASE")).length,
    3,
  );
  assertEquals(JSON.stringify(result).includes("sentinel"), false);
});

Deno.test("PostgreSQL partial-create replay authenticates the owner and skips existing databases", async () => {
  const calls: NodeCall[] = [];
  const result = await createDatabase(intent, privateUse, undefined, (call) => {
    calls.push(call);
    const q = sql(call);
    return Promise.resolve({
      output: q.includes("rolpassword")
        ? storedVerifier
        : q.startsWith("SELECT rolname") || q.includes("current_user")
        ? "app_owner\n"
        : q.startsWith("SELECT datname FROM pg_database WHERE datname=")
        ? "exists\n"
        : "",
    });
  });
  assertEquals(result, { backend: "active" });
  assertEquals(
    calls.some((c) =>
      /^(CREATE ROLE|CREATE DATABASE|ALTER ROLE|DROP)/.test(sql(c))
    ),
    false,
  );
  const auth = calls.find((c) => sql(c).includes("current_user"))!;
  assertEquals(auth.input.password, "a".repeat(40));
});

Deno.test("PostgreSQL refuses foreign database owner before any mutation", async () => {
  const calls: NodeCall[] = [];
  const result = await createDatabase(intent, privateUse, undefined, (call) => {
    calls.push(call);
    return Promise.resolve({
      output: sql(call).includes("pg_get_userbyid") ? "app_test\n" : "",
    });
  });
  assertEquals(result, { error_code: "database.name_conflict" });
  assertEquals(calls.some((c) => c.mutates), false);
});

Deno.test("repair refuses a missing role instead of silently recreating it", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await createDatabase(
      { ...intent, repair: true },
      privateUse,
      undefined,
      (c) => {
        calls.push(c);
        return Promise.resolve({ output: "" });
      },
    ),
    { error_code: "database.name_conflict" },
  );
  assertEquals(calls.some((c) => c.mutates), false);
});

Deno.test("same named foreign role cannot be adopted or have its password replaced", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await createDatabase(intent, privateUse, undefined, (call) => {
      calls.push(call);
      return Promise.resolve(
        sql(call).includes("current_user")
          ? { error_code: "database.server_admin_failed" }
          : { output: "app_owner\n" },
      );
    }),
    { error_code: "database.name_conflict" },
  );
  assertEquals(calls.some((c) => c.mutates), false);
});

Deno.test("MySQL refuses an unowned existing database before granting or creating", async () => {
  const calls: NodeCall[] = [];
  const result = await createDatabase(
    {
      ...intent,
      driver: "mysql",
      locale: null,
      databases: ["app"],
      test_prefix: "app_test",
    },
    privateUse,
    undefined,
    (call) => {
      calls.push(call);
      return Promise.resolve({
        output: sql(call).includes("information_schema.SCHEMATA")
          ? "app\n"
          : "",
      });
    },
  );
  assertEquals(result, { error_code: "database.name_conflict" });
  assertEquals(calls.some((c) => c.mutates), false);
});

Deno.test("MySQL partial create replay preserves data and uses exact escaped grants", async () => {
  const calls: NodeCall[] = [];
  const result = await createDatabase(
    {
      ...intent,
      driver: "mysql",
      locale: null,
      databases: ["app_main", "app_test"],
      test_prefix: "app_test",
    },
    privateUse,
    undefined,
    (call) => {
      calls.push(call);
      const q = sql(call);
      return Promise.resolve({
        output: q.startsWith("SELECT User")
          ? "app_owner\n"
          : q.includes("CURRENT_USER")
          ? "app_owner@%\n"
          : q.includes("information_schema.SCHEMATA")
          ? "app_main\n"
          : q.includes("FROM mysql.db")
          ? "app_main\n"
          : "",
      });
    },
  );
  assertEquals(result, { backend: "active" });
  assertEquals(
    calls.filter((c) => sql(c).startsWith("CREATE DATABASE")).map(sql),
    ["CREATE DATABASE `app_test`;\n"],
  );
  assertEquals(
    calls.some((c) => /^(DROP|ALTER USER|CREATE USER)/.test(sql(c))),
    false,
  );
  assertStringIncludes(
    calls.find((c) => sql(c).startsWith("GRANT"))!.input.sql as string,
    "ON `app\\_main`.*",
  );
});

for (
  const name of [
    "mysql",
    "SYS",
    "performance_schema",
    "information_schema",
    "postgres",
    "template0",
  ]
) {
  Deno.test(`refuses system database ${name} before reading private material`, async () => {
    let read = false;
    await assertRejects(
      () =>
        createDatabase({ ...intent, databases: [name] }, () => {
          read = true;
          return Promise.resolve();
        }),
      Error,
      "database.create_outcome_unknown",
    );
    assertEquals(read, false);
  });
}

Deno.test("invalid pool count, prefix, placement pin and material never reach a Node", async () => {
  for (
    const input of [{ ...intent, databases: Array(35).fill("app") }, {
      ...intent,
      driver: "mysql",
      test_prefix: "m",
    }, { ...intent, agent_sha256: undefined }]
  ) {
    let read = false;
    await assertRejects(
      () =>
        createDatabase(input, () => {
          read = true;
          return Promise.resolve();
        }),
      Error,
      "database.create_outcome_unknown",
    );
    assertEquals(read, false);
  }
});

Deno.test("private transport and SQL diagnostics cannot escape thrown errors", async () => {
  await assertRejects(
    () =>
      createDatabase(intent, privateUse, undefined, () => {
        throw new Error(
          "admin-sentinel SQL aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        );
      }),
    Error,
    "database.create_outcome_unknown",
  );
});

const aclInput = {
  ...intent,
  driver: "redis",
  username: "queue-app",
  databases: [],
  container: null,
  agent_sha256: undefined,
  locale: null,
  patterns: ["app:*"],
  allow: ["@list", "client|setname"],
  index: 15,
};
function aclFixture(prior: unknown = null, failSave = false) {
  const calls: string[][] = [];
  const client = {
    command: (...args: string[]): Promise<unknown> => {
      calls.push(args);
      if (args[0] === "CONFIG") return Promise.resolve(["databases", "16"]);
      if (args[0] === "ACL" && args[1] === "GETUSER") {
        return Promise.resolve(prior);
      }
      if (failSave && args[1] === "SAVE") throw new Error("admin-sentinel");
      return Promise.resolve("OK");
    },
    close() {},
  };
  return { calls, connect: () => Promise.resolve(client as never) };
}
Deno.test("Valkey creates only the admitted user with bounded ACL rules and saves", async () => {
  const f = aclFixture();
  assertEquals(
    await createDatabase(aclInput, privateUse, undefined, undefined, f.connect),
    { backend: "active" },
  );
  const create = f.calls.find((c) => c[1] === "SETUSER")!;
  assertEquals(create.slice(0, 5), [
    "ACL",
    "SETUSER",
    "queue-app",
    "reset",
    "on",
  ]);
  assertEquals(create.slice(-4), [
    "-@admin",
    "-@dangerous",
    "-function",
    "-script",
  ]);
  assertEquals(f.calls.at(-1), ["ACL", "SAVE"]);
});
Deno.test("Valkey refuses an out-of-range index or foreign user before mutation", async () => {
  for (
    const [input, prior, code] of [[
      { ...aclInput, index: 16 },
      null,
      "database.index_invalid",
    ], [
      aclInput,
      ["passwords", ["foreign-hash"]],
      "database.name_conflict",
    ]] as const
  ) {
    const f = aclFixture(prior);
    assertEquals(
      await createDatabase(input, privateUse, undefined, undefined, f.connect),
      { error_code: code },
    );
    assertEquals(
      f.calls.some((c) => c[1] === "SETUSER" || c[1] === "SAVE"),
      false,
    );
  }
});
Deno.test("Valkey failed save retains the owned user and redacts diagnostics", async () => {
  const f = aclFixture(null, true);
  await assertRejects(
    () => createDatabase(aclInput, privateUse, undefined, undefined, f.connect),
    Error,
    "database.create_outcome_unknown",
  );
  assertEquals(f.calls.some((c) => c[1] === "DELUSER"), false);
});
