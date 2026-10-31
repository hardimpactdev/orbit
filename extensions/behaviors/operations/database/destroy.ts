import { z } from "npm:zod@4.4.3";
import { type DatabaseSqlCall as NodeCall, invokeSql } from "./local_sql.ts";
import { nativeEffect } from "./native_effect.ts";
import { sqlClient, SqlRefusal } from "./destroy_sql.ts";
import {
  mysqlInventory,
  mysqlOwnership,
  postgresRemoval,
} from "./destroy_statements.ts";
import { ValkeyClient } from "./valkey_client.ts";
import type { PrivateMaterialUse } from "./user_create_action.ts";

const Name = z.string().regex(/^[A-Za-z0-9_]{1,64}$/);
const User = z.string().min(1).max(128).refine((v) => !/[\s\0]/.test(v));
export const DestroyInput = z.object({
  driver: z.enum(["mysql", "pgsql", "redis"]),
  container: z.string().regex(
    /^orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*$/,
  ).nullable(),
  host: z.ipv4(),
  port: z.number().int().min(1).max(65535),
  default_open: z.boolean(),
  databases: z.array(Name).max(10000),
  users: z.array(User).max(10000),
  test_prefixes: z.array(Name).max(1000),
  owners: z.record(Name, Name),
}).strict().refine((v) => v.driver === "redis" || v.container !== null);
const Material = z.object({
  password: z.string().max(16384).refine((v) => !v.includes("\0")),
}).strict();
const Refusal = z.union([
  SqlRefusal,
  z.object({
    error_code: z.enum([
      "database.in_use",
      "database.ownership_unproven",
      "database.server_command_failed",
    ]),
  }).strict(),
]);
export const DestroyResult = z.union([
  z.object({ backend: z.literal("absent") }).strict(),
  Refusal,
]);
type Result = z.infer<typeof DestroyResult>;
const literal = (value: string) => `'${value}'`;

const systemDatabases = [
  "mysql",
  "sys",
  "performance_schema",
  "information_schema",
  "postgres",
];
function systemName(name: string) {
  name = name.toLowerCase();
  return systemDatabases.includes(name) || name.startsWith("template");
}
function systemPrefix(prefix: string) {
  prefix = prefix.toLowerCase();
  return [...systemDatabases, "template"].some((n) => n.startsWith(prefix)) ||
    prefix.startsWith("template");
}

/** Dynamic test members are admitted only through a proven prefix, never a name guess. */
function mysqlBlockers(names: string[], users: string[]) {
  const databases = names.map(literal).join(",") || "NULL";
  const definers = users.map((u) => literal(`${u}@%`)).join(",") || "NULL";
  return `SELECT DISTINCT blocker FROM (
 SELECT DB AS blocker FROM information_schema.PROCESSLIST WHERE DB IN (${databases}) AND ID <> CONNECTION_ID()
 UNION ALL SELECT locks.OBJECT_SCHEMA FROM performance_schema.metadata_locks locks LEFT JOIN performance_schema.threads threads ON threads.THREAD_ID=locks.OWNER_THREAD_ID WHERE locks.OBJECT_SCHEMA IN (${databases}) AND (threads.PROCESSLIST_ID IS NULL OR threads.PROCESSLIST_ID<>CONNECTION_ID())
 UNION ALL SELECT TABLE_SCHEMA FROM information_schema.VIEWS WHERE DEFINER IN (${definers}) AND TABLE_SCHEMA NOT IN (${databases})
 UNION ALL SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE DEFINER IN (${definers}) AND ROUTINE_SCHEMA NOT IN (${databases})
 UNION ALL SELECT TRIGGER_SCHEMA FROM information_schema.TRIGGERS WHERE DEFINER IN (${definers}) AND TRIGGER_SCHEMA NOT IN (${databases})
 UNION ALL SELECT EVENT_SCHEMA FROM information_schema.EVENTS WHERE DEFINER IN (${definers}) AND EVENT_SCHEMA NOT IN (${databases})
 UNION ALL SELECT TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA IN (${databases}) AND TABLE_SCHEMA<>REFERENCED_TABLE_SCHEMA
 ) blockers ORDER BY blocker;`;
}

