import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import {
  cleanupSnapshot,
  exportSnapshot,
  type FileContext,
  type Invoke,
  receiveSnapshot,
  SeedSource,
  SeedTarget,
  seedTarget,
  snapshotFile,
  SnapshotRef,
  SnapshotSource,
} from "./sqlite_actions.ts";
import { runSqliteLocal } from "./sqlite_local.ts";

const Kind = z.enum([
  "database.sqlite_export",
  "database.sqlite_seed",
  "database.sqlite_seed_abandon",
]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Intent = z.object({
  source: SnapshotSource,
  target: SeedTarget.optional(),
  started_at: z.string().datetime().optional(),
  cancel_requested: z.boolean().default(false),
}).strict().refine((v) =>
  !v.target ||
  (v.source.variant === "seed" && v.source.operation === v.target.operation &&
    v.source.target_instance_id === v.target.target_instance_id)
);
const Outcome = z.object({
  ok: z.boolean(),
  error_code: z.string().regex(/^[a-z0-9_.-]+$/).optional(),
  snapshot: SnapshotRef.optional(),
  state: z.enum([
    "ready",
    "resume",
    "complete",
    "changed",
    "cleaned",
    "cancelled",
  ])
    .optional(),
  incoming: z.string().optional(),
}).strict();
type Result = z.infer<typeof Outcome>;
type Context = FileContext & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};

