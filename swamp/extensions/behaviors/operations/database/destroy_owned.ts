import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { invokeSql } from "./local_sql.ts";
import { runSqliteLocal, type SqliteInvoke } from "./sqlite_local.ts";
import { destroyDatabase, DestroyInput } from "./destroy.ts";
import type { Context } from "./native_effect.ts";
import type { PrivateMaterialUse } from "./private_material.ts";

const Path = z.string().startsWith("/").refine((p) =>
  !/[\0\r\n]|(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(p)
);
const Server = z.object({
  server_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  intent: DestroyInput,
}).strict();
const Sqlite = z.object({
  connection_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  checkout: Path,
  path: Path,
}).strict()
  .refine((v) => v.path.startsWith(v.checkout + "/"));
const Input = z.object({
  servers: z.array(Server).max(1000),
  sqlite: z.array(Sqlite).max(10000),
}).strict();
const Work = z.discriminatedUnion("type", [
  z.object({ type: z.literal("server"), key: z.string(), server: Server }),
  z.object({ type: z.literal("sqlite"), key: z.string(), sqlite: Sqlite }),
]);
const Material = z.object({
  payload: z.object({
    passwords: z.record(
      z.string().regex(/^[1-9][0-9]*$/),
      z.string().max(16384),
    ),
  }).strict(),
}).strict();
const Args = DirectArgs.extend({
  kind: z.literal("database.destroy_owned"),
  node_id: z.number().int().positive(),
});
const SqliteResult = z.union([
  z.object({ cancelled: z.literal(true) }).strict(),
  z.object({ backend: z.literal("absent") }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);

/** Compose one placed removal into any native parent DAG, without a child Operation. */
export async function destroyOwnedStep(
  raw: unknown,
  use: PrivateMaterialUse,
  signal?: AbortSignal,
  invoke = invokeSql,
  destroy = destroyDatabase,
  sqliteInvoke: SqliteInvoke = runSqliteLocal,
) {
  try {
    const work = Work.parse(raw);
    signal?.throwIfAborted();
    if (work.type === "server") {
      return await destroy(
        work.server.intent,
        async (consume) => {
          await use(async (rawMaterial) => {
            const material = Material.parse(rawMaterial);
            const password =
              material.payload.passwords[String(work.server.server_id)];
            if (password === undefined) {
              throw new Error("Missing admitted password");
            }
            await consume({ password });
          });
        },
        signal,
        invoke,
      );
    }
    const result = await sqliteInvoke({
      action: "copy",
      input: {
        operation: "remove",
        checkout: work.sqlite.checkout,
        path: work.sqlite.path,
      },
    }, signal);
    if (result && typeof result === "object" && "error_code" in result) {
      return SqliteResult.parse(result);
    }
    z.object({}).passthrough().parse(result);
    return { backend: "absent" as const };
  } catch {
    throw new Error("database.destroy_owned_outcome_unknown");
  }
}

const Current = z.object({
  id: z.number().int().positive(),
  kind: z.literal("database.destroy_owned"),
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  input: Input,
  cancel_requested: z.boolean(),
});
const ApplyArgs = Args.extend({
  work: Work,
  selected_node_id: z.number().int().positive(),
});

export function databaseDestroyOwned(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = invokeSql,
  destroy = destroyDatabase,
  sqliteInvoke: SqliteInvoke = runSqliteLocal,
) {
  return {
    type: "@nckrtl/database-destroy-owned",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      work: {
        description: "Admitted secret-free placed work for this one native run",
        schema: z.object({
          work: z.array(Work),
          started_at: z.string().datetime(),
          cancelled: z.boolean(),
        }),
        lifetime: "workflow",
        garbageCollection: 1,
      },
      result: {
        description: "Safe outcome of one native removal step",
        schema: z.object({ result: SqliteResult }),
        lifetime: "workflow",
        garbageCollection: 1,
      },
    },
    methods: {
      plan: {
        description:
          "Read the admitted owner and expose its native placed steps",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw),
            gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = Current.parse(await gateway.read(ctx.signal));
          if (
            current.id !== args.operation_id ||
            current.node_id !== args.node_id ||
            !["queued", "running"].includes(current.status)
          ) throw new Error("Wrong admitted identity or terminal removal");
          const started_at = new Date().toISOString();
          await gateway.project({ status: "running", started_at });
          const cancelled = current.cancel_requested || !!ctx.signal?.aborted;
          const work = cancelled ? [] : [
            ...current.input.servers.map((server) => ({
              type: "server" as const,
              key: `server-${server.server_id}`,
              server,
            })),
            ...current.input.sqlite.map((sqlite) => ({
              type: "sqlite" as const,
              key: `sqlite-${sqlite.connection_id}`,
              sqlite,
            })),
          ];
          if (new Set(work.map((w) => w.key)).size !== work.length) {
            throw new Error("Duplicate admitted removal");
          }
          return {
            dataHandles: [
              await ctx.writeResource("work", "work", {
                work,
                started_at,
                cancelled,
              }),
            ],
          };
        },
      },
      apply: {
        description: "Run exactly one admitted removal on its placed Node",
        arguments: ApplyArgs,
        execute: async (
          raw: z.infer<typeof ApplyArgs>,
          ctx: Context,
        ) => {
          const args = ApplyArgs.parse(raw),
            gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = Current.parse(await gateway.read(ctx.signal));
          const work = args.work;
          const selected = work.type === "server" ? work.server : work.sqlite;
          const admitted = work.type === "server"
            ? current.input.servers.find((server) =>
              server.server_id === work.server.server_id
            )
            : current.input.sqlite.find((sqlite) =>
              sqlite.connection_id === work.sqlite.connection_id
            );
          const key = args.work.type === "server"
            ? `server-${args.work.server.server_id}`
            : `sqlite-${args.work.sqlite.connection_id}`;
          if (
            current.id !== args.operation_id ||
            current.node_id !== args.node_id ||
            !["queued", "running"].includes(current.status) ||
            args.selected_node_id !== selected.node_id ||
            args.work.key !== key ||
            JSON.stringify(admitted) !== JSON.stringify(selected)
          ) throw new Error("Wrong admitted removal or selected Node");
          const result = current.cancel_requested || ctx.signal?.aborted
            ? { cancelled: true as const }
            : SqliteResult.parse(
              await destroyOwnedStep(
                args.work,
                (use) => gateway.withPrivateMaterial(use),
                ctx.signal,
                invoke,
                destroy,
                sqliteInvoke,
              ),
            );
          return {
            dataHandles: [
              await ctx.writeResource("result", `result-${args.work.key}`, {
                result,
              }),
            ],
          };
        },
      },
      project: {
        description:
          "Project the aggregate native outcome once, without repeating effects",
        arguments: Args.extend({
          results: z.array(SqliteResult),
          started_at: z.string().datetime(),
          cancelled: z.boolean(),
        }),
        execute: async (
          raw: z.infer<typeof Args> & {
            results: z.infer<typeof SqliteResult>[];
            started_at: string;
            cancelled: boolean;
          },
          ctx: Context,
        ) => {
          const args = Args.extend({
            results: z.array(SqliteResult),
            started_at: z.string().datetime(),
            cancelled: z.boolean(),
          }).parse(raw);
          const failure = args.results.find((r) => "error_code" in r);
          const cancelled = args.cancelled ||
            args.results.some((r) => "cancelled" in r);
          const status = failure
            ? "failed"
            : cancelled
            ? "cancelled"
            : "succeeded";
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          await gateway.project({
            status,
            result: failure ?? (cancelled ? {} : { backend: "absent" }),
            error_code: failure && "error_code" in failure
              ? failure.error_code
              : null,
            started_at: args.started_at,
            finished_at: new Date().toISOString(),
          });
          if (failure && "error_code" in failure) {
            throw new Error(failure.error_code);
          }
          return { status };
        },
      },
    },
  };
}
export const behavior = {
  ...databaseDestroyOwned(),
  type: "@nckrtl/database-destroy-owned",
};
