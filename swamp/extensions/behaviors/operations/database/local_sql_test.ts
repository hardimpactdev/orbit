import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import {
  invokeSql,
  SqlInput,
  type SqlProcess,
  sqlProcess,
} from "./local_sql.ts";
const input = {
  operation: "execute",
  container: "orbit-process-42-proof",
  password: "private-password\n",
  password_variable: "MYSQL_PWD",
  client: ["mysql", "--user=root", "--batch", "--skip-column-names"],
  sql: "private-sql",
};
const call = {
  name: "database.server-admin",
  input,
  sudo: true,
  mutates: true,
  deadlineMs: 30000,
};
Deno.test("SQL and exact credential bytes stay in stdin, with fixed sudo Docker argv", () => {
  const process = sqlProcess(SqlInput.parse(input), 30000);
  assertEquals(process.command, "/usr/bin/sudo");
  assertEquals(process.args.slice(0, 8), [
    "-n",
    "--",
    "/usr/bin/docker",
    "container",
    "exec",
    "-i",
    "--",
    input.container,
  ]);
  assertEquals(JSON.stringify(process.args).includes("private-"), false);
  assertEquals(process.args.includes("--env"), false);
  const [credential, sql] = new TextDecoder().decode(process.stdin).split(
    "\n",
  );
  assertEquals(atob(credential), input.password);
  assertEquals(sql, input.sql);
  assertStringIncludes(process.args.join(" "), "timeout -s TERM -k 5 30s");
});
Deno.test("PostgreSQL password frame uses PGPASSWORD and fixed psql options", async () => {
  let process: SqlProcess | undefined;
  assertEquals(
    await invokeSql(
      {
        ...call,
        input: {
          ...input,
          password_variable: "PGPASSWORD",
          client: [
            "psql",
            "--no-psqlrc",
            "--username=postgres",
            "--dbname=postgres",
            "--quiet",
            "--set=ON_ERROR_STOP=1",
          ],
        },
      },
      undefined,
      (p) => {
        process = p;
        return Promise.resolve({ success: true, output: "t\n" });
      },
    ),
    { output: "t\n" },
  );
  assertStringIncludes(process!.args.join(" "), "export PGPASSWORD");
});
Deno.test("invalid client, names, password variable and call type refuse before spawning", async () => {
  for (
    const changed of [
      { client: ["bash", "-c", "secret"] },
      { container: "--privileged" },
      { password_variable: "PATH" },
      { client: ["mysql", "--execute=SELECT 1"] },
    ]
  ) {
    let count = 0;
    assertEquals(
      await invokeSql(
        { ...call, input: { ...input, ...changed } },
        undefined,
        () => {
          count++;
          return Promise.resolve({ success: true, output: "" });
        },
      ),
      { error_code: "database.server_admin_invalid" },
    );
    assertEquals(count, 0);
  }
});
Deno.test("copy uses fixed dump/load pipeline and admits only identifiers", async () => {
  let process: SqlProcess | undefined;
  const copy = {
    ...call,
    input: {
      operation: "copy",
      container: input.container,
      password: input.password,
      source: "source_app",
      target: "target_app",
    },
  };
  assertEquals(
    await invokeSql(copy, undefined, (p) => {
      process = p;
      return Promise.resolve({ success: true, output: "" });
    }),
    {},
  );
  assertStringIncludes(
    process!.args.join(" "),
    "--single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF",
  );
  assertEquals(process!.args.slice(-2), ["source_app", "target_app"]);
  assertEquals(
    await invokeSql({
      ...copy,
      input: { ...copy.input, source: "source;DROP" },
    }),
    { error_code: "database.server_admin_invalid" },
  );
});
Deno.test("readiness makes one observation and leaves retry to native resume", async () => {
  let attempts = 0;
  const ready = {
    ...call,
    input: {
      operation: "ready",
      container: input.container,
      password: input.password,
      password_variable: "MYSQL_PWD",
      client: ["mysql"],
      timeout_seconds: 5,
    },
  };
  assertEquals(
    await invokeSql(ready, undefined, (process) => {
      attempts++;
      assertEquals(
        new TextDecoder().decode(process.stdin).endsWith("SELECT 1;\n"),
        true,
      );
      return Promise.resolve({ success: false, output: "" });
    }),
    { error_code: "database.server_not_ready" },
  );
  assertEquals(attempts, 1);
  assertEquals(
    await invokeSql(
      ready,
      undefined,
      () => Promise.resolve({ success: true, output: "1\n" }),
    ),
    {},
  );
});
Deno.test("readiness deadline, process errors and output overflow expose only fixed codes", async () => {
  const ready = {
    ...call,
    input: {
      operation: "ready",
      container: input.container,
      password: input.password,
      password_variable: "MYSQL_PWD",
      client: ["mysql"],
      timeout_seconds: 1,
    },
  };
  assertEquals(
    await invokeSql(
      ready,
      undefined,
      () => Promise.resolve({ success: false, output: "" }),
    ),
    { error_code: "database.server_not_ready" },
  );
  assertEquals(
    await invokeSql(call, undefined, () => {
      throw new Error(input.password + input.sql);
    }),
    { error_code: "database.server_admin_failed" },
  );
  assertEquals(
    await invokeSql(
      { ...call, maxOutputBytes: 4 },
      undefined,
      () => Promise.resolve({ success: true, output: "12345" }),
    ),
    { error_code: "database.server_admin_invalid" },
  );
});
Deno.test("started mutations retain their own deadline while reads accept cancellation", async () => {
  const signal = new AbortController().signal;
  for (const mutates of [false, true]) {
    let observed: AbortSignal | undefined;
    await invokeSql({ ...call, mutates }, signal, (p) => {
      observed = p.signal;
      return Promise.resolve({ success: true, output: "" });
    });
    assertEquals(observed === signal, !mutates);
  }
});