/** One native type owns both source and target file access within each run. */
export function sqliteNative(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke: Invoke = runSqliteLocal,
  actions = { exportSnapshot, receiveSnapshot, cleanupSnapshot, seedTarget },
) {
  const save = async (
    ctx: Context,
    name: string,
    action: () => Promise<Result>,
  ) => {
    let result: Result;
    try {
      const intent = Intent.parse(await ctx.readResource("intent"));
      result = intent.cancel_requested
        ? { ok: true, state: "cancelled" }
        : Outcome.parse(await action());
    } catch {
      result = {
        ok: false,
        error_code: `database.sqlite_${name.replaceAll("-", "_")}_failed`,
      };
    }
    return { dataHandles: [await ctx.writeResource("outcome", name, result)] };
  };
  return {
    type: "@nckrtl/database-sqlite",
    version: "2026.10.31.1",
    globalArguments: Globals,
    files: { snapshot: snapshotFile },
    resources: {
      intent: { schema: Intent, lifetime: "workflow", garbageCollection: 1 },
      outcome: { schema: Outcome, lifetime: "workflow", garbageCollection: 1 },
    },
    methods: {
      read: {
        description:
          "Read safe recorded SQLite descriptors on the Operation Node",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          try {
            const args = Args.parse(raw);
            const gateway = new GatewayOperation(makeHttp(ctx), args);
            const current = z.object({
              id: z.literal(args.operation_id),
              kind: z.literal(args.kind),
              node_id: z.literal(args.node_id),
              input: Intent,
              cancel_requested: z.boolean(),
            }).parse(
              await gateway.read(ctx.signal),
            );
            if (
              args.kind !== "database.sqlite_export" &&
              (!current.input.target ||
                current.input.target.node_id !== args.node_id ||
                !SeedSource.safeParse(current.input.source).success)
            ) {
              throw new Error("seed target unavailable");
            }
            if (
              args.kind === "database.sqlite_export" &&
              current.input.source.node_id !== args.node_id
            ) {
              throw new Error("wrong export placement");
            }
            const started_at = new Date().toISOString();
            if (!current.cancel_requested) {
              await gateway.project({ status: "running", started_at });
            }
            return {
              dataHandles: [
                await ctx.writeResource("intent", "intent", {
                  ...current.input,
                  started_at,
                  cancel_requested: current.cancel_requested,
                }),
              ],
            };
          } catch {
            throw new Error("database.sqlite_intent_invalid");
          }
        },
      },
      export: {
        description: "Export an admitted snapshot as a native file",
        arguments: z.object({
          source: SnapshotSource,
          run_id: z.string().uuid(),
        }),
        execute: async (
          args: { source: unknown; run_id: string },
          ctx: Context,
        ) =>
          await save(
            ctx,
            "export",
            async () => ({
              ok: true,
              snapshot: await actions.exportSnapshot(
                args.source,
                ctx,
                `snapshot-${args.run_id}`,
                invoke,
                ctx.signal,
              ),
            }),
          ),
      },
      prepare: {
        description:
          "Prepare the admitted target inode with the pinned seed primitive",
        arguments: z.object({
          run_id: z.string().uuid(),
          target: SeedTarget,
          exported: Outcome,
        }),
        execute: async (
          args: { target: unknown; exported: Result },
          ctx: Context,
        ) =>
          await save(
            ctx,
            "prepare",
            async () =>
              args.exported.ok
                ? {
                  ok: true,
                  ...await actions.seedTarget(
                    args.target,
                    "prepare",
                    args.exported.snapshot,
                    invoke,
                    ctx.signal,
                  ),
                }
                : args.exported,
          ),
      },
      receive: {
        description:
          "Stream the native snapshot into the prepared private inode",
        arguments: z.object({
          run_id: z.string().uuid(),
          target: SeedTarget,
          exported: Outcome,
          prepared: Outcome,
        }),
        execute: async (
          args: {
            target: z.infer<typeof SeedTarget>;
            exported: Result;
            prepared: Result;
          },
          ctx: Context,
        ) =>
          await save(ctx, "receive", async () => {
            if (!args.prepared.ok) return args.prepared;
            if (args.prepared.state === "ready") {
              await actions.receiveSnapshot(
                {
                  transport_user: args.target.transport_user,
                  base: args.target.base,
                  path: z.string().parse(args.prepared.incoming),
                },
                args.exported.snapshot,
                ctx,
              );
            }
            return { ok: true, state: args.prepared.state };
          }),
      },
      install: {
        description: "Install or resume the admitted target seed",
        arguments: z.object({
          run_id: z.string().uuid(),
          target: SeedTarget,
          exported: Outcome,
          received: Outcome,
        }),
        execute: async (
          args: { target: unknown; exported: Result; received: Result },
          ctx: Context,
        ) =>
          await save(ctx, "install", async () => {
            if (!args.received.ok) return args.received;
            if (args.received.state === "complete") {
              return { ok: true, state: "complete" };
            }
            const result = await actions.seedTarget(
              args.target,
              "install",
              args.exported.snapshot,
              invoke,
              ctx.signal,
            );
            if (
              result.state !== "changed" && result.state !== "complete"
            ) {
              throw new Error("install incomplete");
            }
            return { ok: true, state: result.state };
          }),
      },
      cleanupSource: {
        description: "Clean the admitted source with the same pinned primitive",
        arguments: z.object({
          source: SnapshotSource,
          run_id: z.string().uuid(),
        }),
        execute: async (args: { source: unknown }, ctx: Context) =>
          await save(ctx, "cleanup-source", async () => {
            await actions.cleanupSnapshot(args.source, invoke);
            return { ok: true, state: "cleaned" };
          }),
      },
      cleanupTarget: {
        description: "Abandon only the admitted target seed attempt",
        arguments: z.object({ target: SeedTarget, run_id: z.string().uuid() }),
        execute: async (args: { target: unknown }, ctx: Context) =>
          await save(ctx, "cleanup-target", async () => {
            const result = await actions.seedTarget(
              args.target,
              "cleanup",
              undefined,
              invoke,
            );
            if (result.state !== "cleaned") {
              throw new Error("cleanup incomplete");
            }
            return { ok: true, state: "cleaned" };
          }),
      },
      project: {
        description: "Project the saved bounded native outcome",
        arguments: Args.extend({ effect: Outcome, cleanup: Outcome }),
        execute: async (
          raw: z.infer<typeof Args> & { effect: Result; cleanup: Result },
          ctx: Context,
        ) => {
          const args = Args.extend({ effect: Outcome, cleanup: Outcome }).parse(
            raw,
          );
          const intent = Intent.parse(await ctx.readResource("intent"));
          const started_at = z.string().datetime().parse(intent.started_at);
          const error = [args.effect, args.cleanup].find((result) => !result.ok)
            ?.error_code ?? null;
          const result = error ? { error_code: error } : {
            backend: args.kind === "database.sqlite_seed_abandon"
              ? "absent"
              : "active",
            ...(args.kind === "database.sqlite_export" && args.effect.snapshot
              ? {
                bytes: args.effect.snapshot.bytes,
                sha256: args.effect.snapshot.sha256,
                ...(args.effect.snapshot.mode === undefined
                  ? {}
                  : { mode: args.effect.snapshot.mode }),
              }
              : {}),
          };
          await new GatewayOperation(makeHttp(ctx), args).project({
            status: intent.cancel_requested
              ? "cancelled"
              : error
              ? "failed"
              : "succeeded",
            result: intent.cancel_requested ? {} : result,
            error_code: intent.cancel_requested ? null : error,
            started_at,
            finished_at: new Date().toISOString(),
          });
          if (error) throw new Error(error);
          return {
            status: intent.cancel_requested ? "cancelled" : "succeeded",
          };
        },
      },
    },
  };
}
export const behavior = sqliteNative();
