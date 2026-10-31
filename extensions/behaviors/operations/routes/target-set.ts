import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import { convergeFirewall, convergeRouteLan } from "./firewall-local.ts";
import { verifyLocal } from "./verification.ts";
import { certificateActions } from "../certificates/actions.ts";
import { Leaf, Removal } from "../certificates/input.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Args = DirectArgs.extend({
  kind: z.string(),
  node_id: z.number().int().positive(),
});
const Effect = DirectArgs.extend({
  node_id: z.number().int().positive(),
  target_node_id: z.number().int().positive(),
});
const Ids = z.array(z.number().int().positive());
const Member = z.object({
  route_id: z.number().int().positive(),
  instance_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  production: z.boolean(),
  certificate_scope: z.string(),
}).passthrough();
export const TargetIntent = z.object({
  route_id: z.number().int().positive(),
  mode: z.enum(["target", "targets", "targets_recover", "state_only"]),
  binding_only: z.boolean(),
  noop: z.boolean(),
  recover_forward: z.boolean(),
  prepared: z.array(Member),
  workloads: z.array(Member),
  staging_certificates: z.array(Leaf),
  live_certificates: z.array(Leaf),
  certificates: z.array(Leaf),
  staging_removals: z.array(Removal),
  rollback_lan: z.array(
    z.object({
      node_id: z.number().int().positive(),
      operation: z.literal("remove"),
      route_id: z.number().int().positive(),
    }).strict(),
  ).default([]),
  retire_lan: z.array(
    z.object({
      node_id: z.number().int().positive(),
      operation: z.literal("remove"),
      route_id: z.number().int().positive(),
    }).strict(),
  ).default([]),
  node_ids: Ids,
  workload_node_ids: Ids,
  router_node_ids: Ids,
  dns_node_ids: Ids,
  caddy_node_ids: Ids,
  vacated_route_ids: Ids,
  removals: z.array(
    z.object({
      instance_id: z.number().int().positive(),
      node_id: z.number().int().positive(),
    }).passthrough(),
  ),
  proposal: ObjectInput,
});
const Parent = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  status: z.string(),
  cancel_requested: z.boolean(),
  input: z.object({
    target: TargetIntent,
    environment_fields: z.record(z.string(), z.string()).default({}),
    publication: z.object({
      node_id: z.number().int().positive(),
      agent_sha256: z.string().optional(),
    }).passthrough(),
    workloads: z.array(Member),
    probes: z.array(ObjectInput).default([]),
    firewall: ObjectInput.nullable().optional(),
  }),
});
export type RouteTargetRoot = z.infer<typeof DirectArgs> & { node_id: number };
type Root = z.infer<typeof Args>;
export type RouteTargetEffect = z.infer<typeof Effect>;
type NodeArgs = RouteTargetEffect;
export type RouteTargetView = z.infer<typeof Parent>["input"];
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};

/** Read only the public view selected for this authenticated, admitted effect Node. */
export async function readTargetParticipant(
  gateway: Pick<GatewayOperation, "read">,
  args: NodeArgs,
  signal?: AbortSignal,
  options: { recovery?: boolean } = {},
) {
  const current = Parent.parse(
    await gateway.read(options.recovery ? undefined : signal),
  );
  if (
    current.id !== args.operation_id || current.kind !== args.kind ||
    current.node_id !== args.node_id ||
    current.input.publication.node_id !== args.target_node_id ||
    !current.input.target.node_ids.includes(args.target_node_id)
  ) throw new Error("route.target_participant_invalid");
  if (
    current.input.workloads.some((member) =>
      member.node_id !== args.target_node_id
    )
  ) throw new Error("route.target_workload_invalid");
  if (!options.recovery && (current.cancel_requested || signal?.aborted)) {
    throw new Error("operation.cancelled");
  }
  if (!["queued", "running"].includes(current.status)) {
    throw new Error("route.target_finished");
  }
  return current.input;
}
/** A bounded domain transition only; the composing root owns its projection. */
export async function routeTargetTransition(
  gateway: Pick<GatewayOperation, "project">,
  checkpoint: string,
) {
  z.string().regex(/^[a-z][a-z0-9-]{0,99}$/).parse(checkpoint);
  await gateway.project({ status: "running", result: { checkpoint } });
  return { ready: true };
}

