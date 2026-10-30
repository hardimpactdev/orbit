import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { cleanupAsOwner } from "./cleanup_local.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";

const Args = DirectArgs.extend({
  kind: z.literal("database.sqlite_cleanup"),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};

/** Native behavior locking serializes each Node's cleanup step. */
export function cleanup(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  runLocal = cleanupAsOwner,
) {
  return {
    type: "@nckrtl/database-cleanup",
    version: "2026.10.30.3",
    globalArguments: Globals,
    methods: {
      cleanup: {
        description: "Remove one admitted SQLite snapshot",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            runLocal,
            (result) => {
              if (
                result.error_code === undefined && result.backend !== "absent"
              ) {
                throw new Error("Database cleanup has no successful result");
              }
              return result;
            },
            ctx.signal,
          );
        },
      },
    },
  };
}

export const behavior = cleanup();
