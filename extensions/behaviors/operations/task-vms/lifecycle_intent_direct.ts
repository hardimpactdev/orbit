import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";

const Args = DirectArgs.extend({ kind: z.literal("task_vm.lifecycle_intent") });
export const Intent = z.object({ task_vm_id: z.number().int().positive() })
  .strict();
const Result = z.object({ ready: z.literal(true) }).strict();
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** An acknowledgement only. No provider observation, credential or Node action. */
export function lifecycleIntent(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/task-vm-lifecycle-intent",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description: "Acknowledge one admitted VM lifecycle intent",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) =>
          await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            (input) => {
              Intent.parse(input);
              return Promise.resolve({ ready: true });
            },
            (result) => Result.parse(result),
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = {
  ...lifecycleIntent(),
  type: "@nckrtl/task-vm-lifecycle-intent",
};
