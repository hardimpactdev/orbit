import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { Kind, Result, ToolIntentInput } from "./direct-contract.ts";
import { toolNode } from "./node.ts";
import { localTool } from "./local.ts";

const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
type NodeContext = Parameters<
  ReturnType<typeof toolNode>["methods"]["apply"]["execute"]
>[1];
type Context = NodeContext & { globalArgs: z.infer<typeof Globals> };

/** Shared Gateway protocol around one native Node action and its observations. */
export function directTool(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = localTool,
) {
  return {
    type: "@nckrtl/tool-direct",
    version: "2026.10.30.2",
    globalArguments: Globals,
    resources: toolNode(invoke).resources,
    methods: {
      apply: {
        description: "Execute admitted Tool policy on its exact Node worker",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = ToolIntentInput.parse(raw);
              const { outcome } = await toolNode(invoke).methods.apply.execute({
                intent: { ...args, input },
              }, ctx);
              return outcome.result;
            },
            Result.shape.result.parse,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = { ...directTool(), type: "@nckrtl/tool-direct" };