/** Local effects shared by target mutation and Instance transfer in their own root. */
export function routeTargetActions(
  workloads: Pick<
    ReturnType<typeof routeWorkloadLocal>,
    "prepareRuntime" | "grantSourceAccess" | "synchronizeEnvironment"
  > = routeWorkloadLocal(),
  network: {
    lan: (raw: unknown) => Promise<Record<string, unknown>>;
    firewall: (raw: unknown) => Promise<Record<string, unknown>>;
    verify: (raw: unknown) => Promise<Record<string, unknown>>;
  } = {
    lan: convergeRouteLan,
    firewall: convergeFirewall,
    verify: verifyLocal,
  },
  certificates = certificateActions(),
) {
  const success = (value: Record<string, unknown>) => {
    if (value.error_code !== undefined) {
      throw new Error(String(value.error_code));
    }
  };
  return {
    async prepare(view: RouteTargetView, signal?: AbortSignal) {
      for (const member of view.workloads) {
        signal?.throwIfAborted();
        if (member.production) {
          success(await workloads.prepareRuntime(member, signal));
        } else if (member.source_access != null) {
          success(await workloads.grantSourceAccess(member.source_access));
        }
      }
      return { ready: true };
    },
    async lan(view: RouteTargetView, signal?: AbortSignal) {
      for (const member of view.workloads) {
        signal?.throwIfAborted();
        if (member.lan != null) success(await network.lan(member.lan));
      }
      return { ready: true };
    },
    async firewall(view: RouteTargetView, signal?: AbortSignal) {
      signal?.throwIfAborted();
      if (view.firewall) success(await network.firewall(view.firewall));
      return { ready: true };
    },
    async verify(view: RouteTargetView, signal?: AbortSignal) {
      for (const probe of view.probes) {
        signal?.throwIfAborted();
        success(await network.verify(probe));
      }
      return { ready: true };
    },
    async synchronizeEnvironment(
      view: RouteTargetView,
      material: Record<string, unknown>,
      signal?: AbortSignal,
    ) {
      const nodeId = view.publication.node_id;
      const field = view.environment_fields[String(nodeId)];
      if (!field) return { ready: true };
      const owners = new Set(
        view.workloads.map((member) => member.instance_id),
      );
      const writes = z.array(
        z.object({
          instance_id: z.number().int().positive(),
          name: z.enum([
            "instance.write-environment",
            "instance.write-directory-url",
            "instance.write-development-url",
          ]),
          input: ObjectInput,
        }).strict(),
      ).parse(material[field]);
      if (writes.some((write) => !owners.has(write.instance_id))) {
        throw new Error("route.environment_owner_invalid");
      }
      signal?.throwIfAborted();
      success(await workloads.synchronizeEnvironment(writes));
      return { ready: true };
    },
    async cleanup(
      view: RouteTargetView,
      rollback: boolean,
      signal?: AbortSignal,
    ) {
      const nodeId = view.publication.node_id;
      for (
        const rule of rollback
          ? view.target.rollback_lan
          : view.target.retire_lan
      ) {
        if (rule.node_id !== nodeId) continue;
        signal?.throwIfAborted();
        const { node_id: _node, ...input } = rule;
        success(await network.lan(input));
      }
      for (const leaf of view.target.staging_removals) {
        if (leaf.node_id !== nodeId) continue;
        signal?.throwIfAborted();
        success(await certificates.remove(leaf, signal));
      }
      return { ready: true };
    },
  };
}

