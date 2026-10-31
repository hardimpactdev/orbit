import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import { invokeSql } from "./local_sql.ts";
import { runSqliteLocal, type SqliteInvoke } from "./sqlite_local.ts";
import {
  copyMysqlClone,
  MysqlCloneInput,
  prepareMysqlClone,
} from "./clone_mysql.ts";
import {
  cleanupSnapshot,
  CloneSource,
  exportSnapshot,
  type FileContext,
  receiveSnapshot,
  snapshotFile,
  SnapshotRef,
  SqlitePath,
} from "./sqlite_actions.ts";

const NodeId = z.number().int().positive();
const Target = z.object({
  node_id: NodeId,
  transport_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  checkout: SqlitePath,
  target: SqlitePath,
  incoming: z.string().regex(/\/\.orbit-sqlite-[a-f0-9]{32}$/),
}).strict().refine((i) =>
  i.target.startsWith(i.checkout + "/") &&
  i.incoming.slice(0, i.incoming.lastIndexOf("/")) ===
    i.target.slice(0, i.target.lastIndexOf("/"))
);
export const CloneInput = z.discriminatedUnion("variant", [
  z.object({
    variant: z.literal("mysql"),
    node_id: NodeId,
    source_node_id: NodeId,
    mysql: MysqlCloneInput,
  }).strict().refine((i) => i.source_node_id === i.node_id),
  z.object({
    variant: z.enum(["sqlite_local", "sqlite_remote"]),
    node_id: NodeId,
    source_node_id: NodeId,
    source: CloneSource,
    target: Target,
  }).strict().refine((i) =>
    i.node_id === i.target.node_id && i.source_node_id === i.source.node_id &&
    (i.variant === "sqlite_local") === (i.node_id === i.source_node_id) &&
    i.source.source.slice(i.source.checkout.length) ===
      i.target.target.slice(i.target.checkout.length) &&
    (i.source_node_id !== i.node_id || i.source.source !== i.target.target)
  ),
]);
const Result = z.union([
  z.object({ backend: z.literal("active") }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
  z.object({}).strict(),
]);
const Args = DirectArgs.extend({
  kind: z.literal("database.clone"),
  node_id: NodeId,
});
const Placed = Args.extend({ selected_node_id: NodeId });
const Stage = z.object({
  binding: Args,
  intent: CloneInput,
  result: Result,
  snapshot: SnapshotRef.nullable(),
  started_at: z.string().datetime(),
  terminal: z.literal("cancelled").nullable(),
}).strict();
type Saved = z.infer<typeof Stage>;
export type Context = FileContext & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
const stopped = (s: Saved) => s.terminal !== null || "error_code" in s.result;

/** One parent run shares safe native checkpoints across its exact placed Nodes. */
export function clone(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = invokeSql,
  files = { exportSnapshot, receiveSnapshot, cleanupSnapshot },
  sqliteInvoke: SqliteInvoke = runSqliteLocal,
) {
  const read = async (ctx: Context, name: string, run: string) => {
    const value = await ctx.readResource(`${name}-${run}`);
    return value === null ? null : Stage.parse(value);
  };
  const saved = async (
    ctx: Context,
    name: string,
    args: z.infer<typeof Args>,
  ) => {
    const stage = Stage.parse(await read(ctx, name, args.run_id));
    if (JSON.stringify(stage.binding) !== JSON.stringify(Args.parse(args))) {
      throw new Error("Wrong clone binding");
    }
    return stage;
  };
  const write = async (ctx: Context, spec: string, stage: Saved) => ({
    dataHandles: [
      await ctx.writeResource(
        spec,
        `${spec}-${stage.binding.run_id}`,
        Stage.parse(stage),
      ),
    ],
  });
  const placed = (
    args: z.infer<typeof Placed>,
    stage: Saved,
    source = false,
  ) => {
    if (
      args.selected_node_id !==
        (source ? stage.intent.source_node_id : stage.intent.node_id)
    ) throw new Error("Wrong clone placement");
  };
  const step = (
    spec: string,
    previous: string,
    action: (s: Saved, ctx: Context) => Promise<void>,
    source = false,
  ) => ({
    description: `Run the admitted clone ${spec} step`,
    arguments: Placed,
    execute: async (raw: z.infer<typeof Placed>, ctx: Context) => {
      try {
        const args = Placed.parse(raw),
          stage = await saved(ctx, previous, args);
        placed(args, stage, source);
        if (!stopped(stage) && ctx.signal?.aborted) {
          stage.terminal = "cancelled";
          stage.result = {};
        } else if (!stopped(stage)) {
          const current = z.object({
            id: z.literal(args.operation_id),
            kind: z.literal(args.kind),
            node_id: z.literal(args.node_id),
            status: z.enum(["queued", "running"]),
            input: CloneInput,
            cancel_requested: z.boolean(),
          }).parse(
            await new GatewayOperation(makeHttp(ctx), args).read(),
          );
          if (JSON.stringify(current.input) !== JSON.stringify(stage.intent)) {
            throw new Error("Changed clone intent");
          }
          if (current.cancel_requested || ctx.signal?.aborted) {
            stage.terminal = "cancelled";
            stage.result = {};
          } else await action(stage, ctx);
        }
        return await write(ctx, spec, stage);
      } catch {
        throw new Error(`database.clone_${spec}_outcome_unknown`);
      }
    },
  });
  const sqlite = async (
    s: Saved,
    operation: "prepare" | "local" | "install",
    ctx: Context,
  ) => {
    if (s.intent.variant === "mysql") throw new Error("Wrong SQLite intent");
    const { source, target } = s.intent;
    const input = operation === "prepare"
      ? { operation, target_checkout: target.checkout, target: target.target }
      : operation === "local"
      ? {
        operation,
        checkout: source.checkout,
        source: source.source,
        target_checkout: target.checkout,
        target: target.target,
      }
      : {
        operation,
        target_checkout: target.checkout,
        target: target.target,
        incoming: target.incoming,
        bytes: SnapshotRef.parse(s.snapshot).bytes,
        sha256: SnapshotRef.parse(s.snapshot).sha256,
        mode: z.number().int().min(0).max(0o7777).parse(
          SnapshotRef.parse(s.snapshot).mode,
        ),
      };
    const reply = await sqliteInvoke({ action: "copy", input }, ctx.signal);
    if ("error_code" in reply) s.result = Result.parse(reply);
    else if (operation === "local") {
      z.object({
        bytes: z.number().int().min(16).max(2 ** 40),
        sha256: z.string().regex(/^[a-f0-9]{64}$/),
        mode: z.number().int().min(0).max(0o7777),
        copy: z.enum(["reflink", "snapshot"]),
      }).strict().parse(reply);
    } else z.object({}).strict().parse(reply);
  };
  const cleanup = (target: boolean) => ({
    description: "Clean only admitted clone scratch after all started work",
    arguments: Placed,
    execute: async (raw: z.infer<typeof Placed>, ctx: Context) => {
      const args = Placed.parse(raw),
        admitted = await saved(ctx, "admitted", args);
      placed(args, admitted, !target);
      const stage = await read(ctx, "exported", args.run_id) ?? admitted;
      try {
        if (
          stage.intent.variant === "sqlite_remote" &&
          (!stopped(stage) || stage.snapshot !== null)
        ) {
          if (target) {
            const prepared = await read(ctx, "prepared", args.run_id);
            const applied = await read(ctx, "applied", args.run_id);
            // Receive starts only after prepare succeeds and apply passes cancellation.
            if (
              !prepared || stopped(prepared) ||
              applied?.terminal === "cancelled"
            ) {
              return await write(ctx, "target_cleaned", stage);
            }
            const t = stage.intent.target;
            const reply = await sqliteInvoke({
              action: "copy",
              input: {
                operation: "remove",
                checkout: t.checkout,
                path: t.incoming,
              },
            });
            if ("error_code" in reply) {
              throw new Error("Incoming cleanup refused");
            }
          } else await files.cleanupSnapshot(stage.intent.source, sqliteInvoke);
        }
      } catch {
        stage.result = { error_code: "database.clone_cleanup_outcome_unknown" };
      }
      return await write(ctx, target ? "target_cleaned" : "cleaned", stage);
    },
  });
  return {
    type: "@nckrtl/database-clone",
    version: "2026.10.31.2",
    globalArguments: Globals,
    files: { snapshot: snapshotFile },
    resources: Object.fromEntries(
      [
        "admitted",
        "exported",
        "prepared",
        "applied",
        "target_cleaned",
        "cleaned",
      ].map((name) => [name, {
        description: "Safe clone intent and native step outcome",
        schema: Stage,
        lifetime: "workflow",
        garbageCollection: 1,
      }]),
    ),
    methods: {
      admit: {
        description: "Read the admitted clone and publish safe Node placements",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          try {
            const args = Args.parse(raw),
              gateway = new GatewayOperation(makeHttp(ctx), args);
            const current = z.object({
              id: z.literal(args.operation_id),
              kind: z.literal(args.kind),
              node_id: z.literal(args.node_id),
              status: z.enum(["queued", "running"]),
              input: CloneInput,
              cancel_requested: z.boolean(),
            }).parse(await gateway.read(ctx.signal));
            if (current.input.node_id !== args.node_id) {
              throw new Error("Wrong clone identity");
            }
            const started_at = new Date().toISOString();
            await gateway.project({ status: "running", started_at });
            const cancelled = current.cancel_requested || ctx.signal?.aborted;
            return await write(
              ctx,
              "admitted",
              Stage.parse({
                binding: args,
                intent: current.input,
                result: cancelled ? {} : { backend: "active" },
                snapshot: null,
                started_at,
                terminal: cancelled ? "cancelled" : null,
              }),
            );
          } catch {
            throw new Error("database.clone_admission_outcome_unknown");
          }
        },
      },
      export: step("exported", "admitted", async (s, ctx) => {
        if (s.intent.variant === "sqlite_remote") {
          s.snapshot = await files.exportSnapshot(
            s.intent.source,
            ctx,
            `snapshot-${s.binding.run_id}`,
            sqliteInvoke,
            ctx.signal,
          );
        }
      }, true),
      prepare: step("prepared", "exported", async (s, ctx) => {
        if (s.intent.variant === "mysql") {
          const gateway = new GatewayOperation(makeHttp(ctx), s.binding);
          s.result = await prepareMysqlClone(
            s.intent.mysql,
            (use) => gateway.withPrivateMaterial(use),
            ctx.signal,
            invoke,
          );
        } else if (s.intent.variant === "sqlite_remote") {
          await sqlite(s, "prepare", ctx);
        }
      }),
      apply: step("applied", "prepared", async (s, ctx) => {
        if (s.intent.variant === "mysql") {
          const gateway = new GatewayOperation(makeHttp(ctx), s.binding);
          s.result = await copyMysqlClone(
            s.intent.mysql,
            (use) => gateway.withPrivateMaterial(use),
            ctx.signal,
            invoke,
          );
        } else {
          if (s.intent.variant === "sqlite_remote") {
            const t = s.intent.target;
            await files.receiveSnapshot(
              {
                transport_user: t.transport_user,
                base: t.checkout,
                path: t.incoming,
              },
              SnapshotRef.parse(s.snapshot),
              ctx,
            );
          }
          await sqlite(
            s,
            s.intent.variant === "sqlite_local" ? "local" : "install",
            ctx,
          );
        }
      }),
      cleanupTarget: cleanup(true),
      cleanup: cleanup(false),
      project: {
        description:
          "Record the saved copy outcome after both scratch cleanups",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw),
            admitted = await saved(ctx, "admitted", args);
          const applied = await read(ctx, "applied", args.run_id);
          const target = await read(ctx, "target_cleaned", args.run_id),
            source = await read(ctx, "cleaned", args.run_id);
          const failure = [applied, target, source].find((s) =>
            s && "error_code" in s.result
          )?.result;
          const result = failure ??
            (!applied || !target || !source
              ? { error_code: "database.clone_native_failed" }
              : applied.result);
          const status = "error_code" in result
            ? "failed"
            : applied?.terminal ?? "succeeded";
          await new GatewayOperation(makeHttp(ctx), args).project({
            status,
            result,
            error_code: "error_code" in result ? result.error_code : null,
            started_at: admitted.started_at,
            finished_at: new Date().toISOString(),
          });
          if (status === "failed") throw new Error("database.clone_failed");
          return { status };
        },
      },
    },
  };
}
export const behavior = clone();
