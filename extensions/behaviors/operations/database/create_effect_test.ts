import { assertEquals } from "jsr:@std/assert@1.0.19";
import { runNodeAgent } from "../_lib/node-agent.ts";
import { createDatabase } from "./create.ts";
import { cloneMysql } from "./mysql_clone.ts";

const enabled = Deno.env.get("DATABASE_CREATE_EFFECT_CANARY") === "1";
const randomPassword = () =>
  Array.from(
    crypto.getRandomValues(new Uint8Array(40)),
    (b) =>
      "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"[b % 62],
  ).join("");

for (const driver of ["mysql", "pgsql"] as const) {
  Deno.test({
    name: `disposable ${driver} create, replay and foreign ownership refusal`,
    ignore: !enabled,
    fn: async () => {
      const suffix = crypto.randomUUID().replaceAll("-", "").slice(0, 12);
      const container = `orbit-process-999999-database-create-${suffix}`;
      const password = randomPassword();
      const ownerPassword = randomPassword();
      const sha = new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          await Deno.readFile("/usr/local/bin/orbit-agent"),
        ),
      );
      const pin = Array.from(sha, (b) => b.toString(16).padStart(2, "0")).join(
        "",
      );
      const pg = driver === "pgsql";
      let started = false;
      const invoke = (sql: string, mutates: boolean) =>
        runNodeAgent({
          name: "database.server-admin",
          pin,
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
          if (result.output === "1\n") {
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
          agent_sha256: pin,
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
        if (!pg) {
          await invoke(
            "CREATE DATABASE proof_source; CREATE TABLE proof_source.clone_rows (id int); INSERT INTO proof_source.clone_rows VALUES (42); CREATE TABLE proof_main.stale_clone_row (id int);\n",
            true,
          );
          const clone = { ...input, source_database: "proof_source" };
          assertEquals(await cloneMysql(clone, material), {
            backend: "active",
          });
          assertEquals(
            (await invoke("SELECT id FROM proof_main.clone_rows;\n", false))
              .output,
            "42\n",
          );
          assertEquals(
            (await invoke(
              "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='proof_main' AND TABLE_NAME='stale_clone_row';\n",
              false,
            )).output,
            "",
          );
          await invoke(
            "INSERT INTO proof_source.clone_rows VALUES (43); INSERT INTO proof_main.clone_rows VALUES (99);\n",
            true,
          );
          assertEquals(await cloneMysql(clone, material), {
            backend: "active",
          });
          assertEquals(
            (await invoke(
              "SELECT id FROM proof_main.clone_rows ORDER BY id;\n",
              false,
            )).output,
            "42\n43\n",
          );
          assertEquals(
            (await invoke(
              "SELECT id FROM proof_source.clone_rows ORDER BY id;\n",
              false,
            )).output,
            "42\n43\n",
          );
        }
        const proof = await invoke(
          pg
            ? "SELECT tablename FROM pg_tables WHERE tablename='proof_sentinel';\n"
            : "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='proof_main' AND TABLE_NAME='proof_sentinel';\n",
          false,
        );
        assertEquals(proof.output, pg ? "proof_sentinel\n" : "");
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

Deno.test({
  name: "disposable Valkey ACL create, replay and foreign password refusal",
  ignore: !enabled,
  fn: async () => {
    const { ValkeyClient } = await import("./valkey_client.ts");
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
