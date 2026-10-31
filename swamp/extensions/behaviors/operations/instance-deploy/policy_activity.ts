import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";

const Args = DirectArgs.extend({ kind: z.literal("instance.policy_event") });
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** Native parent for admitted database Activity; Gateway projection owns the record. */
export function policyActivity(
  makeHttp = (ctx: Context): Http =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/instance-policy-activity",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      run: {
        arguments: Args,
        description:
          "Project one admitted automatic policy Activity without Node actions",
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          return await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            () => Promise.resolve({}),
            (result) => z.object({}).strict().parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = policyActivity();
