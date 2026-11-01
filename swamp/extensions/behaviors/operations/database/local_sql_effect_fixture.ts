import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { invokeSql } from "./local_sql.ts";
import { copyMysqlClone, createDatabase } from "./native_create_fixture.ts";
import { destroyDatabase } from "../../../../tests/_support/database_destroy_command.ts";

const docker = async (...args: string[]) => {
  const result = await new Deno.Command("/usr/bin/sudo", {
    args: ["-n", "--", "/usr/bin/docker", ...args],
    stdout: "piped",
    stderr: "null",
  }).output();
  if (!result.success) {
    throw new Error("Disposable SQL container command failed");
  }
  return new TextDecoder().decode(result.stdout).trim();
};
// Docker owns startup probing; the production lower now performs one observation.
async function waitHealthy(container: string): Promise<void> {
  const process = new Deno.Command("/usr/bin/sudo", {
    args: [
      "-n",
      "--",
      "/usr/bin/python3",
      "-B",
      new URL(
        "../database-server-commands/scripts/wait_ready.py",
        import.meta.url,
      ).pathname,
      JSON.stringify({ container }),
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
    signal: AbortSignal.timeout(120000),
  }).spawn();
  const writer = process.stdin.getWriter();
  await writer.write(new TextEncoder().encode("{}"));
  await writer.close();
  const result = await process.output();
  assertEquals(result.success, true, new TextDecoder().decode(result.stdout));
}
export function registerMysqlLocalSqlEffect() {
  Deno.test({
    name:
      "native Docker MySQL creates escaped underscore grants, copies rows and destroys only admitted databases",
    fn: async () => {
      const container = "orbit-process-2200000000-local-sql-" +
        crypto.randomUUID();
      const password = "sql-private-" + crypto.randomUUID();
      const invoke = (
        input: Record<string, unknown>,
        mutates = true,
        deadlineMs = 30000,
      ) =>
        invokeSql({
          name: "database.server-admin",
          input: { container, password, ...input },
          sudo: true,
          mutates,
          deadlineMs,
        });
      const execute = (sql: string) =>
        invoke({
          operation: "execute",
          password_variable: "MYSQL_PWD",
          client: [
            "mysql",
            "--user=root",
            "--batch",
            "--skip-column-names",
          ],
          sql,
        });
      try {
        await docker(
          "run",
          "--detach",
          "--rm",
          "--network",
          "none",
          "--name",
          container,
          "--env",
          "MYSQL_ALLOW_EMPTY_PASSWORD=yes",
          "--health-cmd",
          "mysqladmin --protocol=TCP --host=127.0.0.1 ping --silent",
          "--health-interval",
          "1s",
          "--health-timeout",
          "3s",
          "--health-retries",
          "60",
          "mysql:8.4",
        );
        await waitHealthy(container);
        assertEquals(
          await invoke(
            {
              operation: "ready",
              password: "",
              password_variable: "MYSQL_PWD",
              client: [
                "mysql",
                "--protocol=TCP",
                "--host=127.0.0.1",
                "--user=root",
              ],
              timeout_seconds: 60,
            },
            false,
            70000,
          ),
          {},
        );
        assertEquals(
          await invoke({
            operation: "execute",
            password: "",
            password_variable: "MYSQL_PWD",
            client: [
              "mysql",
              "--user=root",
              "--batch",
              "--skip-column-names",
            ],
            sql:
              `ALTER USER 'root'@'localhost' IDENTIFIED BY '${password}'; CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY '${password}'; ALTER USER 'root'@'%' IDENTIFIED BY '${password}';`,
          }),
          { output: "" },
        );
        const material = {
          password,
          payload: {
            password: "a".repeat(40),
            salt: "YWFhYWFhYWFhYWFhYWFhYQ==",
          },
        };
        const use = (
          f: (value: Record<string, unknown>) => Promise<void>,
        ) => f(material);
        const base = {
          repair: false,
          driver: "mysql",
          username: "proof_owner",
          databases: ["proof_app"],
          test_prefix: "proof_app_testing",
          locale: null,
          host: "127.0.0.1",
          port: 3306,
          container,
          index: null,
          patterns: [],
          allow: [],
        };
        assertEquals(await createDatabase(base, use), {
          backend: "active",
        });
        assertEquals(
          await execute(
            "CREATE DATABASE proof_app_testing_test_1; CREATE DATABASE unrelated; CREATE TABLE proof_app.items (id INTEGER); INSERT INTO proof_app.items VALUES (7);",
          ),
          { output: "" },
        );
        assertEquals(
          await copyMysqlClone({
            ...base,
            username: "clone_owner",
            databases: ["clone_app"],
            test_prefix: null,
            source_database: "proof_app",
          }, use),
          { backend: "active" },
        );
        assertEquals(await execute("SELECT id FROM clone_app.items;"), {
          output: "7\n",
        });
        const destroy = {
          driver: "mysql",
          container,
          host: "127.0.0.1",
          port: 3306,
          default_open: false,
          databases: ["proof_app"],
          users: ["proof_owner"],
          test_prefixes: ["proof_app_testing"],
          owners: {
            proof_app: "proof_owner",
            proof_app_testing: "proof_owner",
          },
        };
        // A qualified grant with underscores is escaped in mysql.db.Db by CREATE.
        const grants = await execute(
          "SELECT HEX(Db) FROM mysql.db WHERE User='proof_owner' ORDER BY Db;",
        );
        assertStringIncludes(
          "output" in grants ? grants.output : "",
          "70726F6F665C5F617070",
        );
        let failed = false;
        const failAfterFirstDrop = (action: string) => {
          if (!failed && action === "drop_database") {
            failed = true;
            throw { error_code: "database.server_admin_failed" };
          }
        };
        assertEquals(
          await destroyDatabase(
            destroy,
            password,
            failAfterFirstDrop,
          ),
          { error_code: "database.server_admin_failed" },
        );
        assertEquals(failed, true);
        assertEquals(
          await destroyDatabase(destroy, password),
          { backend: "absent" },
        );
        assertEquals(
          await execute(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('proof_app','proof_app_testing_test_1');",
          ),
          { output: "" },
        );
        assertEquals(await execute("SELECT id FROM clone_app.items;"), {
          output: "7\n",
        });
        assertEquals(
          await execute(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='unrelated';",
          ),
          { output: "unrelated\n" },
        );
        const large = await execute(
          "SELECT REPEAT('x',262144);\n/*" + "z".repeat(524288) +
            "*/",
        );
        assertEquals("output" in large && large.output.length, 262145);
      } finally {
        await docker("rm", "--force", "--volumes", container).catch(() => {});
      }
    },
  });
}

export function registerPgsqlLocalSqlEffect() {
  Deno.test({
    name:
      "native Docker PostgreSQL readiness, private SQL and fenced destruction work without the agent",
    fn: async () => {
      const container = "orbit-process-2200000001-local-sql-" +
        crypto.randomUUID();
      const password = "sql-private-" + crypto.randomUUID();
      const client = [
        "psql",
        "--no-psqlrc",
        "--host=127.0.0.1",
        "--username=postgres",
        "--dbname=postgres",
        "--no-align",
        "--tuples-only",
        "--quiet",
        "--set=ON_ERROR_STOP=1",
      ];
      const invoke = (input: Record<string, unknown>, mutates = true) =>
        invokeSql({
          name: "database.server-admin",
          input: {
            container,
            password,
            password_variable: "PGPASSWORD",
            client,
            ...input,
          },
          sudo: true,
          mutates,
          deadlineMs: 60000,
        });
      const execute = (sql: string) => invoke({ operation: "execute", sql });
      try {
        await docker(
          "run",
          "--detach",
          "--rm",
          "--network",
          "none",
          "--name",
          container,
          "--env",
          "POSTGRES_HOST_AUTH_METHOD=trust",
          "--health-cmd",
          "pg_isready --host=127.0.0.1 --username=postgres",
          "--health-interval",
          "1s",
          "--health-timeout",
          "3s",
          "--health-retries",
          "60",
          "postgres:18-alpine",
        );
        await waitHealthy(container);
        assertEquals(
          await invoke(
            { operation: "ready", timeout_seconds: 45 },
            false,
          ),
          {},
        );
        assertEquals(
          await execute(
            `ALTER USER postgres PASSWORD '${password}'; CREATE ROLE proof_owner LOGIN; CREATE DATABASE proof_app OWNER proof_owner; CREATE DATABASE unrelated OWNER proof_owner;`,
          ),
          { output: "" },
        );
        await docker(
          "exec",
          container,
          "sh",
          "-eu",
          "-c",
          'printf "local all all trust\\nhost all all all scram-sha-256\\n" > "$PGDATA/pg_hba.conf"',
        );
        assertEquals(await execute("SELECT pg_reload_conf();"), {
          output: "t\n",
        });
        const destroy = {
          driver: "pgsql",
          container,
          host: "127.0.0.1",
          port: 5432,
          default_open: false,
          databases: ["proof_app"],
          users: ["proof_owner"],
          test_prefixes: [],
          owners: { proof_app: "proof_owner" },
        };
        assertEquals(await destroyDatabase(destroy, password), {
          error_code: "database.in_use",
        });
        assertEquals(
          await execute(
            "SELECT datallowconn FROM pg_database WHERE datname='proof_app';",
          ),
          { output: "t\n" },
        );
        assertEquals(
          await execute(
            "ALTER DATABASE unrelated OWNER TO postgres;",
          ),
          { output: "" },
        );
        assertEquals(await destroyDatabase(destroy, password), {
          backend: "absent",
        });
        assertEquals(
          await execute(
            "SELECT datname FROM pg_database WHERE datname='proof_app';",
          ),
          { output: "" },
        );
        assertEquals(
          await invoke({
            operation: "execute",
            password: "wrong",
            sql: "SELECT 1;",
          }),
          { error_code: "database.server_admin_failed" },
        );
      } finally {
        await docker("rm", "--force", "--volumes", container).catch(() => {});
      }
    },
  });
}
