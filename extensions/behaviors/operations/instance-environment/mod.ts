import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import {
  EnvironmentPlan,
  EnvironmentRefusal,
  refreshEnvironmentRuntime,
  synchronizeEnvironment,
} from "./sync.ts";
const Args = DirectArgs.extend({
  kind: z.literal("instance.sync_environment"),
  node_id: z.number().int().positive(),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function environmentRoot(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  sync = synchronizeEnvironment,
  refresh = refreshEnvironmentRuntime,
) {
  return {
    type: "@nckrtl/instance-environment",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      apply: {
        arguments: Args,
        description:
          "Synchronize the admitted Instance environment locally in one native root",
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = z.object({
                instance_id: z.number().int().positive(),
                direct_environment: EnvironmentPlan,
              }).parse(raw);
              if (
                input.direct_environment.node_id !== args.node_id ||
                input.instance_id !== input.direct_environment.instance_id
              ) throw new Error("Environment placement mismatch");
              try {
                const result = await sync(
                  input.direct_environment,
                  gateway.withPrivateMaterial.bind(gateway),
                  undefined,
                  ctx.signal,
                );
                const runtime = await refresh(
                  input.direct_environment,
                  undefined,
                  ctx.signal,
                );
                return { ...result, ...runtime };
              } catch (error) {
                if (!(error instanceof EnvironmentRefusal)) throw error;
                return { error_code: error.code, failed_step: error.stage };
              }
            },
            (value) => value,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = environmentRoot();
