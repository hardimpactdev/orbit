import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import { AccountIntent, updateAiRouterAccount } from "./accounts.ts";

const Args = DirectArgs.extend({ kind: z.literal("ai_router.account_update") });
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

export function aiRouterAccounts(
  makeHttp = (ctx: Context): Http =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  update = updateAiRouterAccount,
) {
  return {
    type: "@nckrtl/ai-router-accounts",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      update: {
        description:
          "Read, update and confirm one admitted CLIProxyAPI account",
        arguments: Args,
        writes: true,
        async execute(raw: z.infer<typeof Args>, ctx: Context) {
          const args = Args.parse(raw);
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = AccountIntent.parse(raw);
              const guard = async () => {
                const current = z.object({
                  id: z.literal(args.operation_id),
                  kind: z.literal(args.kind),
                  node_id: z.literal(args.node_id),
                  status: z.enum(["running", "queued"]),
                  cancel_requested: z.literal(false),
                  input: AccountIntent,
                }).parse(await gateway.read(ctx.signal));
                if (JSON.stringify(current.input) !== JSON.stringify(input)) {
                  throw new Error("ai_router.intent_changed");
                }
              };
              await guard();
              return await update(input, gateway, guard, ctx.signal);
            },
            (value) => value,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = {
  ...aiRouterAccounts(),
  type: "@nckrtl/ai-router-accounts",
};
