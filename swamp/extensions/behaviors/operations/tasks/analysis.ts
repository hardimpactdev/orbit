import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import { withTaskCancellation } from "./cancellation.ts";
import { type ScriptExecutor, workspaceProgram } from "./script.ts";
import { AnalysisProgram } from "./analysis_program.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
export const AnalysisInput = z.object({
  group_id: Id,
  instance_id: Id,
  node_id: Id,
  subtask: Id,
  checkout_path: z.string().startsWith("/"),
  ssr_port: z.number().int().min(1).max(65535).nullable(),
  plan: z.object({ id: z.string(), revision: Id }).passthrough(),
  plan_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  base: Sha,
  head: Sha,
  tree: Sha,
  repository: z.string().min(1),
  branch: z.string().min(1),
});
const Failure = z.object({ error_code: z.string().regex(/^[a-z0-9_.]+$/) })
  .passthrough();
const NativeResult = z.union([
  Failure,
  z.object({ cancelled: z.literal(true) }).strict(),
  z.object({
    outcome: z.enum(["ready", "action_required", "blocked", "changed"]),
    artifact: z.object({
      sha256: z.string().regex(/^[a-f0-9]{64}$/),
      bytes: z.number().int().min(1).max(65536),
      content: z.string(),
    }).strict(),
    provenance: z.record(z.string(), z.unknown()),
  }).strict(),
]);
const invoke: ScriptExecutor = (input, signal) =>
  workspaceProgram(AnalysisProgram, input, signal);
export async function analyze(
  input: z.infer<typeof AnalysisInput>,
  operationId: number,
  executor: ScriptExecutor = invoke,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const result = NativeResult.parse(
    await executor({
      ...input,
      operation_id: operationId,
      execution_key: `operation-${operationId}`,
    }, signal),
  );
  if ("cancelled" in result && result.cancelled) {
    throw new DirectOperationCancelled({ effectRunning: false });
  }
  return result;
}
const Args = DirectArgs.extend({
  node_id: Id,
  kind: z.literal("tasks.analyze"),
});
export const analysisMethod = {
  description: "Run the fixed repository analyzer in the owned task VM",
  arguments: Args,
  execute: (
    args: z.infer<typeof Args>,
    ctx: { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal },
  ) => {
    const gateway = new GatewayOperation(
      gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
      args,
    );
    return directOperation(
      args,
      gateway,
      async (raw) => {
        const input = AnalysisInput.parse(raw);
        if (input.node_id !== args.node_id) {
          throw new Error("Wrong task VM Node");
        }
        return await withTaskCancellation(
          gateway,
          (signal) => analyze(input, args.operation_id, invoke, signal),
          ctx.signal,
        );
      },
      (result) => result,
      ctx.signal,
    );
  },
};