/** Reusable fixed effect for a same-Node native DAG; it never admits a Gateway child. */
export async function destroyDatabase(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown> =
    invokeSql,
  connect: (
    host: string,
    port: number,
  ) => Promise<Pick<ValkeyClient, "command" | "close">> = ValkeyClient.connect,
) {
  try {
    const input = DestroyInput.parse(raw);
    if (
      input.databases.some(systemName) ||
      input.test_prefixes.some(systemPrefix)
    ) {
      return { error_code: "database.ownership_unproven" } as const;
    }
    signal?.throwIfAborted();
    let result: Result | undefined;
    await use(async (rawMaterial) => {
      const material = Material.parse(rawMaterial);
      signal?.throwIfAborted();
      if (input.driver === "redis") {
        const names = input.users.filter((n) => n !== "default");

        let client: Pick<ValkeyClient, "command" | "close"> | undefined;
        try {
          client = await connect(input.host, input.port);
          await client.command("AUTH", "default", material.password);
          const lines = z.array(z.string().max(1024 * 1024)).max(10000).parse(
            await client.command("ACL", "LIST"),
          );
          const saved = new Map<string, string[]>();
          for (const line of lines) {
            const [prefix, name, ...tokens] = line.split(" ");
            if (prefix !== "user" || !name) {
              throw new Error("Invalid ACL state");
            }
            saved.set(name, tokens);
          }
          signal?.throwIfAborted();
          try {
            if (names.length) await client.command("ACL", "DELUSER", ...names);
            await client.command("ACL", "SAVE");
          } catch {
            for (const name of names) {
              if (saved.has(name)) {
                await client.command(
                  "ACL",
                  "SETUSER",
                  name,
                  "reset",
                  ...saved.get(name)!,
                );
              }
            }
            await client.command("ACL", "SAVE");
            throw new Error("ACL removal failed");
          }
          result = { backend: "absent" };
        } catch {
          result = { error_code: "database.server_command_failed" };
        } finally {
          client?.close();
        }
        return;
      }
      const execute = sqlClient(
        input.driver,
        input.container!,
        material.password,
        signal,
        invoke,
      );
      if (input.driver === "pgsql") {
        if (
          input.databases.some((n) =>
            n.length > 63 || !input.owners[n] || input.owners[n].length > 63
          ) ||
          input.users.some((n) =>
            !/^[A-Za-z0-9_]{1,63}$/.test(n) || n === "postgres"
          )
        ) {
          result = { error_code: "database.ownership_unproven" };
          return;
        }
        const plan = postgresRemoval(
          input.databases,
          input.users,
          input.owners,
        );
        let effect: Awaited<ReturnType<typeof execute>> | undefined;
        try {
          effect = await execute(plan.drop, true);
        } finally {
          const restore = sqlClient(
            input.driver,
            input.container!,
            material.password,
            undefined,
            invoke,
          );
          const restored = await restore(plan.restore, true);
          if ("error_code" in restored) result = restored;
          else if (effect) {
            result = "error_code" in effect
              ? effect
              : effect.output.trim()
              ? { error_code: "database.in_use" }
              : { backend: "absent" };
          }
        }
        return;
      }
      input.users.forEach((n) =>
        z.string().regex(/^[A-Za-z0-9_]{1,32}$/).parse(n)
      );
      if (input.users.includes("root")) {
        result = { error_code: "database.ownership_unproven" };
        return;
      }
      const names = [...new Set(input.databases)];
      if (input.test_prefixes.length) {
        const inventory = await execute(
          mysqlInventory(input.test_prefixes),
          false,
        );
        if ("error_code" in inventory) {
          result = inventory;
          return;
        }
        const found = inventory.output.trim()
          ? inventory.output.trim().split(/\r?\n/)
          : [];
        if (found.length > 10000) throw new Error("Inventory bound");
        for (const name of found) {
          Name.parse(name);
          if (systemName(name)) {
            result = { error_code: "database.ownership_unproven" };
            return;
          }
          if (
            !names.includes(name) &&
            !input.test_prefixes.some((p) => name.startsWith(p))
          ) {
            result = { error_code: "database.ownership_unproven" };
            return;
          }
          if (!names.includes(name)) names.push(name);
        }
      }
      const owners = names.map((name) =>
        input.owners[name] ??
          input.owners[
            input.test_prefixes.find((p) => name.startsWith(p)) ?? ""
          ]
      );
      if (
        owners.some((owner) =>
          !owner || !/^[A-Za-z0-9_]{1,32}$/.test(owner) || owner === "root"
        )
      ) {
        result = { error_code: "database.ownership_unproven" };
        return;
      }
      if (names.length) {
        const ownership = await execute(
          mysqlOwnership(names, owners, input.test_prefixes),
          false,
        );
        if ("error_code" in ownership) {
          result = ownership;
          return;
        }
        if (ownership.output.trim()) {
          result = { error_code: "database.ownership_unproven" };
          return;
        }
      }
      const blockers = await execute(mysqlBlockers(names, input.users), false);
      if ("error_code" in blockers) {
        result = blockers;
        return;
      }
      if (blockers.output.trim()) {
        result = { error_code: "database.in_use" };
        return;
      }
      signal?.throwIfAborted();
      const sql = names.map((n) =>
        `DROP DATABASE IF EXISTS \`${n}\`;\n`
      ).join("") + input.users.map((n) =>
        `DROP USER IF EXISTS '${n}'@'%';\n`
      ).join("");
      const effect = sql ? await execute(sql, true) : { output: "" };
      result = "error_code" in effect ? effect : { backend: "absent" };
    });
    if (!result) throw new Error("No completed removal");
    return result;
  } catch {
    throw new Error("database.destroy_outcome_unknown");
  }
}
export function databaseDestroy(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown> =
    invokeSql,
  connect: (
    host: string,
    port: number,
  ) => Promise<Pick<ValkeyClient, "command" | "close">> = ValkeyClient.connect,
) {
  return nativeEffect(
    "database.destroy",
    "@nckrtl/database-destroy",
    DestroyResult,
    (input, gateway, signal) =>
      destroyDatabase(
        input,
        (use) => gateway.withPrivateMaterial(use),
        signal,
        invoke,
        connect,
      ),
    makeHttp,
  );
}
export const behavior = {
  ...databaseDestroy(),
  type: "@nckrtl/database-destroy",
};
