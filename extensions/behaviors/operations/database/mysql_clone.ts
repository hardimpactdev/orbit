import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import {
  createDatabase,
  CreateInput,
  CreateResult,
  sqlLiteral,
} from "./create.ts";
import { nativeEffect } from "./native_effect.ts";
import type { PrivateMaterialUse } from "./user_create_action.ts";

const Input = CreateInput.safeExtend({
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

/** Owns reset and copy locally. No old create-shaped child or Gateway SQL plan. */
export async function cloneMysql(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke = runNodeAgent,
) {
  try {
    const { source_database, ...input } = Input.parse(raw);
    let result: z.infer<typeof CreateResult> | undefined;
    await use(async (material) => {
      const password = z.string().min(1).max(16384).parse(material.password);
      const local: PrivateMaterialUse = (effect) => effect(material);
      signal?.throwIfAborted();
      const observed = await invoke({
        name: "database.server-admin",
        pin: input.agent_sha256!,
        sudo: true,
        deadlineMs: 900_000,
        mutates: false,
        input: {
          container: input.container,
          password,
          operation: "execute",
          password_variable: "MYSQL_PWD",
          client: ["mysql", "--user=root", "--batch", "--skip-column-names"],
          sql:
            `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=${
              sqlLiteral(source_database)
            };\n`,
        },
      }, signal);
      const refusal = CreateResult.safeParse(observed);
      if (refusal.success && "error_code" in refusal.data) {
        result = refusal.data;
        return;
      }
      if (
        z.object({ output: z.string().max(1024 * 1024) }).parse(observed).output
          .trim() !== source_database
      ) {
        result = { error_code: "database.source_missing" };
        return;
      }
      // Authenticate the reserved owner and prove every existing target's grants before DROP.
      const prepared = await createDatabase(input, local, signal, invoke);
      if ("error_code" in prepared) {
        result = prepared;
        return;
      }
      const call = async (body: Record<string, unknown>) => {
        signal?.throwIfAborted();
        const reply = await invoke(
          {
            name: "database.server-admin",
            pin: input.agent_sha256!,
            sudo: true,
            deadlineMs: 900_000,
            mutates: true,
            input: { container: input.container, password, ...body },
          } as NodeCall,
          signal,
        );
        const refusal = CreateResult.safeParse(reply);
        if (refusal.success && "error_code" in refusal.data) {
          result = refusal.data;
          return false;
        }
        z.object({ output: z.string().max(1024 * 1024).optional() }).strict()
          .parse(reply);
        return true;
      };
      if (
        !await call({
          operation: "execute",
          password_variable: "MYSQL_PWD",
          client: ["mysql", "--user=root", "--batch", "--skip-column-names"],
          sql: input.databases.map((name) =>
            `DROP DATABASE IF EXISTS \`${name}\`;`
          ).join("\n") + "\n",
        })
      ) return;
      const empty = await createDatabase(input, local, signal, invoke);
      if ("error_code" in empty) {
        result = empty;
        return;
      }
      if (
        !await call({
          operation: "copy",
          source: source_database,
          target: input.databases[0],
        })
      ) return;
      result = { backend: "active" };
    });
    if (!result) throw new Error("No completed clone");
    return result;
  } catch {
    throw new Error("database.mysql_clone_outcome_unknown");
  }
}

export function mysqlClone(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  invoke = runNodeAgent,
) {
  return nativeEffect(
    "database.mysql_clone",
    "@nckrtl/database-mysql-clone",
    CreateResult,
    (input, gateway, signal) =>
      cloneMysql(
        input,
        (use) => gateway.withPrivateMaterial(use),
        signal,
        invoke,
      ),
    makeHttp,
  );
}
export const behavior = {
  ...mysqlClone(),
  type: "@nckrtl/database-mysql-clone",
};
