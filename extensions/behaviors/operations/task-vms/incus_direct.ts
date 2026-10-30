import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { IncusEffect, IncusInput, incusInvocation } from "./contract.ts";
import { localIncus } from "./incus_local.ts";

export const DirectIncusInput = z.object({
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  intent: IncusInput,
}).strict();
export const DirectIncusResult = z.union([
  z.object({ ready: z.literal(true) }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
const Args = DirectArgs.extend({
  kind: IncusEffect,
  node_id: z.number().int().positive(),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Action = (
  pin: string,
  input: Record<string, unknown>,
) => Promise<unknown>;

export function incusDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action: Action = localIncus,
) {
  return {
    type: "@nckrtl/task-vm-incus",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description: "Execute the admitted Incus VM effect on its exact Node",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = DirectIncusInput.parse(raw);
              const call = incusInvocation(args.kind, input.intent);
              return await action(input.agent_sha256, call.input);
            },
            (result) => DirectIncusResult.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}

export const behavior = { ...incusDirect(), type: "@nckrtl/task-vm-incus" };
