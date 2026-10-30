import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { localRule } from "./local.ts";

const Kind = z.enum(["firewall.allow", "firewall.deny", "firewall.remove"]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Rule = z.object({
  node_id: z.number().int().positive(),
  name: z.string(),
  action: z.enum(["allow", "deny"]),
  source: z.string(),
  protocol: z.enum(["tcp", "udp"]),
  port: z.string(),
});
const Result = z.object({
  backend: z.enum(["active", "inactive", "absent"]).optional(),
  error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).optional(),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** One native lock for the Node's whole Firewall action. No Gateway relay. */
export function directFirewall(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  run = localRule,
) {
  return {
    type: "@nckrtl/firewall-direct",
    version: "2026.10.30.2",
    globalArguments: Globals,
    methods: {
      apply: {
        description: "Execute one admitted Firewall intent on its Node worker",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = Rule.parse(raw);
              if (
                input.node_id !== args.node_id ||
                (args.kind !== "firewall.remove" &&
                  input.action !== args.kind.slice(9))
              ) {
                throw new Error("Firewall action differs from admitted intent");
              }
              const result = Result.parse(
                await run({
                  ...input,
                  operation: args.kind === "firewall.remove"
                    ? "remove"
                    : "converge",
                }),
              );
              const error_code = result.error_code ??
                (result.backend === "inactive"
                  ? "firewall.backend_inactive"
                  : null);
              if (error_code) return { ...result, error_code };
              if (
                result.backend !==
                  (args.kind === "firewall.remove" ? "absent" : "active")
              ) {
                throw new Error(
                  "The local Firewall action has no verified backend",
                );
              }
              return result;
            },
            Result.parse,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = {
  ...directFirewall(),
  type: "@nckrtl/firewall-direct",
};
