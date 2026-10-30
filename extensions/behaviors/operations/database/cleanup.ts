import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";

const Kind = z.enum(["database.sqlite_cleanup"]);
const Args = z.object({
  operation_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  kind: Kind,
  run_id: z.string().uuid(),
});
const Record = z.preprocess(
  (value) => Array.isArray(value) && value.length === 0 ? {} : value,
  z.record(z.string(), z.unknown()),
);
const Outcome = z.object({
  id: z.number().int().positive(),
  kind: Kind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  result: Record.nullable(),
  cancel_requested: z.boolean(),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};

/** Native behavior locking serializes each Node's entire cleanup step. */
export function cleanup(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
    ),
) {
  return {
    type: "@nckrtl/database-cleanup",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      cleanup: {
        description: "Remove one admitted SQLite snapshot",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const http = makeHttp(ctx);
          const path = `/api/v1/database-cleanups/${args.operation_id}`;
          const call = async (
            method: "GET" | "POST",
            suffix: string,
            body?: unknown,
          ) => {
            const response = await http(method, `${path}${suffix}`, body);
            if (response.status < 200 || response.status >= 300) {
              // No local retry policy: the native Swamp step owns recovery.
              throw new Error("Database cleanup request did not succeed");
            }
            return z.object({ data: z.unknown() }).parse(response.body).data;
          };
          const current = Outcome.parse(
            await call("GET", `?run_id=${args.run_id}`),
          );
          if (
            current.id !== args.operation_id || current.kind !== args.kind ||
            current.node_id !== args.node_id
          ) throw new Error("Database cleanup does not match admitted intent");
          if (["succeeded", "failed", "cancelled"].includes(current.status)) {
            if (current.status === "failed") {
              throw new Error(
                String(
                  current.result?.error_code ?? "database.cleanup_failed",
                ),
              );
            }
            return { status: current.status };
          }
          const started_at = new Date().toISOString();
          await call("POST", "/result", {
            run_id: args.run_id,
            status: "running",
            started_at,
          });
          let result: z.infer<typeof Record> = {};
          let status: "succeeded" | "failed" | "cancelled";
          if (current.cancel_requested || ctx.signal?.aborted) {
            status = "cancelled";
          } else {
            result = Record.parse(
              await call("POST", "/cleanup", { run_id: args.run_id }),
            );
            const code = result.error_code;
            if (
              code !== undefined &&
              !z.string().regex(/^[a-z0-9_.-]+$/).safeParse(code).success
            ) {
              throw new Error("Invalid Database cleanup refusal");
            }
            if (code === undefined && result.backend !== "absent") {
              throw new Error("Database cleanup has no successful result");
            }
            status = code === undefined ? "succeeded" : "failed";
          }
          await call("POST", "/result", {
            run_id: args.run_id,
            status,
            started_at,
            finished_at: new Date().toISOString(),
            result,
            error_code: result.error_code ?? null,
          });
          if (status === "failed") throw new Error(String(result.error_code));
          return { status };
        },
      },
    },
  };
}

export const behavior = cleanup();
