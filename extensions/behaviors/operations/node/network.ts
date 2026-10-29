import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { Completion, ErrorCode, ResultObject } from "./contract.ts";
import { NodeExecution } from "./execution.ts";

const Descriptor = z.object({
  key: z.string(),
  transport: z.enum(["gateway", "bootstrap-ssh", "agent"]),
  name: z.enum([
    "node.prepare-network",
    "node.network-result",
    "wireguard.prepare-peer-key",
    "wireguard.publish-peer",
    "wireguard.rollback-peer",
    "agent.status",
    "firewall.rules",
  ]),
  input: ResultObject,
});
const Child = z.object({
  id: z.number().int().positive(),
  key: z.string(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  status: z.string(),
});

/** The Gateway exposes only the next proven stage, including native rollback after refusal. */
export async function network(
  execution: NodeExecution,
): Promise<z.infer<typeof Completion> | { waiting: true }> {
  for (let stage = 0; stage < 12; stage++) {
    await execution.refresh();
    const descriptors = z.array(Descriptor).parse(
      execution.operation.execution_context?.descriptors,
    );
    const next = descriptors.find((descriptor) =>
      !Object.hasOwn(execution.operation.step_results, descriptor.key)
    );
    if (next) {
      const result = await execution.run(
        next.key,
        next.transport,
        next.name,
        next.input,
      );
      if (next.key === "network-result") {
        const error = result.error_code
          ? ErrorCode.parse(result.error_code)
          : null;
        if (result.network_proven !== true) {
          throw new ExecutorError("operation.completion_unproven");
        }
        return {
          status: error === "operation.cancelled"
            ? "cancelled"
            : error
            ? "failed"
            : "succeeded",
          result,
          error_code: error,
        };
      }
      if (
        result.error_code === "operation.cancelled" &&
        !Object.hasOwn(execution.operation.step_results, "network-publish")
      ) {
        return { status: "cancelled", result: {}, error_code: null };
      }
      continue;
    }
    if (Object.hasOwn(execution.operation.step_results, "network-result")) {
      const result = ResultObject.parse(
        execution.operation.step_results["network-result"],
      );
      const error = result.error_code
        ? ErrorCode.parse(result.error_code)
        : null;
      return {
        status: error === "operation.cancelled"
          ? "cancelled"
          : error
          ? "failed"
          : "succeeded",
        result,
        error_code: error,
      };
    }
    const key = ResultObject.parse(
      execution.operation.step_results["network-key"],
    );
    if (key.error_code) {
      throw new ExecutorError("operation.completion_unproven");
    }
    const children = z.array(Child).parse(await execution.get("children"));
    const child = children.find((entry) => entry.key === "network-hub");
    if (
      child &&
      (child.kind !== "node.wireguard_hub_converge" ||
        child.node_id !==
          ResultObject.parse(execution.operation.input._network).hub_id)
    ) {
      throw new ExecutorError("operation.completion_unproven");
    }
    if (!child) await execution.child("network-hub");
    await execution.wait(["network-hub"]);
    return { waiting: true };
  }
  throw new ExecutorError("operation.completion_unproven");
}
