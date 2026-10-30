import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";
import { CaddyPublication, publishCaddy as applyCaddy } from "./caddy.ts";
import { runNodeAgent } from "../_lib/node-agent.ts";

const Args = DirectArgs.extend({
  kind: z.enum(["route.caddy_converge", "route.private_dns_publish"]),
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
  input: z.object({ caddy_publication: CaddyPublication }),
});

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
  invoke = runNodeAgent,
) {
  return {
    type: "@nckrtl/route-publication",
    version: "2026.10.30.4",
    globalArguments: Globals,
    methods: {
      publish: {
        description: "Publish one admitted Node's Caddy or private DNS intent",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(
            makeHttp(ctx),
            args,
          );
          return await directOperation(
            args,
            gateway,
            () => gateway.primitive(),
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
      convergeCaddy: {
        description:
          "Publish and project a Caddy Operation on its admitted Node",
        arguments: DirectArgs.extend({
          kind: z.literal("route.caddy_converge"),
          node_id: z.number().int().positive(),
        }),
        execute: async (
          args: z.infer<typeof DirectArgs> & { node_id: number },
          ctx: Context,
        ) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            (input) =>
              applyCaddy(
                boundCaddy(input.caddy_publication, args.node_id),
                invoke,
                ctx.signal,
              ),
            (result) => {
              if (
                result.error_code === undefined && result.ready !== true
              ) throw new Error("Caddy has no successful publication");
              return result;
            },
            ctx.signal,
          );
        },
      },
      publishCaddy: {
        description:
          "Read and publish current Caddy data for the same-root admitted Node",
        arguments: DirectArgs.extend({ node_id: z.number().int().positive() }),
        execute: async (
          args: z.infer<typeof DirectArgs> & { node_id: number },
          ctx: Context,
        ) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = Parent.parse(await gateway.read(ctx.signal));
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) {
            throw new Error("Caddy parent does not match admitted intent");
          }
          if (!["queued", "running"].includes(current.status)) {
            throw new Error("Caddy parent has ended");
          }
          if (current.cancel_requested || ctx.signal?.aborted) {
            throw new DirectOperationCancelled({ effectRunning: false });
          }
          const result = await applyCaddy(
            boundCaddy(current.input.caddy_publication, args.node_id),
            invoke,
            ctx.signal,
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
