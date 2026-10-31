import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { DirectArgs, GatewayOperation } from "../_lib/direct.ts";
import { createUser, UserResult } from "./user_create_action.ts";
import { runNodeAgent } from "../_lib/node-agent.ts";

const Args = DirectArgs.extend({
  kind: z.literal("database_user.create"),
  node_id: z.number().int().positive(),
});
const Empty = z.object({}).strict();
const Result = z.preprocess(
  (raw) => Array.isArray(raw) && raw.length === 0 ? {} : raw,
  z.union([UserResult, Empty]),
);
const Projection = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: Result,
  error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).nullable(),
  started_at: z.string().datetime(),
  finished_at: z.string().datetime(),
}).strict();
const Read = z.object({
  id: z.number().int().positive(),
  kind: z.literal("database_user.create"),
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  result: Result.nullable(),
  input: z.record(z.string(), z.unknown()),
  cancel_requested: z.boolean(),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<{ name: string }>;
};

/** Native step outputs separate SQL execution from terminal record projection. */
export function userCreate(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke = runNodeAgent,
) {
  return {
    type: "@nckrtl/database-user-create",
    version: "2026.10.30.1",
    globalArguments: Globals,
    resources: {
      outcome: {
        description: "Safe outcome passed to this workflow's projection step",
        schema: z.object({ outcome: Projection }),
        lifetime: "workflow",
        garbageCollection: 1,
      },
    },
    methods: {
      create: {
        description:
          "Apply the admitted SQL user and emit only its safe outcome",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          try {
            const args = Args.parse(raw);
            const gateway = new GatewayOperation(makeHttp(ctx), args);
            const current = Read.parse(await gateway.read(ctx.signal));
            if (
              current.id !== args.operation_id ||
              current.node_id !== args.node_id
            ) {
              throw new Error("Wrong admitted identity");
            }
            const started_at = new Date().toISOString();
            let status: z.infer<typeof Projection>["status"];
            let result: z.infer<typeof Result> = {};
            if (["succeeded", "failed", "cancelled"].includes(current.status)) {
              status = z.enum(["succeeded", "failed", "cancelled"]).parse(
                current.status,
              );
              result = Result.parse(current.result ?? {});
            } else {
              await gateway.project({ status: "running", started_at });
              if (current.cancel_requested || ctx.signal?.aborted) {
                status = "cancelled";
              } else {
                result = UserResult.parse(
                  await createUser(
                    current.input,
                    (use) => gateway.withPrivateMaterial(use),
                    ctx.signal,
                    invoke,
                  ),
                );
                status = "error_code" in result ? "failed" : "succeeded";
              }
            }
            const outcome = Projection.parse({
              status,
              result,
              started_at,
              finished_at: new Date().toISOString(),
              error_code: "error_code" in result ? result.error_code : null,
            });
            return {
              dataHandles: [
                await ctx.writeResource("outcome", "outcome", { outcome }),
              ],
            };
          } catch {
            throw new Error("database.user_create_outcome_unknown");
          }
        },
      },
      project: {
        description:
          "Project this run's saved native SQL outcome without repeating SQL",
        arguments: Args.extend({ outcome: Projection }),
        execute: async (
          raw: z.infer<typeof Args> & { outcome: z.infer<typeof Projection> },
          ctx: Context,
        ) => {
          const args = Args.extend({ outcome: Projection }).parse(raw);
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          await gateway.project(args.outcome);
          if (args.outcome.status === "failed") {
            throw new Error(
              args.outcome.error_code ?? "database.user_create_failed",
            );
          }
          return { status: args.outcome.status };
        },
      },
    },
  };
}

export const behavior = userCreate();
