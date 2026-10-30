import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { type Discard, discard, discardDestination } from "./direct_discard.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.transfer_discard"),
  node_id: z.number().int().positive(),
});
const Result = z.union([
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
  z.object({ discarded: z.literal(true) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

export function lifecycleDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  remove: Discard = discardDestination,
) {
  return {
    type: "@nckrtl/instance-lifecycle-direct",
    version: "2026.10.30.4",
    globalArguments: Globals,
    methods: {
      execute: {
        description: "Execute the admitted lifecycle action on its exact Node",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          Args.parse(args);
          return await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            (input) => discard(input, remove),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}

export const behavior = {
  ...lifecycleDirect(),
  type: "@nckrtl/instance-lifecycle-direct",
};
