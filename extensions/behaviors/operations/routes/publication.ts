import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";

const Args = DirectArgs.extend({
  kind: z.enum(["route.caddy_converge", "route.private_dns_publish"]),
  node_id: z.number().int().positive(),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};

/** Native behavior locking serializes each Node's entire publication step. */
export function publication(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/route-publication",
    version: "2026.10.30.3",
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
    },
  };
}

export const behavior = publication();
