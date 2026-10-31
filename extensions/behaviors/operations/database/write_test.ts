import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { databaseWrite, writeDatabase } from "./write.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

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
  assertEquals(
    await writeDatabase(
      { driver: "mysql" },
      privateUse,
      undefined,
      undefined,
      pdo,
    ),
    { error_code: "database.write_outcome_unknown" },
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

Deno.test("pre-effect failures return a failed outcome and never invoke SQL", async () => {
  let effects = 0;
  const effect = () => {
    effects++;
    return Promise.resolve({ row_count: 0, truncated: false });
  };
  for (const sql of ["x".repeat(16385), "bad\0sql"]) {
    assertEquals(
      await writeDatabase(
        input,
        (callback) => callback({ ...material, sql }),
        undefined,
        effect,
      ),
      { error_code: "database.write_outcome_unknown" },
    );
  }
  assertEquals(
    await writeDatabase(
      input,
      () => Promise.reject(new Error("private-read-failed")),
      undefined,
      effect,
    ),
    { error_code: "database.write_outcome_unknown" },
  );
  assertEquals(effects, 0);
  assertEquals(
    await writeDatabase(
      input,
      (callback) => callback({ ...material, sql: "x".repeat(16384) }),
      undefined,
      effect,
    ),
    { backend: "active", row_count: 0, truncated: false },
  );
  assertEquals(effects, 1);
});

Deno.test("native projection settles pre-effect refusal and retains post-invocation uncertainty", async () => {
  const args = {
    operation_id: 17,
    node_id: 14,
    kind: "database.write" as const,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  };
  for (const invoked of [false, true]) {
    const operation = {
      id: 17,
      node_id: 14,
      kind: "database.write",
      status: "queued",
      result: null as unknown,
      cancel_requested: false,
      input: { driver: "mysql" },
    };
    let output: Record<string, unknown> = {};
    let effects = 0;
    const http: Http = (method, path, body) => {
      if (path.includes("private-material")) {
        return Promise.resolve({
          status: 200,
          body: {
            data: {
              ...material,
              dsn: invoked ? "mysql:host=127.0.0.1" : "pgsql:host=127.0.0.1",
            },
          },
        });
      }
      if (method !== "GET") {
        const data = body as Record<string, unknown>;
        operation.status = String(data.status);
        operation.result = data.result;
      }
      return Promise.resolve({ status: 200, body: { data: operation } });
    };
    const behavior = databaseWrite(() => http, undefined, () => {
      effects++;
      throw new Error("private-diagnostic");
    });
    const ctx = {
      globalArgs: {
        gatewayUrl: "https://gateway.orbit",
        caFile: "/fixture/ca.pem",
      },
      writeResource: (
        _spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        output = value;
        return Promise.resolve({ name });
      },
    };
    if (invoked) {
      await assertRejects(() => behavior.methods.apply.execute(args, ctx));
      assertEquals(operation.status, "running");
      assertEquals(output, {});
      await assertRejects(() => behavior.methods.apply.execute(args, ctx));
      assertEquals(effects, 1);
    } else {
      await behavior.methods.apply.execute(args, ctx);
      const projected = behavior.methods.project.arguments.parse({
        ...args,
        outcome: output.outcome,
      });
      assertEquals(projected.outcome.status, "failed");
      assertEquals(
        projected.outcome.error_code,
        "database.write_outcome_unknown",
      );
      await assertRejects(() =>
        behavior.methods.project.execute(projected, ctx)
      );
      assertEquals(operation.status, "failed");
      assertEquals(effects, 0);
    }
  }
});
