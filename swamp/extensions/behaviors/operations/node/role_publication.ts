import { RoleCertificates } from "./role_certificates.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";

const NodeIds = z.array(z.number().int().positive()).max(4096);
export const RolePublication = z.object({
  caddy_node_ids: NodeIds,
  php_node_ids: NodeIds,
  dns_node_ids: NodeIds,
}).strict();
const FreshFields = new Set([
  "caddy_publication",
  "ingress_firewall",
  "php_publication",
  "private_dns_publication",
  "role_removal",
]);

/** Fresh participant artifacts are outside the immutable role snapshot schema. */
export function stripRolePublication(raw: unknown): unknown {
  if (raw === null || typeof raw !== "object" || Array.isArray(raw)) return raw;
  return Object.fromEntries(
    Object.entries(raw).filter(([key]) => !FreshFields.has(key)),
  );
}

const Args = DirectArgs.extend({});
const Current = z.object({
  id: z.number().int().positive(),
  kind: Args.shape.kind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.object({
    publication: RolePublication,
    certificates: RoleCertificates.optional(),
  }),
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

/** The parent owns certificate/serving state; participant jobs publish fresh public data. */
export function rolePublication(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  const read = async (args: z.infer<typeof Args>, ctx: Context) => {
    const current = Current.parse(
      await new GatewayOperation(makeHttp(ctx), args).read(ctx.signal),
    );
    if (
      current.id !== args.operation_id || current.kind !== args.kind ||
      current.node_id !== args.node_id ||
      !["queued", "running"].includes(current.status)
    ) {
      throw new Error(
        "Publication inventory differs from its native role root",
      );
    }
    if (current.cancel_requested || ctx.signal?.aborted) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    return current.input.publication;
  };
  return {
    type: "@nckrtl/node-role-publication",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      inventory: {
        description: "Immutable role publication placements",
        schema: RolePublication,
        lifetime: "1d",
        garbageCollection: 1,
      },
      completion: {
        description: "Native publication dependencies succeeded",
        schema: z.object({ ready: z.literal(true) }),
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: {
      inventory: {
        description: "Read immutable publication placement from this role root",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const inventory = await read(args, ctx);
          await ctx.writeResource("inventory", "inventory", inventory);
          return inventory;
        },
      },
      served: {
        description:
          "Record the native Caddy publication before private DNS includes the role",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = Current.parse(await gateway.read(ctx.signal));
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) throw new Error("publication.root_mismatch");
          for (
            const node_id of new Set(
              current.input.certificates?.leaves.map((l) => l.node_id) ?? [],
            )
          ) {
            await gateway.project({
              status: "running",
              started_at: new Date().toISOString(),
              result: {
                publication_phase: { phase: "caddy_published", node_id },
              },
            });
          }
          const result = { ready: true as const };
          await ctx.writeResource("completion", "served", result);
          return result;
        },
      },
      complete: {
        description:
          "Finish only after the native publication dependencies succeeded",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          await read(args, ctx);
          const result = { ready: true as const };
          await ctx.writeResource("completion", "completion", result);
          return result;
        },
      },
    },
  };
}
export const behavior = rolePublication();
