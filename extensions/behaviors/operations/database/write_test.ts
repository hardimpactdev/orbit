import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { writeDatabase } from "./write.ts";

const input = {
  driver: "sqlite",
  agent_sha256: "a".repeat(64),
  owner: "orbit",
  path: "/tmp/proof.sqlite",
};
const material = {
  sql: "CREATE TABLE private_sentinel(id integer)",
  password: "",
  dsn: "",
  username: null,
};
const use = (callback: (m: Record<string, unknown>) => Promise<void>) =>
  callback(material);
Deno.test("SQLite write uses only fixed pinned owner input and discards returned rows", async () => {
  const result = await writeDatabase(input, use, undefined, (call) => {
    assertEquals(call.name, "database.write-sqlite");
    assertEquals(call.sudo, true);
    assertEquals(call.deadlineMs, 30000);
    assertEquals(call.input, {
      owner: "orbit",
      path: "/tmp/proof.sqlite",
      sql: material.sql,
    });
    return Promise.resolve({
      row_count: 1,
      truncated: false,
      rows: [{ private: material.sql }],
      columns: [material.sql],
    });
  });
  assertEquals(result, { backend: "active", row_count: 1, truncated: false });
});
Deno.test("PDO write gets only callback-local material and refuses a foreign DSN", async () => {
  let writes = 0;
  const privateUse = (f: (m: Record<string, unknown>) => Promise<void>) =>
    f({
      ...material,
      dsn: "pgsql:host=127.0.0.1;dbname=proof",
      username: "proof",
      password: "private-password",
    });
  const pdo = () => {
    writes++;
    return Promise.resolve({ row_count: 2, truncated: false });
  };
  assertEquals(
    await writeDatabase(
      { driver: "pgsql" },
      privateUse,
      undefined,
      undefined,
      pdo,
    ),
    { backend: "active", row_count: 2, truncated: false },
  );
  await assertRejects(() =>
    writeDatabase(
      { driver: "mysql" },
      privateUse,
      undefined,
      undefined,
      pdo,
    )
  );
  assertEquals(writes, 1);
});
Deno.test("write refusal is closed and thrown SQL/diagnostics cannot escape", async () => {
  assertEquals(
    await writeDatabase(
      input,
      use,
      undefined,
      () => Promise.resolve({ error_code: "database.sqlite_unreadable" }),
    ),
    { error_code: "database.sqlite_unreadable" },
  );
  const error = await assertRejects(() =>
    writeDatabase(input, use, undefined, () => {
      throw new Error(material.sql);
    })
  );
  assertEquals(String(error).includes("private_sentinel"), false);
});
