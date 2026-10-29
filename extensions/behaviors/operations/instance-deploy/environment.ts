import { type Completion, type DeploymentPort } from "./contract.ts";

/** The Gateway binds each write; Swamp orders the reusable environment child. */
export async function synchronizeEnvironment(
  instanceId: unknown,
  port: Pick<DeploymentPort, "cancelled" | "phase" | "result">,
): Promise<Completion> {
  for (
    const name of [
      "environment_prepare",
      "environment_sync",
      "environment_testing",
      "environment_urls",
      "environment_receipt",
    ]
  ) {
    if (await port.cancelled()) {
      return {
        status: "cancelled",
        result: { instance_id: instanceId },
        error_code: null,
      };
    }
    const receipt = await port.phase({ name, required: true, cleanup: false });
    if (typeof receipt.error_code === "string") {
      return {
        status: receipt.error_code === "operation.cancelled"
          ? "cancelled"
          : "failed",
        result: { ...receipt, instance_id: instanceId },
        error_code: receipt.error_code,
      };
    }
  }
  return {
    status: "succeeded",
    result: await port.result(),
    error_code: null,
  };
}
