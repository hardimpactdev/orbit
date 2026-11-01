import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
  archiveFile,
  captureTransferArchive,
  materializeTransferArchive,
  TransferArchive,
} from "./transfer_archive.ts";
import {
  assertTransferDestination,
  inspectTransferPath,
  removeTransferPath,
  TransferPathProof,
} from "./transfer_paths.ts";
import {
  readTargetParticipant,
  routeTargetActions,
} from "../routes/target-set.ts";
import { removalActions } from "../routes/removal-local.ts";
import {
  type FileContext,
  snapshotFile,
  SnapshotRef,
} from "../database/sqlite_actions.ts";
import { TransferSqlite, transferSqliteActions } from "./transfer_sqlite.ts";

import { TransferRuntime, transferRuntimeActions } from "./transfer_runtime.ts";

const Args = DirectArgs.extend({
  kind: z.enum(["instance.transfer", "instance.transfer_rollback"]),
  node_id: z.number().int().positive(),
});
const Effect = Args.extend({ target_node_id: z.number().int().positive() });
const Plan = z.object({
  instance_id: z.number().int().positive(),
  transfer_id: z.string().uuid(),
  source_node_id: z.number().int().positive(),
  destination_node_id: z.number().int().positive(),
  source: z.object({ source: z.string(), user: z.string() }).passthrough(),
  destination: z.object({
    destination: z.string(),
    root: z.string(),
    environment: z.string(),
    user: z.string(),
  }).strict(),
  cutover: z.boolean(),
  completed: z.boolean(),
  sqlite: TransferSqlite.nullable().default(null),
});
const Current = z.object({
  id: z.number(),
  kind: Args.shape.kind,
  node_id: z.number(),
  status: z.string(),
  cancel_requested: z.boolean(),
  input: z.object({
    direct_transfer: Plan,
    process_runtime: TransferRuntime,
    target: z.object({
      live_certificates: z.array(
        z.object({ node_id: z.number() }).passthrough(),
      ),
      caddy_node_ids: z.array(z.number()),
      dns_node_ids: z.array(z.number()),
      workload_node_ids: z.array(z.number()),
      node_ids: z.array(z.number()),
    }).passthrough(),
  }),
});
type Args = z.infer<typeof Args>;
type Effect = z.infer<typeof Effect>;
type Context = FileContext & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
  readResource(spec: string): Promise<unknown>;
};
const Archive = z.object({
  archive: TransferArchive,
  source: TransferPathProof,
});
const Target = z.object({ proof: TransferPathProof });
const Decision = z.object({
  recover: z.boolean(),
  cancelled: z.boolean(),
  destination_node_id: z.number(),
  caddy_node_ids: z.array(z.number()),
  dns_node_ids: z.array(z.number()),
});

