import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { runLocalProgram } from "./local-program.ts";
import { convergeFirewall, convergeRouteLan } from "./firewall-local.ts";
import { convergeRouterLan } from "./router-lan.ts";
import { prepareRouter } from "./router-preparation.ts";
import { readRoleAccount, RoleAccount } from "../node/role_baseline_system.ts";
import { verifyLocal } from "./verification.ts";
import { removalActions } from "./removal-local.ts";
const Args = DirectArgs.extend({ node_id: z.number().int().positive() });
const Node = z.object({
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  user: RoleAccount.shape.user,
  home: RoleAccount.shape.home.optional(),
}).passthrough();
const Inventory = z.object({
  cluster_id: z.number().int().positive(),
  candidate: Node.nullable(),
  noop: z.boolean(),
  old_nodes: z.array(Node),
  leaves: z.array(z.record(z.string(), z.unknown())),
  workloads: z.array(Node),
  route_ids: z.array(z.number().int().positive()),
  edge_node_ids: z.array(z.number().int().positive()),
  dns_node_ids: z.array(z.number().int().positive()),
});
const LocalArgs = Args.extend({
  target_node_id: z.number().int().positive(),
  node: Node,
});
const Checkpoint = z.enum([
  "router-serving",
  "router-selected",
  "old-router-withdrawn",
  "candidate-withdrawn",
]);
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    data: unknown,
  ) => Promise<unknown>;
};
const object = (value: unknown) =>
  z.record(z.string(), z.unknown()).parse(value);
export function clusterRouter(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = runLocalProgram,
  probeAction = verifyLocal,
  accountAction = (user: string, signal?: AbortSignal) =>
    readRoleAccount(user, undefined, signal),
) {
  async function account(args: z.infer<typeof LocalArgs>, ctx: Context) {
    if (args.target_node_id !== args.node.node_id) {
      throw new Error("Router account differs from worker");
    }
    const resolved = await accountAction(args.node.user, ctx.signal);
    if (resolved === null) throw new Error("role.prerequisite_failed");
    if (args.node.home !== undefined && args.node.home !== resolved.home) {
      throw new Error("role.prerequisite_conflict");
    }
    return resolved;
  }
  function local(
    name: string,
    field: string,
    action: (
      raw: Record<string, unknown>,
      signal?: AbortSignal,
    ) => Promise<Record<string, unknown>>,
    match = false,
  ) {
    return {
      description: name,
      arguments: LocalArgs,
      execute: async (args: z.infer<typeof LocalArgs>, ctx: Context) => {
        if (args.target_node_id !== args.node.node_id) {
          throw new Error(
            "Router effect differs from worker",
          );
        }
        const result = await action(object(args.node[field]), ctx.signal);
        if (result.error_code !== undefined) {
          throw new Error(
            z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
          );
        }
        if (match && result.matches !== true) {
          throw new Error(
            "node.firewall_convergence_failed",
          );
        }
        return { ready: true };
      },
    };
  }
  return {
    type: "@nckrtl/cluster-router",
    version: "2026.10.31.2",
    globalArguments: Globals,
    resources: {
      inventory: {
        description: "Public Router transition inventory",
        schema: Inventory,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      read: {
        description: "Read this parent's admitted Router transition",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = z.object({
            id: z.number(),
            kind: z.string(),
            node_id: z.number(),
            cancel_requested: z.boolean(),
            input: z.object({ cluster_router: Inventory }),
          }).parse(await gateway.read(ctx.signal));
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) throw new Error("Router root differs from admission");
          if (current.cancel_requested || ctx.signal?.aborted) {
            throw new Error("operation.cancelled");
          }
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
          });
          await ctx.writeResource(
            "inventory",
            "inventory",
            current.input.cluster_router,
          );
          return current.input.cluster_router;
        },
      },
      installCaddy: local(
        "caddy.install-package",
        "caddy_package",
        (input, signal) =>
          prepareRouter("caddy.install-package", input, invoke, signal),
      ),
      installPrerequisites: {
        description:
          "Resolve the local account and install Router prerequisites",
        arguments: LocalArgs,
        execute: async (args: z.infer<typeof LocalArgs>, ctx: Context) => {
          const resolved = await account(args, ctx);
          const result = await prepareRouter(
            "role.install-prerequisites",
            {
              ...object(args.node.prerequisites),
              ...resolved,
            },
            invoke,
            ctx.signal,
          );
          if (result.error_code !== undefined) {
            throw new Error(
              z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
            );
          }
          return { ready: true };
        },
      },
      firewall: local(
        "firewall.rules",
        "firewall",
        (input, signal) => convergeFirewall(input, invoke, signal),
        true,
      ),
      routerLan: local(
        "firewall.router-lan",
        "router_lan",
        (input, signal) => convergeRouterLan(input, invoke, signal),
        true,
      ),
      workloadLan: local(
        "firewall.route-lan",
        "firewall",
        (input, signal) =>
          convergeRouteLan({ operation: "converge", ...input }, invoke, signal),
        true,
      ),
      retireLan: local(
        "firewall.rules",
        "retire_firewall",
        (input, signal) => convergeFirewall(input, invoke, signal),
        true,
      ),
      verify: {
        description: "Verify candidate Router workload hops",
        arguments: LocalArgs,
        execute: async (args: z.infer<typeof LocalArgs>, _ctx: Context) => {
          if (args.target_node_id !== args.node.node_id) {
            throw new Error("Router probe differs from worker");
          }
          for (const probe of z.array(z.unknown()).parse(args.node.probes)) {
            const result = await probeAction(probe);
            if (result.error_code) throw new Error(result.error_code);
          }
          return { ready: true };
        },
      },
      certificates: {
        description:
          "Remove old Router scopes after native DNS expiry and Caddy withdrawal",
        arguments: LocalArgs,
        execute: async (args: z.infer<typeof LocalArgs>, ctx: Context) => {
          const resolved = await account(args, ctx);
          return await removalActions(invoke).certificates({
            ...args.node,
            ...resolved,
            lan_route_ids: [],
            shared_php: false,
            production_instance_ids: [],
            url_instance_ids: [],
            ingress_firewall: false,
          }, ctx.signal);
        },
      },
      checkpoint: {
        description: "Project a native Router state checkpoint",
        arguments: Args.extend({ checkpoint: Checkpoint }),
        execute: async (
          args: z.infer<typeof Args> & {
            checkpoint: z.infer<typeof Checkpoint>;
          },
          ctx: Context,
        ) => {
          await new GatewayOperation(makeHttp(ctx), args).project({
            status: "running",
            started_at: new Date().toISOString(),
            result: { checkpoint: args.checkpoint },
          });
          return { ready: true };
        },
      },
      grace: {
        description:
          "Observe the private DNS TTL plus one second before retiring a Router",
        arguments: Args,
        execute: async (_args: z.infer<typeof Args>, ctx: Context) => {
          ctx.signal?.throwIfAborted();
          await new Promise<void>((resolve, reject) => {
            const timer = setTimeout(resolve, 31000);
            ctx.signal?.addEventListener("abort", () => {
              clearTimeout(timer);
              reject(ctx.signal?.reason);
            }, { once: true });
          });
          return { ready: true };
        },
      },
      finish: {
        description: "Project the native Router transition result",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          await new GatewayOperation(makeHttp(ctx), args).project({
            status: "succeeded",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result: { ready: true },
          });
          return { ready: true };
        },
      },
    },
  };
}
export const behavior = clusterRouter();
