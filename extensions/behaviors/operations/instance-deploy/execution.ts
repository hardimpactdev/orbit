import {
  type Completion,
  type Configuration,
  type DeploymentPort,
  DeploymentRefused,
  phases,
} from "./contract.ts";

/** The phase order is owned by Swamp; the Gateway checks intent and evidence. */
export async function deploy(
  config: Configuration,
  port: DeploymentPort,
): Promise<Completion> {
  let activated = await port.activated();
  for (const phase of phases(config)) {
    if (
      await port.cancelled() && !activated
    ) {
      return {
        status: "cancelled",
        error_code: null,
        result: await port.result(),
      };
    }
    let error: string | null = null;
    try {
      const receipt = await port.phase(phase);
      error = typeof receipt.error_code === "string"
        ? receipt.error_code
        : null;
      if (
        !error && typeof receipt.exit_code === "number" &&
        receipt.exit_code !== 0
      ) {
        error = receipt.timed_out
          ? "deployment.command_timed_out"
          : "deployment.step_failed";
      }
    } catch (failure) {
      // A transport error is not proof of failure. Resume with the same Operation and keys.
      if (!(failure instanceof DeploymentRefused)) throw failure;
      error = failure.code;
    }
    if (error === "operation.cancelled") {
      return {
        status: "cancelled",
        error_code: error,
        result: await port.result(),
      };
    }
    // Only an executed user command may be best-effort. Access, lease, validation and
    // uncertain-outcome refusals always stop the deployment.
    if (
      error && !phase.cleanup &&
      (phase.required || error !== "deployment.step_failed")
    ) {
      return {
        status: "failed",
        error_code: error,
        result: await port.result(),
      };
    }
    if (phase.name === "activation" && !error) activated = true;
  }
  return { status: "succeeded", error_code: null, result: await port.result() };
}
