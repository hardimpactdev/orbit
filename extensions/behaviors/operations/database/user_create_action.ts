import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";

/** Public projection only. SQL and passwords belong to the shared private read. */
export const UserCreateInput = z.object({
  driver: z.enum(["mysql", "pgsql"]),
  container: z.string().regex(
    /^orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*$/,
  ),
  agent_sha256: z.string().regex(/^[0-9a-f]{64}$/),
  username: z.string().regex(/^[A-Za-z0-9_]{1,64}$/),
  replication: z.boolean(),
  known_user: z.boolean(),
}).strict();

const Material = z.object({
  password: z.string().max(16384).refine((value) => !value.includes("\0")),
  sql: z.string().min(1).max(1024 * 1024).refine((value) =>
    value.trim().length > 0 && !value.includes("\0")
  ),
}).strict();
const Refusal = z.object({
  error_code: z.enum([
    "agent.version_mismatch",
    "database.server_admin_invalid",
    "database.server_admin_failed",
    "database.server_not_ready",
    "database.wal_level_not_logical",
    "database.name_conflict",
  ]),
}).strict();
const Applied = z.object({ output: z.string().max(1024 * 1024) }).strict();
export const UserResult = z.union([
  z.object({ backend: z.literal("active") }).strict(),
  Refusal,
]);

/** A callback supplied by the shared Gateway client, never a family transport. */
export type PrivateMaterialUse = (
  use: (material: Record<string, unknown>) => Promise<void>,
) => Promise<void>;

/** One fixed action; native workflow resume owns uncertainty, with no local retry. */
export async function createUser(
  rawIntent: unknown,
  withPrivateMaterial: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke: (call: NodeCall, signal?: AbortSignal) => Promise<unknown> =
    runNodeAgent,
): Promise<{ backend: "active" } | z.infer<typeof Refusal>> {
  try {
    const intent = UserCreateInput.parse(rawIntent);
    signal?.throwIfAborted();
    let outcome: { backend: "active" } | z.infer<typeof Refusal> | undefined;
    await withPrivateMaterial(async (rawMaterial) => {
      const material = Material.parse(rawMaterial);
      signal?.throwIfAborted();
      const postgres = intent.driver === "pgsql";
      if (postgres && intent.username.length > 63) {
        throw new Error("Invalid role");
      }
      const execute = (sql: string, mutates: boolean) =>
        invoke({
          name: "database.server-admin",
          pin: intent.agent_sha256,
          sudo: true,
          mutates,
          deadlineMs: 900_000,
          input: {
            operation: "execute",
            container: intent.container,
            password_variable: postgres ? "PGPASSWORD" : "MYSQL_PWD",
            client: postgres
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
              : ["mysql", "--user=root", "--batch", "--skip-column-names"],
            password: material.password,
            sql,
          },
        }, signal);
      if (postgres && intent.replication) {
        const readiness = await execute("SHOW wal_level;\n", false);
        const refusal = Refusal.safeParse(readiness);
        if (refusal.success) {
          outcome = refusal.data;
          return;
        }
        if (Applied.parse(readiness).output.trim() !== "logical") {
          outcome = { error_code: "database.wal_level_not_logical" };
          return;
        }
      }
      const result = await execute(material.sql, true);
      const refusal = Refusal.safeParse(result);
      if (refusal.success) {
        outcome = refusal.data;
        return;
      }
      Applied.parse(result);
      outcome = { backend: "active" };
    });
    if (outcome === undefined) throw new Error("No completed Node action");
    return outcome;
  } catch {
    // Zod issues, cancellation reasons and thrown transport errors can contain SQL.
    throw new Error("database.user_create_outcome_unknown");
  }
}
