import { assertEquals } from "jsr:@std/assert@1.0.19";
import { pdoWrite, writeDatabase } from "./write.ts";

for (const driver of ["mysql", "pgsql"] as const) {
  Deno.test({
    name: `fixed PDO applies private ${driver} writes on a disposable server`,
    ignore: Deno.env.get("DATABASE_WRITE_EFFECT_CANARY") !== "1",
    fn: async () => {
      const name = `orbit-database-write-${crypto.randomUUID()}`;
      const dir = await Deno.makeTempDir({ prefix: "database-write-" });
      const password = `private-write-${crypto.randomUUID()}`;
      const docker = async (...args: string[]) => {
        const out = await new Deno.Command("docker", {
          args,
          stdout: "piped",
          stderr: "null",
          signal: AbortSignal.timeout(15000),
        }).output();
        if (!out.success) throw new Error("Disposable SQL fixture failed");
        return new TextDecoder().decode(out.stdout).trim();
      };
      try {
        const pg = driver === "pgsql";
        await Deno.writeTextFile(
          `${dir}/env`,
          `${pg ? "POSTGRES_PASSWORD" : "MYSQL_ROOT_PASSWORD"}=${password}\n${
            pg ? "POSTGRES_DB" : "MYSQL_DATABASE"
          }=proof\n`,
          { mode: 0o600 },
        );
        await docker(
          "run",
          "--detach",
          "--rm",
          "--name",
          name,
          "--label",
          "orbit.test=database-write",
          "--env-file",
          `${dir}/env`,
          "--publish",
          `127.0.0.1::${pg ? 5432 : 3306}`,
          pg ? "postgres:18" : "mysql:8.0",
        );
        const port = Number(
          (await docker("port", name, pg ? "5432/tcp" : "3306/tcp")).split(":")
            .at(-1),
        );
        const material = {
          dsn: `${driver}:host=127.0.0.1;port=${port};dbname=${
            pg ? "postgres" : "mysql"
          }`,
          username: pg ? "postgres" : "root",
          password,
          sql: "CREATE TABLE private_write_sentinel(id integer)",
        };
        let ready = false;
        const until = Date.now() + 35000;
        while (Date.now() < until) {
          if ("row_count" in await pdoWrite({ ...material, sql: "SELECT 1" })) {
            ready = true;
            break;
          }
          await new Promise((resolve) => setTimeout(resolve, 100));
        }
        assertEquals(ready, true);
        assertEquals(await pdoWrite(material), {
          row_count: 0,
          truncated: false,
        });
        assertEquals(
          await pdoWrite({
            ...material,
            sql: "INSERT INTO private_write_sentinel VALUES (17)",
          }),
          { row_count: 1, truncated: false },
        );
        const refused = await pdoWrite(material);
        assertEquals(refused, { error_code: "database.query_failed" });
        assertEquals(JSON.stringify(refused).includes(password), false);
      } finally {
        await docker("rm", "--force", name).catch(() => {});
        await Deno.remove(dir, { recursive: true });
      }
    },
  });
}

Deno.test({
  name:
    "SQLite writes use the installed pinned sudo agent on a disposable owner file",
  ignore: Deno.env.get("DATABASE_WRITE_EFFECT_CANARY") !== "1",
  fn: async () => {
    const dir = await Deno.makeTempDir({ prefix: "database-write-sqlite-" });
    try {
      const path = `${dir}/proof.sqlite`;
      await Deno.writeTextFile(path, "", { mode: 0o600 });
      const binary = await Deno.readFile("/usr/local/bin/orbit-agent");
      const digest = await crypto.subtle.digest("SHA-256", binary);
      const pin = Array.from(
        new Uint8Array(digest),
        (byte) => byte.toString(16).padStart(2, "0"),
      ).join("");
      const ownerReply = await new Deno.Command("id", {
        args: ["-un"],
        stdout: "piped",
      }).output();
      if (!ownerReply.success) throw new Error("Fixture owner unavailable");
      const owner = new TextDecoder().decode(ownerReply.stdout).trim();
      const result = await writeDatabase(
        { driver: "sqlite", agent_sha256: pin, owner, path },
        (use) =>
          use({
            password: "",
            username: null,
            dsn: "",
            sql: "CREATE TABLE private_sqlite_sentinel(id integer)",
          }),
      );
      assertEquals(result, {
        backend: "active",
        row_count: 0,
        truncated: false,
      });
    } finally {
      await Deno.remove(dir, { recursive: true });
    }
  },
});
