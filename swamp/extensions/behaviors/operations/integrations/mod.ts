import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { type Context, Globals } from "../_lib/direct.ts";
import { maintainConn } from "../conn/maintenance.ts";

/** Retained Conn maintenance entrypoint; native roots execute integration effects. */
export function integrations(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-integrations",
    version: "2026.11.01.1",
    globalArguments: Globals,
    methods: {
      maintenance: {
        description: "Admit pending Conn registration session cleanup",
        arguments: z.object({ tick: z.string().min(1).max(128) }),
        execute: async (args: { tick: string }, ctx: Context) =>
          await maintainConn(makeHttp(ctx), args.tick),
      },
    },
  };
}

export const behavior = {
  ...integrations(),
  type: "@nckrtl/operation-integrations",
};
