import { z } from "npm:zod@4.4.3";
import { type DatabaseSqlCall as NodeCall, invokeSql } from "./local_sql.ts";
import {
  createDatabase,
  CreateInput,
  CreateResult,
  sqlLiteral,
} from "./create.ts";
import type { PrivateMaterialUse } from "./user_create_action.ts";

export const MysqlCloneInput = CreateInput.safeExtend({
  driver: z.literal("mysql"),
  source_database: z.string().regex(/^[A-Za-z0-9_]{1,64}$/).refine((name) =>
    !["mysql", "sys", "performance_schema", "information_schema", "postgres"]
      .includes(name.toLowerCase()) &&
    !name.toLowerCase().startsWith("template")
  ),
}).refine((i) =>
  !i.databases.includes(i.source_database) &&
  (i.test_prefix === null || !i.source_database.startsWith(i.test_prefix))
);

const Applied = z.object({ output: z.string().max(1024 * 1024).optional() })
  .strict();
export type CloneResult = z.infer<typeof CreateResult>;

async function mysqlCall(
  raw: unknown,
  use: PrivateMaterialUse,
  body: (i: z.infer<typeof MysqlCloneInput>) => Record<string, unknown>,
  mutates: boolean,
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown>,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const i = MysqlCloneInput.parse(raw);
  let reply: Record<string, unknown> | undefined;
  await use(async (material) => {
    const password = z.string().min(1).max(16384).parse(material.password);
    signal?.throwIfAborted();
    const value = await invoke({
      name: "database.server-admin",
      sudo: true,
      deadlineMs: 900_000,
      maxOutputBytes: 1024 * 1024 + 4096,
      mutates,
      input: { container: i.container, password, ...body(i) },
    }, signal);
    const refusal = CreateResult.safeParse(value);
    reply = refusal.success && "error_code" in refusal.data
      ? refusal.data
      : Applied.parse(value);
  });
  if (!reply) throw new Error("Missing MySQL result");
  return reply;
}
const execute = (sql: string) => ({
  operation: "execute",
  password_variable: "MYSQL_PWD",
  client: ["mysql", "--user=root", "--batch", "--skip-column-names"],
  sql,
});

/** Native prepare step: prove source and owner without resetting data. */
export async function prepareMysqlClone(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown> =
    invokeSql,
): Promise<CloneResult> {
  try {
    const i = MysqlCloneInput.parse(raw);
    const { source_database, ...create } = i;
    const source = await mysqlCall(
      i,
      use,
      () =>
        execute(
          `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=${
            sqlLiteral(source_database)
          };\n`,
        ),
      false,
      invoke,
      signal,
    );
    if ("error_code" in source) return CreateResult.parse(source);
    if (
      z.object({ output: z.string().max(1024 * 1024) }).parse(source).output
        .trim() !== source_database
    ) return { error_code: "database.source_missing" };
    // createDatabase authenticates the reserved owner and checks every existing name's grants.
    return await createDatabase(create, use, signal, invoke);
  } catch {
    throw new Error("database.clone_prepare_outcome_unknown");
  }
}

/** Reset and copy share one native step; an uncertain copy always restarts with empty targets. */
export async function copyMysqlClone(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown> =
    invokeSql,
): Promise<CloneResult> {
  try {
    const i = MysqlCloneInput.parse(raw);
    // Recheck the source and owner at the destructive boundary, including on native resume.
    const proof = await prepareMysqlClone(i, use, signal, invoke);
    if ("error_code" in proof) return proof;
    const reset = await mysqlCall(
      i,
      use,
      (input) =>
        execute(
          input.databases.map((name) => `DROP DATABASE IF EXISTS \`${name}\`;`)
            .join("\n") + "\n",
        ),
      true,
      invoke,
      signal,
    );
    if ("error_code" in reset) return CreateResult.parse(reset);
    const { source_database: _, ...create } = i;
    const empty = await createDatabase(create, use, signal, invoke);
    if ("error_code" in empty) return empty;
    const copied = await mysqlCall(
      i,
      use,
      (i) => ({
        operation: "copy",
        source: i.source_database,
        target: i.databases[0],
      }),
      true,
      invoke,
      signal,
    );
    return "error_code" in copied
      ? CreateResult.parse(copied)
      : { backend: "active" };
  } catch {
    throw new Error("database.clone_copy_outcome_unknown");
  }
}
