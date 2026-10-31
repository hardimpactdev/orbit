import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";

const Args = DirectArgs.extend({
  kind: z.literal("route.target_unset"),
  node_id: z.number().int().positive(),
});
export const EmptyTargets = z.object({
  route_id: z.number().int().positive(),
  target_ids: z.array(z.never()).length(0),
}).strict();
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

export function targetUnset(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/route-target-unset",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      confirm: {
        description:
          "Confirm the admitted empty Route target set without publication",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) =>
          await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            (input) =>
              Promise.resolve(
                EmptyTargets.safeParse(input).success
                  ? { ready: true }
                  : { error_code: "route.target_conflict" },
              ),
            (result) =>
              z.union([
                z.object({ ready: z.literal(true) }).strict(),
                z.object({ error_code: z.literal("route.target_conflict") })
                  .strict(),
              ]).parse(result),
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = targetUnset();
