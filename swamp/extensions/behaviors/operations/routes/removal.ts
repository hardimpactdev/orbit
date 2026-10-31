import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { cleanupActions } from "./cleanup-local.ts";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import { convergeFirewall } from "./firewall-local.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import {
  removalActions,
  RemovalNode,
  RemovalSnapshot,
} from "./removal-local.ts";

const Args = DirectArgs.extend({ node_id: z.number().int().positive() });
const Retained = z.array(
  z.object({
    node_id: z.number().int().positive(),
    steps: z.array(z.string()),
  }),
);
const Selected = RemovalSnapshot.extend({
  retained_on_nodes: Retained.default([]),
});
const NodeArgs = Args.extend({
  target_node_id: z.number().int().positive(),
  node: RemovalNode,
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    data: unknown,
  ) => Promise<unknown>;
};
const Current = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  status: z.string(),
  cancel_requested: z.boolean(),
  input: z.object({ route_removal: RemovalSnapshot }),
});

export function removal(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  actions = removalActions(),
  workloadActions = routeWorkloadLocal(),
  firewall = convergeFirewall,
) {
  const cleanup = cleanupActions(workloadActions);
  const effectArgs = Args.extend({
    target_node_id: z.number().int().positive(),
  });
  type EffectArgs = z.infer<typeof effectArgs>;
  async function effect(args: EffectArgs, ctx: Context) {
    const current = Current.extend({
      input: z.object({
        route_removal: RemovalSnapshot,
        php_publication: z.object({
          node_id: z.number().int().positive(),
          agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
        }),
        workloads: z.array(z.record(z.string(), z.unknown())).default([]),
        environment_fields: z.record(z.string(), z.string()).default({}),
      }),
    }).parse(await new GatewayOperation(makeHttp(ctx), args).read(ctx.signal));
    if (
      current.id !== args.operation_id || current.kind !== args.kind ||
      current.node_id !== args.node_id ||
      current.input.php_publication.node_id !== args.target_node_id
    ) throw new Error("Route workload differs from admission");
    if (current.cancel_requested || ctx.signal?.aborted) {
      throw new Error("operation.cancelled");
    }
    if (!["queued", "running"].includes(current.status)) {
      throw new Error("Route withdrawal has ended");
    }
    return current.input;
  }
  return {
    type: "@nckrtl/route-removal",
    version: "2026.10.31.2",
    globalArguments: Globals,
    resources: {
      inventory: {
        description: "Public admitted Route withdrawal inventory",
        schema: Selected,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      read: {
        description: "Read one parent's complete withdrawal inventory",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = Current.parse(await gateway.read(ctx.signal));
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) throw new Error("Route withdrawal root differs from admission");
          if (current.cancel_requested || ctx.signal?.aborted) {
            throw new Error(
              "operation.cancelled",
            );
          }
          if (!["queued", "running"].includes(current.status)) {
            throw new Error(
              "Route withdrawal has ended",
            );
          }
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
          });
          await ctx.writeResource(
            "inventory",
            "inventory",
            current.input.route_removal,
          );
          return current.input.route_removal;
        },
      },
      ready: {
        description:
          "Confirm this exact worker can run a no-effect step before any Route effect",
        arguments: Args.extend({ target_node_id: z.number().int().positive() }),
        execute: (_args: unknown, _ctx: Context) =>
          Promise.resolve({ ready: true }),
      },
      select: {
        description:
          "Keep reachable native workers and retain offline Node cleanup before effects",
        arguments: Args.extend({
          inventory: RemovalSnapshot,
          readiness: z.record(
            z.string(),
            z.object({ status: z.string() }).passthrough(),
          ),
        }),
        execute: async (
          args: z.infer<typeof Args> & {
            inventory: z.infer<typeof RemovalSnapshot>;
            readiness: Record<string, { status: string }>;
          },
          ctx: Context,
        ) => {
          const retained_on_nodes: z.infer<typeof Retained> = [];
          const nodes = args.inventory.nodes.filter((node) => {
            if (
              args.readiness[`ready-${node.node_id}`]?.status === "succeeded"
            ) return true;
            if (!args.inventory.offline) {
              throw new Error("route.node_unreachable");
            }
            retained_on_nodes.push({
              node_id: node.node_id,
              steps: [
                "caddy",
                ...(node.shared_php || node.production_instance_ids.length
                  ? ["php"]
                  : []),
                "certificates",
                "firewall",
              ],
            });
            return false;
          });
          const selected = { ...args.inventory, nodes, retained_on_nodes };
          await ctx.writeResource("inventory", "selected", selected);
          return selected;
        },
      },
      certificates: {
        description: "Remove withdrawn Route leaf scopes on this worker",
        arguments: NodeArgs,
        execute: async (args: z.infer<typeof NodeArgs>, ctx: Context) => {
          if (args.target_node_id !== args.node.node_id) {
            throw new Error("Route withdrawal Node differs from worker");
          }
          const current = await effect(args, ctx);
          const member = current.route_removal.nodes.find((node) =>
            node.node_id === args.target_node_id
          );
          if (!member) throw new Error("Route withdrawal participant missing");
          return await actions.certificates(member, ctx.signal);
        },
      },
      lan: {
        description: "Remove withdrawn Route LAN rules on this worker",
        arguments: NodeArgs,
        execute: async (args: z.infer<typeof NodeArgs>, ctx: Context) => {
          if (args.target_node_id !== args.node.node_id) {
            throw new Error(
              "Route withdrawal Node differs from worker",
            );
          }
          const current = await effect(args, ctx);
          const member = current.route_removal.nodes.find((node) =>
            node.node_id === args.target_node_id
          );
          if (!member) throw new Error("Route withdrawal participant missing");
          return await actions.lan(member);
        },
      },
      prepareRuntime: {
        description:
          "Rebuild production PHP runtimes from the remaining served roots",
        arguments: effectArgs,
        execute: async (args: EffectArgs, ctx: Context) => {
          const current = await effect(args, ctx);
          return await cleanup.prepareRuntime(current.workloads, ctx.signal);
        },
      },
      synchronizeEnvironment: {
        description:
          "Restore authoritative application URLs inside the protected material callback",
        arguments: effectArgs,
        execute: async (args: EffectArgs, ctx: Context) => {
          const current = await effect(args, ctx);
          const member = current.route_removal.nodes.find((node) =>
            node.node_id === args.target_node_id
          );
          if (!member) throw new Error("Route environment participant missing");
          await new GatewayOperation(makeHttp(ctx), args).withPrivateMaterial(
            async (material) => {
              await cleanup.synchronizeEnvironment(
                material[
                  current.environment_fields[String(args.target_node_id)]
                ],
                member.url_instance_ids,
              );
            },
          );
          return { ready: true };
        },
      },
      ingressFirewall: {
        description:
          "Converge the remaining public ingress rules after withdrawal",
        arguments: Args.extend({ target_node_id: z.number().int().positive() }),
        execute: async (
          args: z.infer<typeof Args> & { target_node_id: number },
          ctx: Context,
        ) => {
          const current = z.object({
            id: z.number(),
            kind: z.string(),
            node_id: z.number(),
            cancel_requested: z.boolean(),
            input: z.object({
              ingress_firewall: z.record(z.string(), z.unknown()).nullable(),
              caddy_publication: z.object({
                node_id: z.number(),
                agent_sha256: z.string(),
              }),
            }),
          }).parse(
            await new GatewayOperation(makeHttp(ctx), args).read(ctx.signal),
          );
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id ||
            current.input.caddy_publication.node_id !== args.target_node_id
          ) throw new Error("Route ingress differs from admission");
          if (current.cancel_requested || ctx.signal?.aborted) {
            throw new Error("operation.cancelled");
          }
          if (current.input.ingress_firewall === null) {
            throw new Error("Route ingress firewall unavailable");
          }
          const result = await firewall(
            current.input.ingress_firewall,
            undefined,
            ctx.signal,
          );
          if ("error_code" in result) {
            throw new Error(
              z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
            );
          }
          if (result.matches !== true) {
            throw new Error("node.firewall_convergence_failed");
          }
          return { ready: true };
        },
      },
      finish: {
        description: "Record this native parent's successful Route withdrawal",
        arguments: Args.extend({ retained_on_nodes: Retained.default([]) }),
        execute: async (
          args: z.infer<typeof Args> & {
            retained_on_nodes: z.infer<typeof Retained>;
          },
          ctx: Context,
        ) => {
          await new GatewayOperation(makeHttp(ctx), args).project({
            status: "succeeded",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result: { ready: true, retained_on_nodes: args.retained_on_nodes },
          });
          return { ready: true };
        },
      },
    },
  };
}
export const behavior = removal();
