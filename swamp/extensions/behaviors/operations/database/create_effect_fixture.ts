import { assertEquals } from "jsr:@std/assert@1.0.19";
import { invokeSql } from "./local_sql.ts";
import { destroyDatabase } from "../../../../tests/_support/database_destroy_command.ts";
import { createDatabase } from "../../../../tests/_support/database_create_command.ts";

const randomPassword = () =>
  Array.from(
    crypto.getRandomValues(new Uint8Array(40)),
    (b) =>
      "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"[b % 62],
  ).join("");

export function registerCreateEffect(driver: "mysql" | "pgsql") {
  Deno.test({
    name: `disposable ${driver} create, replay and foreign ownership refusal`,
    fn: async () => {
      const suffix = crypto.randomUUID().replaceAll("-", "").slice(0, 12);
      const container = `orbit-process-999999-database-create-${suffix}`;
      const password = randomPassword();
      const ownerPassword = randomPassword();
      const pg = driver === "pgsql";
      let started = false;
      const invoke = (sql: string, mutates: boolean) =>
        invokeSql({
          name: "database.server-admin",
          sudo: true,
          mutates,
          deadlineMs: 30_000,
          input: {
            operation: "execute",
            container,
            password_variable: pg ? "PGPASSWORD" : "MYSQL_PWD",
            client: pg
              ? [
                "psql",
                "--no-psqlrc",
                "--host=127.0.0.1",
                "--username=postgres",
                "--dbname=postgres",
                "--no-align",
                "--tuples-only",
                "--quiet",
                "--set=ON_ERROR_STOP=1",
              ]
              : [
                "mysql",
                "--protocol=TCP",
                "--host=127.0.0.1",
                "--user=root",
                "--batch",
                "--skip-column-names",
              ],
            password,
            sql,
          },
        });
      try {
        const child = new Deno.Command("docker", {
          args: [
            "run",
            "--detach",
            "--name",
            container,
            "--env-file",
            "/dev/stdin",
            pg ? "postgres:18-alpine" : "mysql:8.0",
          ],
          stdin: "piped",
          stdout: "null",
          stderr: "null",
        }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(
          new TextEncoder().encode(
            `${pg ? "POSTGRES_PASSWORD" : "MYSQL_ROOT_PASSWORD"}=${password}\n`,
          ),
        );
        await writer.close();
        if (!(await child.status).success) {
          throw new Error("Disposable fixture start failed");
        }
        started = true;
        let ready = false;
        const expires = Date.now() + 40_000;
        while (Date.now() < expires) {
          const result = await invoke("SELECT 1;\n", false);
          if ("output" in result && result.output === "1\n") {
            ready = true;
            break;
          }
          await new Promise((resolve) => setTimeout(resolve, 500));
        }
        if (!ready) {
          throw new Error("Disposable SQL fixture did not become ready");
        }
        const input = {
          repair: false,
          driver,
          username: "proof_owner",
          databases: ["proof_main", "proof_test"],
          test_prefix: pg ? null : "proof_test",
          locale: pg ? "C.UTF-8" : null,
          host: "127.0.0.1",
          port: pg ? 5432 : 3306,
          container,
          index: null,
          patterns: [],
          allow: [],
        };
        const material = (
          effect: (m: Record<string, unknown>) => Promise<void>,
        ) =>
          effect({
            password,
            payload: {
              password: ownerPassword,
              salt: btoa(
                String.fromCharCode(
                  ...crypto.getRandomValues(new Uint8Array(16)),
                ),
              ),
            },
          });
        assertEquals(await createDatabase(input, material), {
          backend: "active",
        });
        await invoke(
          pg
            ? "CREATE TABLE proof_sentinel (id int);\n"
            : "CREATE TABLE proof_main.proof_sentinel (id int);\n",
          true,
        );
        assertEquals(await createDatabase(input, material), {
          backend: "active",
        });
        const wrongMaterial = (
          effect: (m: Record<string, unknown>) => Promise<void>,
        ) =>
          effect({
            password,
            payload: { password: randomPassword(), salt: btoa("a".repeat(16)) },
          });
        assertEquals(await createDatabase(input, wrongMaterial), {
          error_code: "database.name_conflict",
        });
        await invoke(
          pg
            ? "CREATE DATABASE foreign_database;\n"
            : "CREATE DATABASE foreign_database;\n",
          true,
        );
        assertEquals(
          await createDatabase({
            ...input,
            databases: ["foreign_database"],
            test_prefix: null,
          }, material),
          { error_code: "database.name_conflict" },
        );

        const proof = await invoke(
          pg
            ? "SELECT tablename FROM pg_tables WHERE tablename='proof_sentinel';\n"
            : "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='proof_main' AND TABLE_NAME='proof_sentinel';\n",
          false,
        );
        assertEquals(
          "output" in proof && proof.output,
          "proof_sentinel\n",
        );
        const removal = {
          driver,
          container,
          host: "127.0.0.1",
          port: pg ? 5432 : 3306,
          default_open: false,
          databases: input.databases,
          users: [input.username],
          test_prefixes: pg ? [] : ["proof_test"],
          owners: Object.fromEntries(
            input.databases.map((name) => [name, input.username]),
          ),
        };
        if (pg) {
          await invoke(
            "ALTER DATABASE foreign_database OWNER TO proof_owner;",
            true,
          );
          assertEquals(await destroyDatabase(removal, password), {
            error_code: "database.in_use",
          });
          const restored = await invoke(
            "SELECT datallowconn FROM pg_database WHERE datname='proof_main';",
            false,
          );
          assertEquals("output" in restored && restored.output, "t\n");
          await invoke(
            "ALTER DATABASE foreign_database OWNER TO postgres;",
            true,
          );
        } else {
          await invoke(
            "CREATE DATABASE proof_test_3; SET GLOBAL sql_mode='NO_BACKSLASH_ESCAPES';",
            true,
          );
        }
        assertEquals(await destroyDatabase(removal, password), {
          backend: "absent",
        });
        const absent = await invoke(
          pg
            ? "SELECT datname FROM pg_database WHERE datname IN ('proof_main','proof_test');"
            : "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('proof_main','proof_test','proof_test_3');",
          false,
        );
        assertEquals("output" in absent && absent.output, "");
        const foreign = await invoke(
          pg
            ? "SELECT datname FROM pg_database WHERE datname='foreign_database';"
            : "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='foreign_database';",
          false,
        );
        assertEquals(
          "output" in foreign && foreign.output,
          "foreign_database\n",
        );
      } finally {
        if (started) {
          const removal = await new Deno.Command("docker", {
            args: ["rm", "--force", "--volumes", container],
            stdout: "null",
            stderr: "null",
          }).output();
          assertEquals(
            removal.success,
            true,
            "Disposable fixture retirement failed",
          );
        }
      }
    },
  });
}

export function registerValkeyCreateEffect() {
  Deno.test({
    name: "disposable Valkey ACL create, replay and foreign password refusal",
    fn: async () => {
      const { ValkeyClient } = await import("../../../../tests/_support/valkey_client.ts");
      const container = `orbit-process-999999-database-create-${
        crypto.randomUUID().slice(0, 8)
      }`;
      const password = randomPassword();
      const ownedPassword = randomPassword();
      let started = false;
      let client: InstanceType<typeof ValkeyClient> | undefined;
      try {
        const start = await new Deno.Command("docker", {
          args: [
            "run",
            "--detach",
            "--name",
            container,
            "--publish",
            "127.0.0.1::6379",
            "valkey/valkey:8.1",
            "sh",
            "-c",
            "touch /tmp/users.acl; exec valkey-server --aclfile /tmp/users.acl",
          ],
          stdout: "null",
          stderr: "null",
        }).output();
        assertEquals(start.success, true);
        started = true;
        const publication = await new Deno.Command("docker", {
          args: ["port", container, "6379/tcp"],
          stdout: "piped",
          stderr: "null",
        }).output();
        const port = Number(
          new TextDecoder().decode(publication.stdout).trim().split(":").at(-1),
        );
        const expires = Date.now() + 10_000;
        while (Date.now() < expires) {
          try {
            client = await ValkeyClient.connect("127.0.0.1", port);
            await client.command("PING");
            break;
          } catch {
            client?.close();
            client = undefined;
            await new Promise((resolve) => setTimeout(resolve, 100));
          }
        }
        if (!client) throw new Error("Disposable Valkey did not become ready");
        const hash = Array.from(
          new Uint8Array(
            await crypto.subtle.digest(
              "SHA-256",
              new TextEncoder().encode(password),
            ),
          ),
          (b) => b.toString(16).padStart(2, "0"),
        ).join("");
        await client.command(
          "ACL",
          "SETUSER",
          "default",
          "on",
          "resetpass",
          `#${hash}`,
          "~*",
          "&*",
          "+@all",
        );
        await client.command("AUTH", "default", password);
        await client.command("ACL", "SAVE");
        const input = {
          repair: false,
          driver: "redis",
          username: "proof-queue",
          databases: [],
          test_prefix: null,
          locale: null,
          host: "127.0.0.1",
          port,
          container: null,
          index: 15,
          patterns: ["proof:*"],
          allow: ["@connection", "@list"],
        };
        const material = (
          effect: (m: Record<string, unknown>) => Promise<void>,
        ) =>
          effect({
            password,
            payload: { password: ownedPassword, salt: btoa("a".repeat(16)) },
          });
        assertEquals(await createDatabase(input, material), {
          backend: "active",
        });
        assertEquals(await createDatabase(input, material), {
          backend: "active",
        });
        assertEquals(await createDatabase({ ...input, index: 16 }, material), {
          error_code: "database.index_invalid",
        });
        const wrong = (effect: (m: Record<string, unknown>) => Promise<void>) =>
          effect({
            password,
            payload: { password: randomPassword(), salt: btoa("a".repeat(16)) },
          });
        assertEquals(await createDatabase(input, wrong), {
          error_code: "database.name_conflict",
        });
        const app = await ValkeyClient.connect("127.0.0.1", port);
        try {
          await app.command("AUTH", "proof-queue", ownedPassword);
          assertEquals(await app.command("PING"), "PONG");
        } finally {
          app.close();
        }
        await client.command("LPUSH", "proof:queue", "preserved");
        const removal = {
          driver: "redis",
          container: null,
          host: "127.0.0.1",
          port,
          default_open: false,
          databases: [],
          users: ["default", "proof-queue"],
          test_prefixes: [],
          owners: {},
        };
        const blockSave = await new Deno.Command("docker", {
          args: [
            "exec",
            container,
            "sh",
            "-c",
            "mv /tmp/users.acl /tmp/users.acl.saved; mkdir /tmp/users.acl",
          ],
          stdout: "null",
          stderr: "null",
        }).output();
        assertEquals(blockSave.success, true);
        assertEquals(await destroyDatabase(removal, password), {
          error_code: "database.server_command_failed",
        });
        const restored = await client.command("ACL", "GETUSER", "proof-queue");
        assertEquals(Array.isArray(restored), true);
        assertEquals(await client.command("LLEN", "proof:queue"), 1);
        const unblockSave = await new Deno.Command("docker", {
          args: [
            "exec",
            container,
            "sh",
            "-c",
            "rmdir /tmp/users.acl; mv /tmp/users.acl.saved /tmp/users.acl",
          ],
          stdout: "null",
          stderr: "null",
        }).output();
        assertEquals(unblockSave.success, true);
        assertEquals(await destroyDatabase(removal, password), {
          backend: "absent",
        });
        assertEquals(
          await client.command("ACL", "GETUSER", "proof-queue"),
          null,
        );
        assertEquals(await client.command("LLEN", "proof:queue"), 1);
        assertEquals(await client.command("AUTH", "default", password), "OK");
      } finally {
        client?.close();
        if (started) {
          const removed = await new Deno.Command("docker", {
            args: ["rm", "--force", "--volumes", container],
            stdout: "null",
            stderr: "null",
          }).output();
          assertEquals(
            removed.success,
            true,
            "Disposable fixture retirement failed",
          );
        }
      }
    },
  });
}
