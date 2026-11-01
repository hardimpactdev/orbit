import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";

import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import { CaddyPublication, publishCaddy as applyCaddy } from "./caddy.ts";
import { PhpPublication, publishPhp as applyPhp } from "./php.ts";
import { runLocalProgram } from "./local-program.ts";
import { DnsPublication, publishPrivateDns, runPrivateDns } from "./dns.ts";
import { convergeFirewall } from "./firewall-local.ts";

const Args = DirectArgs.extend({
  kind: z.literal("route.private_dns_publish"),
  node_id: z.number().int().positive(),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};
const Parent = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: z.record(z.string(), z.unknown()),
});

async function freshParticipant(
  http: ReturnType<typeof gatewayHttp>,
  args: z.infer<typeof DirectArgs> & { recovery?: boolean },
  signal?: AbortSignal,
) {
  const current = Parent.parse(
    await new GatewayOperation(http, args).read(
      args.recovery ? undefined : signal,
    ),
  );
  if (
    current.id !== args.operation_id || current.kind !== args.kind ||
    current.node_id !== args.node_id
  ) {
    throw new Error("Publication parent does not match admitted intent");
  }
  if (!["queued", "running"].includes(current.status)) {
    throw new Error("Publication parent has ended");
  }
  if (!args.recovery && (current.cancel_requested || signal?.aborted)) {
    throw new DirectOperationCancelled({ effectRunning: false });
  }
  return current;
}

function boundCaddy(input: unknown, nodeId: number) {
  const payload = CaddyPublication.parse(input);
  if (payload.node_id !== nodeId) {
    throw new Error("Caddy publication Node does not match");
  }
  return payload;
}

/** Native behavior locking serializes each Node's entire publication step. */
export function publication(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke: Parameters<typeof applyCaddy>[1] = undefined,
  invokeDns = runPrivateDns,
  invokePhp = runLocalProgram,
  invokeFirewall = runLocalProgram,
) {
  return {
    type: "@nckrtl/route-publication",
    version: "2026.10.31.9",
    globalArguments: Globals,
    methods: {
      publish: {
        description: "Publish the admitted DNS owner's current private catalog",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(
            makeHttp(ctx),
            args,
          );
          return await directOperation(
            args,
            gateway,
            (input) => {
              const payload = DnsPublication.parse(
                input.private_dns_publication,
              );
              if (payload.node_id !== args.node_id) {
                throw new Error("DNS publication Node does not match");
              }
              return publishPrivateDns(payload, invokeDns);
            },
            (result) => {
              if (result.error_code === undefined && result.ready !== true) {
                throw new Error("Route publication has no successful result");
              }
              return result;
            },
            ctx.signal,
          );
        },
      },
      publishFirewall: {
        description:
          "Read and apply the participant's current ingress firewall rules",
        arguments: DirectArgs.extend({
          target_node_id: z.number().int().positive().optional(),
          recovery: z.boolean().optional(),
        }),
        execute: async (
          args: z.infer<typeof DirectArgs> & {
            target_node_id?: number;
            recovery?: boolean;
          },
          ctx: Context,
        ) => {
          const current = await freshParticipant(
            makeHttp(ctx),
            args,
            args.recovery ? undefined : ctx.signal,
          );
          const identity = z.object({ node_id: z.number().int().positive() })
            .parse(current.input.caddy_publication);
          if (identity.node_id !== (args.target_node_id ?? args.node_id)) {
            throw new Error("Firewall publication Node does not match");
          }
          const result = await convergeFirewall(
            current.input.ingress_firewall,
            invokeFirewall,
            args.recovery ? undefined : ctx.signal,
          );
          if ("error_code" in result) throw new Error(result.error_code);
          return result;
        },
      },
      publishPhp: {
        description:
          "Read and publish the participant's current whole-Node PHP pools",
        arguments: DirectArgs.extend({
          target_node_id: z.number().int().positive().optional(),
          recovery: z.boolean().optional(),
        }),
        execute: async (
          args: z.infer<typeof DirectArgs> & {
            target_node_id?: number;
            recovery?: boolean;
          },
          ctx: Context,
        ) => {
          const current = await freshParticipant(
            makeHttp(ctx),
            args,
            args.recovery ? undefined : ctx.signal,
          );
          const payload = PhpPublication.parse(current.input.php_publication);
          if (payload.node_id !== (args.target_node_id ?? args.node_id)) {
            throw new Error("PHP publication Node does not match");
          }
          const result = await applyPhp(
            payload,
            invokePhp,
            args.recovery ? undefined : ctx.signal,
          );
          if ("error_code" in result) throw new Error(result.error_code);
          return result;
        },
      },
      publishDns: {
        description:
          "Read and publish the participant's current private DNS catalog",
        arguments: DirectArgs.extend({
          target_node_id: z.number().int().positive().optional(),
          recovery: z.boolean().optional(),
        }),
        execute: async (
          args: z.infer<typeof DirectArgs> & {
            target_node_id?: number;
            recovery?: boolean;
          },
          ctx: Context,
        ) => {
          const current = await freshParticipant(
            makeHttp(ctx),
            args,
            args.recovery ? undefined : ctx.signal,
          );
          const payload = DnsPublication.parse(
            current.input.private_dns_publication,
          );
          if (payload.node_id !== (args.target_node_id ?? args.node_id)) {
            throw new Error("DNS publication Node does not match");
          }
          const result = await publishPrivateDns(payload, invokeDns);
          if ("error_code" in result) throw new Error(result.error_code);
          return result;
        },
      },
      publishCaddy: {
        description:
          "Read and publish fresh Caddy data for an admitted participant Node",
        arguments: DirectArgs.extend({
          node_id: z.number().int().positive(),
          target_node_id: z.number().int().positive().optional(),
          recovery: z.boolean().optional(),
        }),
        execute: async (
          args: z.infer<typeof DirectArgs> & {
            node_id: number;
            target_node_id?: number;
            recovery?: boolean;
          },
          ctx: Context,
        ) => {
          const current = await freshParticipant(
            makeHttp(ctx),
            args,
            args.recovery ? undefined : ctx.signal,
          );
          const result = await applyCaddy(
            boundCaddy(
              current.input.caddy_publication,
              args.target_node_id ?? args.node_id,
            ),
            invoke,
            args.recovery ? undefined : ctx.signal,
          );
          if ("error_code" in result) {
            throw new Error(result.error_code);
          }
          return result;
        },
      },
    },
  };
}

export const behavior = publication();
