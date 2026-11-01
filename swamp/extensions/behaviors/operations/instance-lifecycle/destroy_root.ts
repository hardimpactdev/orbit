import { sqliteFileInvoke } from "../database/sqlite_local.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import { removalActions, RemovalNode } from "../routes/removal-local.ts";
import { destroySystemRead } from "./destroy_system.ts";
import { DestroyLocal } from "./destroy_contract.ts";
import { DestroyRefusal } from "./destroy_local.ts";
import { inspectDestroySources } from "./destroy_inspection.ts";
import { assertCapturedDestroyPublication } from "./destroy_material.ts";
import { runDestroyLocalStep } from "./destroy_steps.ts";
import { composeDestroyRouteSnapshots } from "./destroy_routes.ts";
import {
  DestroyDatabase,
  DestroyDatabaseSelection,
  DestroyDatabaseWork,
  destroyDatabaseWork,
  removeDestroyDatabase,
} from "./destroy_database.ts";

const Id = z.number().int().positive();
const Args = DirectArgs.extend({
  kind: z.literal("instance.destroy"),
  node_id: Id,
});
const Stage = z.enum([
  "prepare_sources",
  "runtime",
  "teardown",
  "verify_sources",
  "finalize_sources",
]);
const Node = z.object({
  node_id: Id,
  user: z.string(),
  agent_sha256: z.string(),
  certificate_scopes: z.array(z.string()),
  lan_route_ids: z.array(Id),
});
const Plan = z.object({
  nodes: z.array(Node),
  dns_node_ids: z.array(Id),
  work: z.array(DestroyDatabaseWork),
});
const Current = z.object({
  id: Id,
  kind: z.literal("instance.destroy"),
  node_id: Id,
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.object({
    instance_id: Id,
    participant_node_ids: z.array(Id),
    direct_destroy: z.object({
      node_id: Id,
      members: z.array(z.unknown()),
      runtime: z.unknown(),
      database: DestroyDatabase,
      routes: z.array(z.unknown()),
    }).passthrough(),
  }).passthrough(),
});
type ArgsType = z.infer<typeof Args>;
type Context = {
  extensionFile(path: string): string;
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
function active(
  value: { status: string; cancel_requested: boolean },
  signal?: AbortSignal,
) {
  signal?.throwIfAborted();
  if (value.cancel_requested) throw new DestroyRefusal("operation.cancelled");
  if (!["queued", "running"].includes(value.status)) {
    throw new DestroyRefusal("operation.not_running");
  }
}
function local(value: z.infer<typeof Current>) {
  const detail = value.input.direct_destroy;
  return DestroyLocal.parse({
    node_id: detail.node_id,
    members: detail.members,
    runtime: detail.runtime,
    database: detail.database,
  });
}

/** One native parent; placed lower actions never admit or project child Operations. */
export function destroyRoot(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action = runDestroyLocalStep,
  captureSource = inspectDestroySources,
  checkPublication = assertCapturedDestroyPublication,
  databaseAction = removeDestroyDatabase,
  cleanup = removalActions(),
  account = destroySystemRead,
) {
  const port = (args: ArgsType, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  async function current(args: ArgsType, ctx: Context) {
    const value = Current.parse(await port(args, ctx).read(ctx.signal));
    if (
      value.id !== args.operation_id || value.node_id !== args.node_id ||
      value.input.direct_destroy.node_id !== args.node_id
    ) throw new DestroyRefusal("instance.removal_conflict");
    active(value, ctx.signal);
    return value;
  }
  return {
    type: "@nckrtl/instance-destroy",
    version: "2026.11.01.1",
    globalArguments: Globals,
    resources: {
      plan: {
        description:
          "Secret-free participant identities and placed database work",
        schema: Plan,
        lifetime: "workflow",
        garbageCollection: 1,
      },
    },
    methods: {
      captureSources: {
        arguments: Args,
        description: "Capture source identity before the first removal effect",
        execute: async (args: ArgsType, ctx: Context) => {
          const value = await current(args, ctx), gateway = port(args, ctx);
          if (value.input.source_pending !== true) return { captured: true };
          const source_inspection = await captureSource(
            value.input,
            undefined,
            ctx.signal,
          );
          await checkPublication(
            value.input,
            source_inspection,
            gateway,
            undefined,
            ctx.signal,
          );
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
            result: { source_inspection },
          });
          return { captured: true };
        },
      },
      admit: {
        arguments: Args,
        description:
          "Expose the admitted native participants and database fanout",
        execute: async (args: ArgsType, ctx: Context) => {
          const value = await current(args, ctx), input = local(value);
          if (input.database.owner_instance_id !== value.input.instance_id) {
            throw new DestroyRefusal("database.removal_conflict");
          }
          const routes = composeDestroyRouteSnapshots(
            input,
            value.input.direct_destroy.routes,
          );
          const plan = Plan.parse({
            nodes: routes.nodes,
            dns_node_ids: routes.dns.map((node) => node.node_id),
            work: destroyDatabaseWork(input.database),
          });
          const participants = [
            ...plan.nodes.map((node) => node.node_id),
            ...plan.dns_node_ids,
            ...plan.work.map((work) => work.node_id),
          ];
          if (
            participants.some((id) =>
              !value.input.participant_node_ids.includes(id)
            )
          ) throw new DestroyRefusal("instance.removal_conflict");
          await port(args, ctx).project({
            status: "running",
            started_at: new Date().toISOString(),
          });
          return {
            dataHandles: [await ctx.writeResource("plan", "plan", plan)],
          };
        },
      },
      step: {
        arguments: Args.extend({ stage: Stage }),
        description: "Run one local source or runtime phase",
        execute: async (
          args: ArgsType & { stage: z.infer<typeof Stage> },
          ctx: Context,
        ) => {
          await action(args.stage, args, port(args, ctx), {}, ctx.signal);
          return { ready: true };
        },
      },
      database: {
        arguments: Args.extend({
          target_node_id: Id,
          work: DestroyDatabaseWork,
        }),
        description:
          "Compose the owning DB removal lower on its admitted participant",
        execute: async (
          args: ArgsType & {
            target_node_id: number;
            work: z.infer<typeof DestroyDatabaseWork>;
          },
          ctx: Context,
        ) => {
          const gateway = port(args, ctx);
          const value = z.object({
            id: Id,
            kind: z.literal("instance.destroy"),
            node_id: Id,
            status: z.string(),
            cancel_requested: z.boolean(),
            input: z.object({
              direct_destroy: z.object({ database: DestroyDatabaseSelection }),
            }),
          }).parse(await gateway.read(ctx.signal));
          if (
            value.id !== args.operation_id || value.node_id !== args.node_id
          ) throw new DestroyRefusal("instance.removal_conflict");
          active(value, ctx.signal);
          return await databaseAction(
            args.work,
            args.target_node_id,
            value.input.direct_destroy.database,
            gateway,
            ctx.signal,
            undefined,
            sqliteFileInvoke(ctx.extensionFile("database-server-commands/scripts/sqlite_actions.py")),
          );
        },
      },
      cleanupNode: {
        arguments: Args.extend({ node: Node }),
        description:
          "Remove withdrawn leaf and LAN artifacts after publication and database success",
        execute: async (
          args: ArgsType & { node: z.infer<typeof Node> },
          ctx: Context,
        ) => {
          const gateway = port(args, ctx);
          const value = z.object({
            id: Id,
            kind: z.literal("instance.destroy"),
            node_id: Id,
            status: z.string(),
            cancel_requested: z.boolean(),
            input: z.object({
              direct_destroy: z.object({
                routes: z.array(z.object({ nodes: z.array(Node) })),
              }),
            }),
          }).parse(await gateway.read(ctx.signal));
          if (
            value.id !== args.operation_id || value.node_id !== args.node_id
          ) throw new DestroyRefusal("instance.removal_conflict");
          active(value, ctx.signal);
          const rows = value.input.direct_destroy.routes.flatMap((route) =>
            route.nodes
          ).filter((node) => node.node_id === args.node.node_id);
          const scopes = [
            ...new Set(rows.flatMap((node) => node.certificate_scopes)),
          ].sort();
          const lan = [...new Set(rows.flatMap((node) => node.lan_route_ids))]
            .sort((a, b) => a - b);
          if (
            !rows.length || rows.some((node) => node.user !== args.node.user) ||
            JSON.stringify(scopes) !==
              JSON.stringify(args.node.certificate_scopes) ||
            JSON.stringify(lan) !== JSON.stringify(args.node.lan_route_ids)
          ) throw new DestroyRefusal("route.withdrawal_invalid");
          const owner = z.object({
            user: z.literal(args.node.user),
            home: z.string().startsWith("/"),
          }).parse(
            await account({
              name: "account.lookup",
              input: { user: args.node.user },
              sudo: false,
              mutates: false,
              deadlineMs: 10000,
            }, ctx.signal),
          );
          const node = RemovalNode.parse({
            ...args.node,
            home: owner.home,
            shared_php: false,
            production_instance_ids: [],
            url_instance_ids: [],
            ingress_firewall: false,
          });
          await cleanup.certificates(node, ctx.signal);
          await cleanup.lan(node);
          return { ready: true };
        },
      },
      finish: {
        arguments: Args,
        description:
          "Project bounded record deletion after every native dependency succeeds",
        execute: async (args: ArgsType, ctx: Context) => {
          await port(args, ctx).project({
            status: "succeeded",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result: { removed: true },
            error_code: null,
          });
          return { removed: true };
        },
      },
      fail: {
        arguments: Args.extend({
          failed_step: z.string().regex(/^[a-z_]{1,64}$/),
        }),
        description: "Record a failed native phase without deleting records",
        execute: async (
          args: ArgsType & { failed_step: string },
          ctx: Context,
        ) => {
          const gateway = port(args, ctx);
          let cancelled = false;
          try {
            const observed = z.object({
              id: Id,
              kind: z.literal("instance.destroy"),
              node_id: Id,
              status: z.string(),
              cancel_requested: z.boolean(),
            }).parse(await gateway.read());
            if (
              observed.id !== args.operation_id ||
              observed.node_id !== args.node_id
            ) throw new Error("instance.removal_conflict");
            if (
              ["succeeded", "failed", "cancelled"].includes(observed.status)
            ) return { recorded: true };
            cancelled = observed.cancel_requested;
          } catch {
            /* A failed public render must not prevent terminal failure reporting. */
          }
          await gateway.project({
            status: cancelled ? "cancelled" : "failed",
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result: cancelled ? {} : {
              error_code: "instance.destroy_failed",
              failed_step: args.failed_step,
            },
            error_code: cancelled ? null : "instance.destroy_failed",
          });
          return { recorded: true };
        },
      },
    },
  };
}
export const behavior = destroyRoot();
