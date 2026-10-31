import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
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
const Terminal = z.enum(["succeeded", "failed", "cancelled"]);
const Stage = z.object({
  intent: CloneInput,
  result: Result,
  snapshot: SnapshotRef.nullable(),
  started_at: z.string().datetime(),
  terminal: Terminal.nullable(),
}).strict();
const Args = DirectArgs.extend({
  kind: z.literal("database.clone"),
  node_id: NodeId,
});
const StagedArgs = Args.extend({ stage: Stage });
export type Context = FileContext & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
const stopped = (s: z.infer<typeof Stage>) =>
  s.terminal !== null || "error_code" in s.result;

/** One parent native DAG; source and target share typed native file data, never child Operations. */
export function clone(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = invokeSql,
  files = { exportSnapshot, receiveSnapshot, cleanupSnapshot },
  sqliteInvoke: SqliteInvoke = runSqliteLocal,
) {
  const write = async (
    ctx: Context,
    spec: string,
    args: z.infer<typeof Args>,
    stage: z.infer<typeof Stage>,
  ) => ({
    dataHandles: [
      await ctx.writeResource(
        spec,
        `${spec}-${args.run_id}`,
        Stage.parse(stage),
      ),
    ],
  });
  const step = (
    spec: string,
    action: (
      s: z.infer<typeof Stage>,
      a: z.infer<typeof Args>,
      ctx: Context,
    ) => Promise<void>,
  ) => ({
    description: `Run the admitted clone ${spec} step`,
    arguments: StagedArgs,
    execute: async (raw: z.infer<typeof StagedArgs>, ctx: Context) => {
      try {
        const args = StagedArgs.parse(raw);
        if (args.node_id !== args.stage.intent.node_id) {
          throw new Error("Wrong clone placement");
        }
        const stage = structuredClone(args.stage);
        if (!stopped(stage)) await action(stage, args, ctx);
        return await write(ctx, spec, args, stage);
      } catch {
        throw new Error(`database.clone_${spec}_outcome_unknown`);
      }
    },
  });
  const sqlite = async (
    s: z.infer<typeof Stage>,
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
  return {
    type: "@nckrtl/database-clone",
    version: "2026.10.31.1",
    globalArguments: Globals,
    files: { snapshot: snapshotFile },
    resources: Object.fromEntries(
      ["admitted", "exported", "prepared", "applied", "cleaned"]
        .map((
          name,
        ) => [name, {
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
            const args = Args.parse(raw);
            const gateway = new GatewayOperation(makeHttp(ctx), args);
            const current = z.object({
              id: NodeId,
              kind: z.literal("database.clone"),
              node_id: NodeId,
              status: z.enum([
                "queued",
                "running",
                "succeeded",
                "failed",
                "cancelled",
              ]),
              input: CloneInput,
              result: Result.nullable(),
              cancel_requested: z.boolean(),
            }).parse(await gateway.read(ctx.signal));
            if (
              current.id !== args.operation_id ||
              current.node_id !== args.node_id ||
              current.input.node_id !== args.node_id
            ) {
              throw new Error("Wrong clone identity");
            }
            const started_at = new Date().toISOString();
            const terminal = Terminal.safeParse(current.status);
            const stage = Stage.parse({
              intent: current.input,
              result: terminal.success
                ? current.result ?? {}
                : { backend: "active" },
              snapshot: null,
              started_at,
              terminal: terminal.success ? terminal.data : null,
            });
            if (!terminal.success) {
              await gateway.project({ status: "running", started_at });
              if (current.cancel_requested || ctx.signal?.aborted) {
                stage.terminal = "cancelled";
                stage.result = {};
              }
            }
            return await write(ctx, "admitted", args, stage);
          } catch {
            throw new Error("database.clone_admission_outcome_unknown");
          }
        },
      },
      export: step("exported", async (s, args, ctx) => {
        if (s.intent.variant === "sqlite_remote") {
          s.snapshot = await files.exportSnapshot(
            s.intent.source,
            ctx,
            `snapshot-${args.run_id}`,
            sqliteInvoke,
            ctx.signal,
          );
        }
      }),
      prepare: step("prepared", async (s, args, ctx) => {
        if (s.intent.variant === "mysql") {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
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
      apply: step("applied", async (s, args, ctx) => {
        if (s.intent.variant === "mysql") {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
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
      cleanup: {
        description:
          "Remove the source export without changing the copy result",
        arguments: StagedArgs,
        execute: async (raw: z.infer<typeof StagedArgs>, ctx: Context) => {
          try {
            const args = StagedArgs.parse(raw);
            if (args.node_id !== args.stage.intent.node_id) {
              throw new Error(
                "Wrong clone placement",
              );
            }
            if (
              args.stage.intent.variant === "sqlite_remote" &&
              args.stage.snapshot !== null
            ) {
              await files.cleanupSnapshot(
                args.stage.intent.source,
                sqliteInvoke,
                ctx.signal,
              );
            }
            return await write(ctx, "cleaned", args, args.stage);
          } catch {
            throw new Error("database.clone_cleanup_outcome_unknown");
          }
        },
      },
      project: {
        description: "Record the saved native outcome",
        arguments: StagedArgs,
        execute: async (raw: z.infer<typeof StagedArgs>, ctx: Context) => {
          const args = StagedArgs.parse(raw);
          if (args.node_id !== args.stage.intent.node_id) {
            throw new Error(
              "Wrong clone placement",
            );
          }
          const result = args.stage.result;
          const status = args.stage.terminal ??
            ("error_code" in result ? "failed" : "succeeded");
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          await gateway.project({
            status,
            result,
            error_code: "error_code" in result ? result.error_code : null,
            started_at: args.stage.started_at,
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
