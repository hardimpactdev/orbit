import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { cloneMysql } from "./mysql_clone.ts";
import { sqlLiteral } from "./create.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const input = {
  repair: false,
  driver: "mysql",
  username: "target_owner",
  databases: ["target", "target_test"],
  test_prefix: "target_test",
  locale: null,
  host: "10.44.0.9",
  port: 3306,
  container: "orbit-process-42-proof",
  agent_sha256: "a".repeat(64),
  index: null,
  patterns: [],
  allow: [],
  source_database: "source_database",
};
const use = (effect: (m: Record<string, unknown>) => Promise<void>) =>
  effect({
    password: "private-root-clone",
    payload: { password: "a".repeat(40), salt: "YWFhYWFhYWFhYWFhYWFhYQ==" },
  });
Deno.test("MySQL clone checks ownership, resets locally and then copies only admitted databases", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await cloneMysql(input, use, undefined, (call) => {
      calls.push(call);
      return Promise.resolve(
        call.input.operation === "copy" ? {} : {
          output:
            String(call.input.sql).includes("SCHEMA_NAME='source_database'")
              ? "source_database\n"
              : "",
        },
      );
    }),
    { backend: "active" },
  );
  const reset = calls.findIndex((c) =>
    String(c.input.sql).startsWith("DROP DATABASE")
  );
  assertEquals(reset > 0, true);
  assertEquals(
    calls.slice(0, reset).some((c) =>
      String(c.input.sql).includes("information_schema.SCHEMATA")
    ),
    true,
  );
  assertEquals(
    calls[reset].input.sql,
    "DROP DATABASE IF EXISTS `target`;\nDROP DATABASE IF EXISTS `target_test`;\n",
  );
  const copy = calls.at(-1)!;
  assertEquals(copy.input, {
    container: input.container,
    password: "private-root-clone",
    operation: "copy",
    source: "source_database",
    target: "target",
  });
  assertEquals(
    calls.every((c) =>
      c.sudo && c.pin === input.agent_sha256 &&
      c.name === "database.server-admin"
    ),
    true,
  );
});
Deno.test("foreign target ownership refuses before any reset or copy", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await cloneMysql(input, use, undefined, (call) => {
      calls.push(call);
      return Promise.resolve({
        output: String(call.input.sql).includes("SCHEMA_NAME='source_database'")
          ? "source_database\n"
          : String(call.input.sql).includes("information_schema.SCHEMATA")
          ? "target\n"
          : "",
      });
    }),
    { error_code: "database.name_conflict" },
  );
  assertEquals(calls.some((c) => c.mutates), false);
});
Deno.test("source and target alias, unsafe names and private fields refuse before material", async () => {
  for (
    const raw of [
      { ...input, source_database: "target" },
      { ...input, source_database: "target_test_2" },
      { ...input, source_database: "source;DROP" },
      { ...input, source_database: "mysql" },
      { ...input, password: "caller-secret" },
    ]
  ) {
    let read = false;
    await assertRejects(
      () =>
        cloneMysql(raw, () => {
          read = true;
          return Promise.resolve();
        }),
      Error,
      "database.mysql_clone_outcome_unknown",
    );
    assertEquals(read, false);
  }
});
Deno.test("PostgreSQL literals preserve backslashes under standard_conforming_strings", () => {
  assertEquals(sqlLiteral("one\\two'", true), "'one\\two'''");
  assertEquals(sqlLiteral("one\\two'"), "'one\\\\two'''");
});

Deno.test("missing source refuses before any target mutation", async () => {
  const calls: NodeCall[] = [];
  assertEquals(
    await cloneMysql(input, use, undefined, (call) => {
      calls.push(call);
      return Promise.resolve({ output: "" });
    }),
    { error_code: "database.source_missing" },
  );
  assertEquals(calls.length, 1);
  assertEquals(calls[0].mutates, false);
});
