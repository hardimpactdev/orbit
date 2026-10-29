import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { Completion, ErrorCode } from "./contract.ts";
import { NodeExecution } from "./execution.ts";

const Prepare = z.object({
  context_node_id: z.number().int().positive(),
  excluded_node_id: z.number().int().positive().nullable(),
});
const Projection = z.object({
  configuration: z.object({
    $secret: z.literal("node.vpn_configuration"),
    id: z.number().int().positive(),
  }).strict(),
  excluded_node_id: z.number().int().positive().nullable(),
}).strict();
export async function projectWireGuard(
  execution: NodeExecution,
): Promise<z.infer<typeof Completion>> {
  const outcome = (
    result: Record<string, unknown>,
  ): z.infer<typeof Completion> => {
    const error = result.error_code === undefined
      ? null
      : ErrorCode.parse(result.error_code);
    return {
      status: error === "operation.cancelled"
        ? "cancelled"
        : error
        ? "failed"
        : "succeeded",
      result,
      error_code: error,
    };
  };
  if (
    execution.operation.cancel_requested &&
    execution.operation.steps.length === 0
  ) return { status: "cancelled", result: {}, error_code: null };
  const prepared = await execution.run(
    "prepare-wireguard",
    "gateway",
    "node.prepare-wireguard",
    Prepare.parse(execution.operation.input),
  );
  if (prepared.error_code) return outcome(prepared);
  const operation = await execution.refresh();
  const input = Projection.parse(operation.input._wireguard);
  if (input.configuration.id !== operation.node_id) {
    throw new ExecutorError("operation.execution_forbidden");
  }
  const result = await execution.run(
    "hub-project",
    "node-wireguard",
    "wireguard.project-hub",
    input,
  );
  if (!result.error_code && result.projected !== true) {
    throw new ExecutorError("operation.completion_unproven");
  }
  return outcome(result);
}
