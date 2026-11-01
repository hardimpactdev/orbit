import { hasRoleTransport } from "./role_metrics.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
  RemovalStage,
  RoleRemoval,
  roleRemovalLocal,
} from "./role_removal_local.ts";
import { stripRolePublication } from "./role_publication.ts";

const Id = z.number().int().positive();
const Args = DirectArgs.extend({
  node_id: Id,
  kind: z.enum(["node.role_remove", "node.role_relocate"]),
});
const Current = z.object({
  id: Id,
  node_id: Id,
  kind: Args.shape.kind,
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.record(z.string(), z.unknown()),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  extensionFile?(path: string): string;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
const pause = (seconds: number, signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    signal?.throwIfAborted();
    const abort = () => {
      clearTimeout(timer);
      reject(signal?.reason);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", abort);
      resolve();
    }, seconds * 1000);
    signal?.addEventListener("abort", abort, { once: true });
  });
/** Source and target remain placed jobs in one parent, with root-only state callbacks. */
export function roleRemoval(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  local = roleRemovalLocal(),
  sleep = pause,
) {
  const read = async (
    args: z.infer<typeof Args>,
    ctx: Context,
    before = true,
  ) => {
    const gateway = new GatewayOperation(makeHttp(ctx), args);
    const current = Current.parse(
      await gateway.read(before ? ctx.signal : undefined),
    );
    if (
      current.id !== args.operation_id || current.node_id !== args.node_id ||
      current.kind !== args.kind ||
      !["queued", "running"].includes(current.status)
    ) throw new Error("role.cleanup_root_changed");
    if (before && (current.cancel_requested || ctx.signal?.aborted)) {
      throw new Error("operation.cancelled");
    }
    const removal = RoleRemoval.parse(
      args.kind === "node.role_relocate"
        ? current.input.source_removal ?? current.input.role_removal
        : current.input.role_removal ?? stripRolePublication(current.input),
    );
    return { gateway, current, removal };
  };
  return {
    type: "@nckrtl/node-role-removal",
    version: "2026.11.01.1",
    globalArguments: Globals,
    resources: {
      output: {
        schema: z.record(z.string(), z.unknown()),
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      admit: {
        description: "Read the complete online role withdrawal before effects",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const { gateway, removal } = await read(args, ctx);
          if (
            args.kind !== "node.role_remove" || removal.node_id !== args.node_id
          ) throw new Error("role.removal_node_changed");
          const refusal =
            removal.metrics_fleet?.fleet.some((node) =>
                !hasRoleTransport(node, removal.proxy_node_ids)
              )
              ? "metrics.no_worker"
              : null;
          if (refusal) throw new Error(refusal);
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
          });
          const result = { removal, ready: true };
          await ctx.writeResource("output", "admission", result);
          return result;
        },
      },
      inventory: {
        description: "Read exact source and certificate cleanup placements",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const { removal, current } = await read(args, ctx);
          const result = {
            node_id: removal.node_id,
            certificate_nodes: [
              ...new Set(
                removal.certificates.withdrawals.map((entry) => entry.node_id),
              ),
            ],
            grace_seconds: args.kind === "node.role_relocate"
              ? z.union([z.literal(0), z.literal(31)]).parse(
                current.input.withdrawal_grace_seconds,
              )
              : removal.role === "websocket"
              ? 31
              : 0,
          };
          await ctx.writeResource("output", "inventory", result);
          return result;
        },
      },
      withdraw: {
        description:
          "Record source site and DNS withdrawal before fresh native publication",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const { gateway, removal } = await read(args, ctx);
          for (
            const node_id of new Set(
              removal.certificates.withdrawals.map((entry) => entry.node_id),
            )
          ) {
            await gateway.project({
              status: "running",
              started_at: new Date().toISOString(),
              result: {
                publication_phase: { phase: "withdrawal_started", node_id },
              },
            });
          }
          return { ready: true };
        },
      },
      grace: {
        description: "Keep the old runtime alive through the published DNS TTL",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const { removal, current } = await read(args, ctx);
          const seconds = args.kind === "node.role_relocate"
            ? z.union([z.literal(0), z.literal(31)]).parse(
              current.input.withdrawal_grace_seconds,
            )
            : removal.role === "websocket"
            ? 31
            : 0;
          if (seconds) await sleep(seconds, ctx.signal);
          return { ready: true };
        },
      },
      stage: {
        description:
          "Run a fixed cleanup on the exact admitted source or certificate Node",
        arguments: Args.extend({ target_node_id: Id, stage: RemovalStage }),
        execute: async (
          args: z.infer<typeof Args> & {
            target_node_id: number;
            stage: z.infer<typeof RemovalStage>;
          },
          ctx: Context,
        ) => {
          const { removal, current } = await read(args, ctx);
          const allowed = args.stage === "certificates"
            ? removal.certificates.withdrawals.some((entry) =>
              entry.node_id === args.target_node_id
            )
            : removal.node_id === args.target_node_id;
          if (!allowed || current.input.node_id !== args.target_node_id) {
            throw new Error("role.cleanup_participant_changed");
          }
          const result = await local(
            removal,
            args.stage,
            ctx.signal,
            args.target_node_id,
            args.stage === "runtime" && removal.runtime?.role === "swamp"
              ? await Deno.readTextFile(
                ctx.extensionFile?.("fleet-recipe/scripts/swamp_workers.py") ??
                  "",
              )
              : undefined,
          );
          await ctx.writeResource("output", args.stage, result);
          if (!result.ok) {
            throw new Error(result.error_code ?? "node_role.remove_failed");
          }
          return result;
        },
      },
      fail: {
        description:
          "Record failed or cancelled role removal through its owning projection",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const { gateway, current } = await read(args, ctx, false);
          if (args.kind !== "node.role_remove") {
            throw new Error("role.removal_root_changed");
          }
          const error_code = current.cancel_requested
            ? "operation.cancelled"
            : "node_role.remove_failed";
          await gateway.project({
            status: current.cancel_requested ? "cancelled" : "failed",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            error_code,
            result: { removed: false, failed_step: "cleanup", error_code },
          });
          return { ready: false };
        },
      },
      complete: {
        description:
          "Record native source cleanup without deleting a relocation target",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const { gateway, removal } = await read(args, ctx, false);
          for (
            const node_id of new Set(
              removal.certificates.withdrawals.map((entry) => entry.node_id),
            )
          ) {
            await gateway.project({
              status: "running",
              started_at: new Date().toISOString(),
              result: {
                publication_phase: { phase: "withdrawal_completed", node_id },
              },
            });
          }
          await gateway.project(
            args.kind === "node.role_relocate"
              ? {
                status: "running",
                started_at: new Date().toISOString(),
                result: { source_completed: true },
              }
              : {
                status: "succeeded",
                started_at: new Date().toISOString(),
                finished_at: new Date().toISOString(),
                result: { removed: true },
              },
          );
          const result = { ready: true };
          await ctx.writeResource("output", "completion", result);
          return result;
        },
      },
    },
  };
}
export const behavior = roleRemoval();
