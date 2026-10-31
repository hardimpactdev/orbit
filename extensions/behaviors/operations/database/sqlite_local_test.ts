import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { userInfo } from "node:os";
import { copySqlite, runSqliteLocal, sqliteLaunch } from "./sqlite_local.ts";
import { sqliteProgram } from "./sqlite_program.ts";

const owner = userInfo().username;
async function database(path: string) {
  const result = await new Deno.Command("/usr/bin/python3", {
    args: [
      "-I",
      "-c",
      "import sqlite3,sys; d=sqlite3.connect(sys.argv[1]); d.execute('CREATE TABLE proof(value TEXT)'); d.execute(\"INSERT INTO proof VALUES ('private-local-sqlite-sentinel')\"); d.commit(); d.close()",
      path,
    ],
    stdout: "null",
    stderr: "null",
  }).output();
  assertEquals(result.success, true);
  await Deno.chmod(path, 0o600);
}
Deno.test("SQLite launch bundles only fixed domain code and keeps private SQL off argv", () => {
  const call = {
    action: "write" as const,
    input: {
      owner,
      path: "/tmp/proof.sqlite",
      sql: "private-local-sqlite-sentinel",
    },
  };
  const launch = sqliteLaunch(call);
  assertEquals(launch.command, "/usr/bin/python3");
  assertEquals(launch.args, ["-I", "-c", sqliteProgram, "write", "30"]);
  assertEquals(
    launch.args.join(" ").includes("private-local-sqlite-sentinel"),
    false,
  );
  const seed = sqliteLaunch({ action: "seedSource", input: {} });
  assertEquals(seed.command, "/usr/bin/sudo");
  assertEquals(seed.args.slice(0, 4), ["-n", "--", "/usr/bin/python3", "-I"]);
});
Deno.test("real domain copy, write and cleanup preserve private data without returning rows", async () => {
  const root = await Deno.makeTempDir({ prefix: "sqlite-port-test-" });
  const source = root + "/source";
  const target = root + "/target";
  await Deno.mkdir(source, { mode: 0o700 });
  await Deno.mkdir(target, { mode: 0o700 });
  const path = source + "/db.sqlite";
  await database(path);
  try {
    const copied = await copySqlite({
      operation: "local",
      checkout: source,
      source: path,
      target_checkout: target,
      target: target + "/db.sqlite",
    });
    assertEquals(typeof copied.bytes, "number");
    assertEquals(typeof copied.sha256, "string");
    const result = await runSqliteLocal({
      action: "write",
      input: {
        owner,
        path: target + "/db.sqlite",
        sql: "UPDATE proof SET value='private-local-sqlite-sentinel'",
      },
    });
    assertEquals(result, { row_count: 1, truncated: false });
    assertEquals(
      JSON.stringify(copied).includes("private-local-sqlite-sentinel"),
      false,
    );
    assertEquals((await Deno.stat(target + "/db.sqlite")).mode! & 0o777, 0o600);
    assertEquals(
      await copySqlite({
        operation: "remove",
        checkout: target,
        path: target + "/db.sqlite",
      }),
      {},
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("local SQL refuses multiple statements, oversized SQL and missing databases without raw diagnostics", async () => {
  const root = await Deno.makeTempDir({ prefix: "sqlite-port-write-" });
  const path = root + "/db.sqlite";
  await database(path);
  try {
    for (
      const sql of [
        "SELECT 1; DROP TABLE proof",
        "x".repeat(16385),
        "SELECT private-local-sqlite-sentinel FROM missing",
      ]
    ) {
      const result = await runSqliteLocal({
        action: "write",
        input: { owner, path, sql },
      });
      assertEquals(result, { error_code: "database.query_failed" });
    }
    assertEquals(
      await runSqliteLocal({
        action: "write",
        input: {
          owner,
          path: root + "/missing.sqlite",
          sql: "CREATE TABLE proof(value)",
        },
      }),
      { error_code: "database.sqlite_unreadable" },
    );
    await assertRejects(
      () => Deno.stat(root + "/missing.sqlite"),
      Deno.errors.NotFound,
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("copy path and symlink checks preserve foreign files", async () => {
  const root = await Deno.makeTempDir({ prefix: "sqlite-port-path-" });
  const path = root + "/db.sqlite";
  await database(path);
  await Deno.symlink(path, root + "/link.sqlite");
  try {
    for (const selected of [root + "/link.sqlite", root + "/../foreign"]) {
      assertEquals(
        await copySqlite({
          operation: "remove",
          checkout: root,
          path: selected,
        }),
        { error_code: "instance.sqlite_copy_refused" },
      );
    }
    assertEquals((await Deno.stat(path)).isFile, true);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
