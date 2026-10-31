import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { destroyDatabase } from "./destroy.ts";
import { mysqlOwnership } from "./destroy_statements.ts";
const input = {
  driver: "mysql",
  container: "orbit-process-42-proof",
  host: "10.44.0.9",
  port: 3306,
  default_open: false,
  databases: ["proof"],
  users: ["proof"],
  test_prefixes: [] as string[],
  owners: { proof: "proof", proof_testing: "proof" },
};
const use = (f: (m: Record<string, unknown>) => Promise<void>) =>
  f({ password: "private-root-sentinel" });
Deno.test("MySQL destroy matches the exact escaped grant used by managed create", () => {
  const query = mysqlOwnership(["my_database"], ["owner"], []);
  const encoded = Array.from(
    new TextEncoder().encode("my\\_database"),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  assertStringIncludes(
    query,
    `BINARY Db=BINARY CONVERT(0x${encoded} USING utf8mb4)`,
  );
});
Deno.test("system names and prefixes refuse before private material or SQL", async () => {
  for (
    const [databases, test_prefixes] of [
      [["Postgres"], []],
      [["template1"], []],
      [[], ["my"]],
      [[], ["information_"]],
    ]
  ) {
    let touched = 0;
    assertEquals(
      await destroyDatabase(
        { ...input, databases, test_prefixes },
        () => {
          touched++;
          return Promise.resolve();
        },
      ),
      { error_code: "database.ownership_unproven" },
    );
    assertEquals(touched, 0);
  }
});
Deno.test("MySQL inventories literal test prefix, proves grants and checks blockers before drop", async () => {
  const calls: string[] = [];
  const result = await destroyDatabase(
    { ...input, test_prefixes: ["proof_testing"] },
    use,
    undefined,
    (call) => {
      assertEquals(call.sudo, true);
      assertEquals(call.name, "database.server-admin");
      assertEquals(call.input.password, "private-root-sentinel");
      calls.push(String(call.input.sql));
      return Promise.resolve({
        output: calls.length === 1 ? "proof_testing_1\n" : "",
      });
    },
  );
  assertEquals(result, { backend: "absent" });
  assertEquals(calls.length, 4);
  assertStringIncludes(
    calls[0],
    "BINARY LEFT(SCHEMA_NAME,13)=BINARY 'proof_testing'",
  );
  assertStringIncludes(calls[1], "FROM mysql.db");
  assertStringIncludes(calls[1], "Drop_priv='Y'");
  assertStringIncludes(calls[2], "PROCESSLIST");
  assertEquals(
    calls[3],
    "DROP DATABASE IF EXISTS `proof`;\nDROP DATABASE IF EXISTS `proof_testing_1`;\nDROP USER IF EXISTS 'proof'@'%';\n",
  );
});
Deno.test("foreign live prefix member refuses before grant query or drop", async () => {
  let calls = 0;
  const result = await destroyDatabase(
    { ...input, test_prefixes: ["proof_testing"] },
    use,
    undefined,
    () => {
      calls++;
      return Promise.resolve({ output: "unrelated\n" });
    },
  );
  assertEquals(result, { error_code: "database.ownership_unproven" });
  assertEquals(calls, 1);
});
Deno.test("foreign MySQL grants refuse before blockers or drop", async () => {
  let calls = 0;
  assertEquals(
    await destroyDatabase(input, use, undefined, () => {
      calls++;
      return Promise.resolve({ output: "proof\n" });
    }),
    { error_code: "database.ownership_unproven" },
  );
  assertEquals(calls, 1);
});
Deno.test("live MySQL clients refuse after grants but before drop", async () => {
  let calls = 0;
  assertEquals(
    await destroyDatabase(input, use, undefined, () => {
      calls++;
      return Promise.resolve({ output: calls === 2 ? "proof\n" : "" });
    }),
    { error_code: "database.in_use" },
  );
  assertEquals(calls, 2);
});
Deno.test("PostgreSQL builds fenced owner checks and always restores admission after SQL failure", async () => {
  const calls: string[] = [];
  const result = await destroyDatabase(
    { ...input, driver: "pgsql" },
    use,
    undefined,
    (call) => {
      calls.push(String(call.input.sql));
      return Promise.resolve(
        calls.length === 1
          ? { error_code: "database.server_admin_failed" }
          : { output: "" },
      );
    },
  );
  assertEquals(result, { error_code: "database.server_admin_failed" });
  assertEquals(calls.length, 2);
  assertStringIncludes(calls[0], "datdba IS DISTINCT FROM");
  assertStringIncludes(calls[0], "pg_advisory_lock");
  assertStringIncludes(calls[0], 'DROP DATABASE IF EXISTS "proof";');
  assertStringIncludes(calls[0], "\\if :orbit_blocked");
  assertStringIncludes(calls[1], "ALLOW_CONNECTIONS %s");
  assertEquals(calls[1].includes("DROP DATABASE"), false);
});
Deno.test("Valkey preserves default and keys while removing only admitted users", async () => {
  const calls: string[][] = [];
  const result = await destroyDatabase(
    { ...input, driver: "redis", users: ["default", "proof"] },
    use,
    undefined,
    undefined,
    () =>
      Promise.resolve({
        command: (...args: string[]) => {
          calls.push(args);
          return Promise.resolve(
            args[0] === "ACL" && args[1] === "LIST"
              ? [
                "user default on nopass ~* &* +@all",
                "user proof on nopass ~proof:* &proof:* +@all",
              ]
              : "OK",
          );
        },
        close() {},
      }),
  );
  assertEquals(result, { backend: "absent" });
  assertEquals(calls.map((c) => c.slice(0, 2)), [
    ["AUTH", "default"],
    ["ACL", "LIST"],
    ["ACL", "DELUSER"],
    ["ACL", "SAVE"],
  ]);
  assertEquals(calls[2], ["ACL", "DELUSER", "proof"]);
});
Deno.test("private transport exceptions never escape to native outputs", async () => {
  await assertRejects(
    () =>
      destroyDatabase(input, use, undefined, () => {
        throw new Error("private-root-sentinel");
      }),
    Error,
    "database.destroy_outcome_unknown",
  );
});
