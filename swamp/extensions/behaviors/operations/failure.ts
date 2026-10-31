import { DirectArgs, GatewayOperation, Globals } from "./_lib/direct.ts";
import { gatewayHttp } from "../automatic-deploy/gateway.ts";
import type { z } from "npm:zod@4.4.3";

/** A separate failed-dependency job can still report when a family bundle is refused. */
export function nativeFailure(
  http = (ctx: { globalArgs: z.infer<typeof Globals> }) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/operation-failure",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      report: {
        description:
          "Record this bound native root failure without domain effects",
        arguments: DirectArgs,
        execute: async (
          args: z.infer<typeof DirectArgs>,
          ctx: { globalArgs: z.infer<typeof Globals> },
        ) => {
          const gateway = new GatewayOperation(
            http(ctx),
            DirectArgs.parse(args),
          );
          await gateway.call("POST", "/failure", { run_id: args.run_id });
          return { reported: true };
        },
      },
    },
  };
}
export const behavior = nativeFailure();
