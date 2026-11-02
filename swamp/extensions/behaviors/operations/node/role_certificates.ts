import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import { RoleLeaf } from "../certificates/role-input.ts";

const Connection = z.object({
  node_id: z.number().int().positive(),
  ssh: z.object({
    control_node_id: z.literal(209),
    identity_file: z.literal(
      "/etc/orbit/swamp-role-worker/ssh-identity",
    ),
    public_key: z.string().min(1),
    target: z.object({
      name: z.literal("target"),
      host: z.string().min(1),
      user: z.string().min(1),
      port: z.number().int().positive(),
      host_keys: z.array(z.string().min(1)).min(1),
    }).strict(),
  }).strict(),
}).strict();
export const RoleCertificates = z.object({
  leaves: z.array(RoleLeaf).max(256),
  connections: z.array(Connection).max(256).optional(),
  withdrawals: z.array(
    z.object({
      node_id: z.number().int().positive(),
      scope: RoleLeaf.shape.scope,
    }).strict(),
  ).max(256),
}).strict().refine(
  (plan) =>
    new Set(plan.leaves.map((leaf) => `${leaf.node_id}:${leaf.scope}`))
      .size ===
      plan.leaves.length,
  { message: "Role certificate placements must be unique" },
);
const Args = DirectArgs.extend({
  node_id: z.number().int().positive(),
});
const Phase = z.object({
  phase: z.literal("certificates_published"),
  node_id: z.number().int().positive(),
}).strict();
const Current = z.object({
  id: z.number().int().positive(),
  kind: Args.shape.kind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.object({ certificates: RoleCertificates }),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};

/** Parent state only. Canonical certificate methods own all CSR/sign/publish effects. */
export function roleCertificates(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  const read = async (
    args: z.infer<typeof Args>,
    ctx: Context,
    beforeEffect: boolean,
  ) => {
    const gateway = new GatewayOperation(makeHttp(ctx), args);
    const current = Current.parse(await gateway.read(ctx.signal));
    if (
      current.id !== args.operation_id || current.kind !== args.kind ||
      current.node_id !== args.node_id ||
      !["queued", "running"].includes(current.status)
    ) {
      throw new Error(
        "Certificate plan differs from its native role root",
      );
    }
    if (beforeEffect && (current.cancel_requested || ctx.signal?.aborted)) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    return { gateway, plan: current.input.certificates };
  };
  const ready = z.object({ ready: z.literal(true) }).strict();
  const inventory = RoleCertificates.safeExtend({
    node_ids: z.array(z.number().int().positive()).max(256),
  });
  return {
    type: "@nckrtl/node-role-certificates",
    version: "2026.11.01.1",
    globalArguments: Globals,
    resources: {
      inventory: {
        schema: inventory,
        lifetime: "1d",
        garbageCollection: 1,
      },
      phase: { schema: Phase, lifetime: "1d", garbageCollection: 1 },
      completion: { schema: ready, lifetime: "1d", garbageCollection: 1 },
    },
    methods: {
      inventory: {
        description: "Read immutable public role leaves from the owning root",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const { plan } = await read(args, ctx, true);
          const ids = plan.leaves.map((leaf) => leaf.node_id);
          const connections = plan.connections ?? [];
          if (
            new Set(connections.map((c) => c.node_id)).size !==
              connections.length ||
            connections.some((c) => !ids.includes(c.node_id)) ||
            ids.some((id) => !connections.some((c) => c.node_id === id))
          ) {
            throw new Error(
              "Role certificate SSH intent differs from its admitted leaves",
            );
          }
          const result = inventory.parse({
            ...plan,
            node_ids: [
              ...new Set(plan.leaves.map((leaf) => leaf.node_id)),
            ]
              .sort(
                (a, b) => a - b,
              ),
          });
          await ctx.writeResource("inventory", "inventory", result);
          return result;
        },
      },
      record: {
        description: "Record successful native role certificate publication",
        arguments: Args.extend({
          target_node_id: z.number().int().positive(),
        }),
        execute: async (
          args: z.infer<typeof Args> & { target_node_id: number },
          ctx: Context,
        ) => {
          // Reconcile a finished effect even when cancellation arrived after publication.
          const { gateway, plan } = await read(args, ctx, false);
          if (
            !plan.leaves.some((leaf) => leaf.node_id === args.target_node_id)
          ) {
            throw new Error(
              "Certificate result Node is not in the admitted plan",
            );
          }
          const phase = Phase.parse({
            phase: "certificates_published",
            node_id: args.target_node_id,
          });
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
            result: { publication_phase: phase },
          });
          await ctx.writeResource(
            "phase",
            `phase-${args.target_node_id}`,
            phase,
          );
          return phase;
        },
      },
      complete: {
        description: "Finish after the native certificate and recording jobs",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          await read(args, ctx, false);
          const result = { ready: true as const };
          await ctx.writeResource("completion", "completion", result);
          return result;
        },
      },
    },
  };
}
export const behavior = roleCertificates();