export function targetSet(
  makeHttp: (ctx: Context, args: RouteTargetRoot) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  workloads: Pick<
    ReturnType<typeof routeWorkloadLocal>,
    "prepareRuntime" | "grantSourceAccess" | "synchronizeEnvironment"
  > = routeWorkloadLocal(),
  lan: (raw: unknown) => Promise<Record<string, unknown>> = convergeRouteLan,
  verify = verifyLocal,
  destroy?: (
    inventory: Record<string, unknown>,
    gateway: GatewayOperation,
    signal?: AbortSignal,
  ) => Promise<Record<string, unknown>>,
) {
  const gateway = (args: RouteTargetRoot, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx, args), args);
  const read = (args: NodeArgs, ctx: Context, recovery = false) =>
    readTargetParticipant(gateway(args, ctx), args, ctx.signal, { recovery });
  const success = (value: Record<string, unknown>) => {
    if (value.error_code !== undefined) {
      throw new Error(String(value.error_code));
    }
  };
  const actions = routeTargetActions(workloads, {
    lan,
    firewall: convergeFirewall,
    verify,
  });
  return {
    type: "@nckrtl/route-target-set",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      admission: {
        schema: z.object({ intent: TargetIntent }),
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: {
      admit: {
        description: "Read the admitted singular or production target mutation",
        arguments: Args,
        execute: async (args: Root, ctx: Context) => {
          const current = Parent.parse(
            await gateway(args, ctx).read(ctx.signal),
          );
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) throw new Error("route.target_root_invalid");
          if (!["queued", "running"].includes(current.status)) {
            throw new Error("route.target_finished");
          }
          if (!current.cancel_requested) {
            await gateway(args, ctx).project({
              status: "running",
              started_at: new Date().toISOString(),
            });
          }
          return {
            dataHandles: [
              await ctx.writeResource("admission", `target-${args.run_id}`, {
                intent: current.cancel_requested
                  ? { ...current.input.target, noop: true, binding_only: true }
                  : current.input.target,
              }),
            ],
          };
        },
      },
      checkpoint: {
        description:
          "Project the database checkpoint chosen by native dependencies",
        arguments: DirectArgs.extend({
          checkpoint: z.enum([
            "preparations-published",
            "rollback-preparations",
            "database-committed",
            "environment-synchronized",
            "live-certificates",
            "router-published",
            "vacated-published",
            "removal",
            "rollback-associations",
          ]),
        }),
        execute: (
          args: RouteTargetRoot & { checkpoint: string },
          ctx: Context,
        ) => routeTargetTransition(gateway(args, ctx), args.checkpoint),
      },
      prepare: {
        description: "Prepare local workload runtime or singular source access",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) => {
          const current = await read(args, ctx);
          return await actions.prepare(current, ctx.signal);
        },
      },
      lan: {
        description: "Converge admitted workload Router LAN rules locally",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) =>
          actions.lan(await read(args, ctx), ctx.signal),
      },
      firewall: {
        description: "Converge fresh admitted role firewall rules locally",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) =>
          actions.firewall(await read(args, ctx), ctx.signal),
      },
      verify: {
        description: "Probe prepared TLS from the admitted serving hop",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) =>
          actions.verify(await read(args, ctx), ctx.signal),
      },
      synchronizeEnvironment: {
        description:
          "Write bound environments only inside the private callback",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) => {
          const current = await read(args, ctx);
          if (!current.environment_fields[String(args.target_node_id)]) {
            return { ready: true };
          }
          await gateway(args, ctx).withPrivateMaterial(async (material) => {
            await actions.synchronizeEnvironment(current, material, ctx.signal);
          });
          return { ready: true };
        },
      },
      restoreEnvironment: {
        description:
          "Restore authoritative environment after the root rolled back its domain state",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) => {
          const current = await read(args, ctx, true);
          if (!current.environment_fields[String(args.target_node_id)]) {
            return { ready: true };
          }
          await gateway(args, ctx).withPrivateMaterial(async (material) => {
            await actions.synchronizeEnvironment(current, material);
          });
          return { ready: true };
        },
      },
      restoreFirewall: {
        description:
          "Restore fresh firewall rules during cancellation or failure recovery",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) =>
          actions.firewall(await read(args, ctx, true)),
      },
      cleanup: {
        description:
          "Retire only admitted LAN rules and staging leaves after Caddy withdrawal",
        arguments: Effect.extend({ rollback: z.boolean().default(false) }),
        execute: async (args: NodeArgs & { rollback: boolean }, ctx: Context) =>
          actions.cleanup(
            await read(args, ctx, args.rollback),
            args.rollback,
            args.rollback ? undefined : ctx.signal,
          ),
      },
      removeDispositions: {
        description:
          "Execute admitted Instance removal dispositions through the lower native cleanup",
        arguments: Effect,
        execute: async (args: NodeArgs, ctx: Context) => {
          const current = await read(args, ctx);
          for (const inventory of current.target.removals) {
            if (inventory.node_id !== args.target_node_id) continue;
            if (!destroy) throw new Error("route.target_cleanup_unavailable");
            success(await destroy(inventory, gateway(args, ctx), ctx.signal));
          }
          return { ready: true };
        },
      },
      finish: {
        description:
          "Project the native target-set outcome without replaying effects",
        arguments: Args.extend({
          failed: z.boolean(),
          cancelled: z.boolean().default(false),
        }),
        execute: async (
          args: Root & { failed: boolean; cancelled: boolean },
          ctx: Context,
        ) => {
          const current = Parent.parse(await gateway(args, ctx).read());
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) throw new Error("route.target_root_invalid");
          const route_id = current.input.target.route_id;
          args = {
            ...args,
            cancelled: args.cancelled || current.cancel_requested,
          };
          const status = args.cancelled
            ? "cancelled"
            : args.failed
            ? "failed"
            : "succeeded";
          const result = args.cancelled
            ? {}
            : args.failed
            ? { route_id, error_code: "route.target_set_failed" }
            : { route_id, ready: true };
          await gateway(args, ctx).project({
            status,
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result,
            error_code: args.failed ? "route.target_set_failed" : null,
          });
          if (args.failed && !args.cancelled) {
            throw new Error("route.target_set_failed");
          }
          return { status };
        },
      },
    },
  };
}
export const behavior = { ...targetSet(), type: "@nckrtl/route-target-set" };
