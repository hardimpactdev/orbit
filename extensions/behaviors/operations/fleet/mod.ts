import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { type Context, Globals } from "../../operation-runner/mod.ts";
import { maintenance, MaintenanceIntent } from "./maintenance.ts";

/** Scheduled admission only. Each admitted Operation starts its native workflow. */
export function fleet(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-fleet",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      maintenance: {
        description:
          "Saved maintenance admission intent and Operation identity",
        schema: MaintenanceIntent,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      maintenance: {
        description:
          "Admit scheduled recipe, DNS and ordered rollout Operations",
        arguments: z.object({ tick: z.string().min(1) }),
        execute: (args: { tick: string }, ctx: Context) =>
          maintenance(args, ctx, makeHttp(ctx)),
      },
    },
  };
}
export const behavior = fleet();