/** Native filesystem effects only; callbacks carry bounded domain transitions, never checkout bytes. */
export function transferRoot(
  makeHttp = (ctx: Context, _args: Args) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  effects = {
    capture: captureTransferArchive,
    materialize: materializeTransferArchive,
    inspect: inspectTransferPath,
    ready: assertTransferDestination,
    remove: removeTransferPath,
  },
  targetActions = routeTargetActions,
  retirement = removalActions,
  runtime = transferRuntimeActions(),
  sqlite = transferSqliteActions(),
) {
  const gateway = (args: Args, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx, args), args);
  async function read(args: Args, ctx: Context, recovery = false) {
    const value = Current.parse(
      await gateway(args, ctx).read(recovery ? undefined : ctx.signal),
    );
    if (
      value.id !== args.operation_id || value.kind !== args.kind ||
      value.node_id !== args.node_id ||
      value.input.direct_transfer.source_node_id !== args.node_id ||
      !["queued", "running"].includes(value.status)
    ) {
      throw new Error("instance.transfer_intent_invalid");
    }
    if (
      !recovery && !value.input.direct_transfer.cutover &&
      (value.cancel_requested || ctx.signal?.aborted)
    ) {
      throw new Error("operation.cancelled");
    }
    const plan = value.input.direct_transfer;
    if (
      plan.sqlite && (plan.sqlite.owner_instance_id !== plan.instance_id ||
        plan.sqlite.source.node_id !== plan.source_node_id ||
        plan.sqlite.target.node_id !== plan.destination_node_id ||
        plan.sqlite.source.base !== plan.source.source ||
        plan.sqlite.source.source_path !== plan.source.sqlite_source ||
        plan.sqlite.source.source_user !== plan.source.user ||
        plan.sqlite.target.base !== plan.destination.destination ||
        plan.sqlite.target.target_user !== plan.destination.user)
    ) {
      throw new Error("instance.transfer_intent_invalid");
    }
    return value;
  }
  function placed(
    current: z.infer<typeof Current>,
    args: Effect,
    node: number,
  ) {
    if (
      args.target_node_id !== node ||
      !current.input.target.node_ids.includes(node)
    ) throw new Error("instance.transfer_participant_invalid");
  }
  const output = async (
    ctx: Context,
    spec: string,
    name: string,
    value: unknown,
  ) => ({ dataHandles: [await ctx.writeResource(spec, name, value)] });
  const checkpoint = async (args: Args, ctx: Context, name: string) => {
    await gateway(args, ctx).project({
      status: "running",
      started_at: new Date().toISOString(),
      result: { checkpoint: name },
    });
    return { ready: true };
  };
  return {
    type: "@nckrtl/instance-transfer",
    version: "2026.11.01.1",
    globalArguments: Globals,
    files: { archive: archiveFile, snapshot: snapshotFile },
    resources: {
      restoration: { schema: z.object({ ready: z.literal(true), restore: z.boolean() }).strict(), lifetime: "workflow", garbageCollection: 1 },
      sqliteSnapshot: {
        schema: SnapshotRef.nullable(),
        lifetime: "workflow",
        garbageCollection: 1,
      },
      paused: {
        schema: z.object({ attempted: z.literal(true) }),
        lifetime: "workflow",
        garbageCollection: 1,
      },
      inventory: {
        schema: z.object({
          source_node_id: z.number(),
          destination_node_id: z.number(),
          certificate_nodes: z.array(
            z.object({ node_id: z.number(), leaves: z.array(z.unknown()) }),
          ),
          certificates: z.array(z.unknown()),
          caddy_node_ids: z.array(z.number()),
          dns_node_ids: z.array(z.number()),
          workload_node_ids: z.array(z.number()),
        }),
        lifetime: "workflow",
        garbageCollection: 1,
      },
      captured: { schema: Archive, lifetime: "workflow", garbageCollection: 1 },
      destination: {
        schema: Target,
        lifetime: "workflow",
        garbageCollection: 1,
      },
      recovery: {
        schema: Decision,
        lifetime: "workflow",
        garbageCollection: 1,
      },
    },
    methods: {
      admit: {
        arguments: Args,
        description:
          "Read immutable transfer participants on the truthful source root",
        execute: async (args: Args, ctx: Context) => {
          const current = await read(args, ctx);
          const { direct_transfer: plan, target } = current.input;
          await gateway(args, ctx).project({
            status: "running",
            started_at: new Date().toISOString(),
            result: {},
          });
          const nodeIds = [
            ...new Set(target.live_certificates.map((v) => v.node_id)),
          ];
          return output(ctx, "inventory", "inventory", {
            source_node_id: plan.source_node_id,
            destination_node_id: plan.destination_node_id,
            certificates: target.live_certificates,
            certificate_nodes: nodeIds.map((node_id) => ({
              node_id,
              leaves: target.live_certificates.filter((v) =>
                v.node_id === node_id
              ),
            })),
            caddy_node_ids: target.caddy_node_ids,
            dns_node_ids: target.dns_node_ids,
            workload_node_ids: [
              ...new Set([plan.source_node_id, ...target.workload_node_ids]),
            ],
          });
        },
      },
      ready: {
        arguments: Effect,
        description: "Check the destination before pausing or archiving source",
        execute: async (args: Effect, ctx: Context) => {
          const current = await read(args, ctx);
          const plan = current.input.direct_transfer;
          placed(current, args, plan.destination_node_id);
          if (plan.cutover) return { ready: true };
          await runtime.ready(current.input.process_runtime);
          return effects.ready(
            plan.destination.destination,
            plan.destination.root,
            plan.destination.user,
          );
        },
      },
      runtime: {
        arguments: Effect.extend({
          phase: z.enum([
            "pause",
            "prepare",
            "verify",
            "restore",
            "retire",
          ]),
        }),
        description:
          "Apply the captured Process batch on its admitted placement without child Operations",
        execute: async (
          args: Effect & {
            phase:
              | "pause"
              | "prepare"
              | "verify"
              | "restore"
              | "retire";
          },
          ctx: Context,
        ) => {
          const recovery = args.phase === "restore";
          const current = await read(args, ctx, recovery);
          const plan = current.input.direct_transfer;
          const source = ["pause", "restore", "retire"].includes(args.phase);
          placed(
            current,
            args,
            source ? plan.source_node_id : plan.destination_node_id,
          );
          const batch = current.input.process_runtime;
          if (batch.node_id !== args.target_node_id) {
            throw new Error("instance.transfer_runtime_invalid");
          }
          if (args.phase === "restore") {
            if (plan.cutover) {
              return output(ctx, "restoration", "restoration", { ready: true, restore: false });
            }
            let paused: unknown;
            try {
              paused = await ctx.readResource("paused");
            } catch {
              return output(ctx, "restoration", "restoration", { ready: true, restore: false });
            }
            if (!paused) return output(ctx, "restoration", "restoration", { ready: true, restore: false });
            z.object({ attempted: z.literal(true) }).strict().parse(
              paused,
            );
            await runtime.prepare(batch);
            return output(ctx, "restoration", "restoration", { ready: true, restore: true });
          } else if (args.phase === "pause") {
            if (plan.cutover) {
              throw new Error("instance.transfer_already_cutover");
            }
            await output(ctx, "paused", "paused", { attempted: true });
            await runtime.pause(batch, ctx.signal);
          } else {
            if (!plan.cutover) {
              throw new Error("instance.transfer_not_cutover");
            }
            if (args.phase === "prepare") {
              await runtime.prepare(batch);
            }
            if (args.phase === "verify") {
              await runtime.verify(batch);
            }
            if (args.phase === "retire") {
              await runtime.retire(batch);
            }
          }
          return { ready: true };
        },
      },
      sqlite: {
        arguments: Effect.extend({
          phase: z.enum([
            "export",
            "install",
            "cleanup-source",
            "cleanup-target",
          ]),
        }),
        description:
          "Snapshot and seed SQLite on admitted participants; cleanup survives cancellation",
        execute: async (
          args: Effect & {
            phase:
              | "export"
              | "install"
              | "cleanup-source"
              | "cleanup-target";
          },
          ctx: Context,
        ) => {
          const cleanup = args.phase.startsWith("cleanup-");
          const current = await read(args, ctx, cleanup);
          const plan = current.input.direct_transfer;
          const source = args.phase === "export" ||
            args.phase === "cleanup-source";
          placed(
            current,
            args,
            source ? plan.source_node_id : plan.destination_node_id,
          );
          if (cleanup) {
            if (plan.sqlite) {
              if (source) await sqlite.cleanupSource(plan.sqlite);
              else await sqlite.cleanupTarget(plan.sqlite);
            }
            return { ready: true };
          }
          if (plan.cutover) {
            throw new Error("instance.transfer_already_cutover");
          }
          if (args.phase === "export") {
            const snapshot = plan.sqlite
              ? await sqlite.export(plan.sqlite, ctx, args.run_id, ctx.signal)
              : null;
            return output(ctx, "sqliteSnapshot", "sqliteSnapshot", snapshot);
          }
          if (plan.sqlite) {
            const snapshot = SnapshotRef.parse(
              await ctx.readResource("sqliteSnapshot"),
            );
            if (
              snapshot.model_id !== ctx.modelId ||
              snapshot.name !== `snapshot-${args.run_id}`
            ) {
              throw new Error("database.sqlite_snapshot_invalid");
            }
            await sqlite.install(
              plan.sqlite,
              snapshot,
              ctx,
              () => read(args, ctx),
              ctx.signal,
            );
            await read(args, ctx);
            await sqlite.environment(
              plan.sqlite,
              plan.destination.environment,
              ctx.signal,
            );
          }
          return { ready: true };
        },
      },
      capture: {
        arguments: Args,
        description:
          "Capture a private native archive with independent Git state",
        execute: async (args: Args, ctx: Context) => {
          const current = await read(args, ctx);
          const plan = current.input.direct_transfer;
          if (plan.cutover) {
            throw new Error(
              "instance.transfer_already_cutover",
            );
          }
          const source = await effects.inspect(
            plan.source.source,
            plan.source.user,
            ctx.signal,
          );
          const archive = await effects.capture(
            plan.source,
            args.run_id,
            ctx,
            ctx.signal,
          );
          return output(ctx, "captured", "captured", { archive, source });
        },
      },
      materialize: {
        arguments: Effect.extend({ captured: Archive }),
        description:
          "Verify and receive the archive on the admitted destination",
        execute: async (
          args: Effect & { captured: z.infer<typeof Archive> },
          ctx: Context,
        ) => {
          const current = await read(args, ctx);
          const plan = current.input.direct_transfer;
          placed(current, args, plan.destination_node_id);
          await effects.materialize(
            plan.destination,
            args.captured.archive,
            ctx,
            ctx.signal,
          );
          return output(ctx, "destination", "destination", {
            proof: await effects.inspect(
              plan.destination.destination,
              plan.destination.user,
              ctx.signal,
            ),
          });
        },
      },
      cutover: {
        arguments: Args,
        description:
          "Commit Instance and Route placement atomically before publication",
        execute: async (args: Args, ctx: Context) => {
          await read(args, ctx);
          return checkpoint(args, ctx, "cutover");
        },
      },
      route: {
        arguments: Effect.extend({
          phase: z.enum(["prepare", "environment", "lan", "retire"]),
        }),
        description:
          "Apply canonical Route lower actions against fresh committed placement",
        execute: async (
          args: Effect & {
            phase: "prepare" | "environment" | "lan" | "retire";
          },
          ctx: Context,
        ) => {
          const current = await read(args, ctx);
          const committed = current.input.direct_transfer.cutover;
          const view = await readTargetParticipant(
            gateway(args, ctx),
            args,
            committed ? undefined : ctx.signal,
            { recovery: committed },
          );
          const signal = committed ? undefined : ctx.signal;
          const actions = targetActions();
          if (args.phase === "prepare") {
            return actions.prepare(view, signal);
          }
          if (args.phase === "lan") return actions.lan(view, signal);
          if (args.phase === "environment") {
            await gateway(args, ctx).withPrivateMaterial(async (material) => {
              await actions.synchronizeEnvironment(view, material, signal);
            });
            return { ready: true };
          }
          const raw = z.object({
            input: z.object({
              route_retire: z.record(z.string(), z.unknown()).nullable(),
            }),
          }).parse(await gateway(args, ctx).read());
          if (raw.input.route_retire) {
            const removal = retirement();
            await removal.certificates(raw.input.route_retire, signal);
            await removal.lan(raw.input.route_retire);
          }
          return { ready: true };
        },
      },
      removeSource: {
        arguments: Args.extend({ captured: Archive }),
        description:
          "Remove only the proven old checkout after destination publication",
        execute: async (
          args: Args & { captured: z.infer<typeof Archive> },
          ctx: Context,
        ) => {
          const current = await read(args, ctx);
          if (!current.input.direct_transfer.cutover) {
            throw new Error("instance.transfer_not_cutover");
          }
          return effects.remove(args.captured.source, ctx.signal);
        },
      },
      retire: {
        arguments: Args,
        description: "Retire replaced Route records after source effects",
        execute: async (args: Args, ctx: Context) => {
          await read(args, ctx);
          return checkpoint(args, ctx, "retire");
        },
      },
      recover: {
        arguments: Args,
        description:
          "Choose pre-cutover restoration; committed transfers recover forward",
        execute: async (args: Args, ctx: Context) => {
          const current = await read(args, ctx, true);
          return output(ctx, "recovery", "recovery", {
            recover: !current.input.direct_transfer.cutover,
            cancelled: current.cancel_requested,
            destination_node_id:
              current.input.direct_transfer.destination_node_id,
            caddy_node_ids: current.input.target.caddy_node_ids,
            dns_node_ids: current.input.target.dns_node_ids,
          });
        },
      },
      rollback: {
        arguments: Args.extend({ decision: Decision }),
        description:
          "Drop only pre-cutover reservations after source restoration",
        execute: async (
          args: Args & { decision: z.infer<typeof Decision> },
          ctx: Context,
        ) => {
          if (!args.decision.recover) return { ready: true };
          await read(args, ctx, true);
          return checkpoint(args, ctx, "rollback");
        },
      },
      removeCandidateCertificates: {
        arguments: Effect.extend({ decision: Decision }),
        description:
          "Remove only admitted candidate leaves unused by fresh stored source sites",
        execute: async (
          args: Effect & { decision: z.infer<typeof Decision> },
          ctx: Context,
        ) => {
          const current = await read(args, ctx, true);
          if (!args.decision.recover) return { ready: true };
          placed(current, args, args.target_node_id);
          const value = z.object({
            input: z.object({
              route_rollback: z.record(z.string(), z.unknown()).nullable(),
            }),
          }).parse(await gateway(args, ctx).read());
          if (value.input.route_rollback) {
            await retirement().certificates(value.input.route_rollback);
          }
          return { ready: true };
        },
      },
      discard: {
        arguments: Effect.extend({ decision: Decision }),
        description:
          "Discard only the proven destination after restoring source",
        execute: async (
          args: Effect & { decision: z.infer<typeof Decision> },
          ctx: Context,
        ) => {
          const current = await read(args, ctx, true);
          placed(
            current,
            args,
            current.input.direct_transfer.destination_node_id,
          );
          if (!args.decision.recover) return { removed: false };
          let saved: unknown;
          try {
            saved = await ctx.readResource("destination");
          } catch {
            return { removed: false };
          }
          const target = Target.parse(saved);
          return effects.remove(target.proof);
        },
      },
      finish: {
        arguments: Args.extend({
          failed: z.boolean().default(false),
          decision: Decision.optional(),
        }),
        description:
          "Project the final native transfer outcome on the root only",
        execute: async (
          args: Args & {
            failed: boolean;
            decision?: z.infer<typeof Decision>;
          },
          ctx: Context,
        ) => {
          const current = await read(args, ctx, true);
          const cancelled = args.failed && current.cancel_requested;
          const status = args.failed
            ? (cancelled ? "cancelled" : "failed")
            : "succeeded";
          const error_code = args.failed
            ? (cancelled ? "operation.cancelled" : "instance.transfer_failed")
            : null;
          const now = new Date().toISOString();
          await gateway(args, ctx).project({
            status,
            started_at: now,
            finished_at: now,
            error_code,
            result: args.failed
              ? { error_code, recovered: args.decision?.recover === true }
              : {},
          });
          if (args.failed) throw new Error(error_code!);
          return { ready: true };
        },
      },
    },
  };
}
export const behavior = transferRoot();
