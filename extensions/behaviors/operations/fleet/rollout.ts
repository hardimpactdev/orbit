import { z } from "npm:zod@4.4.3";
import {
  type Context,
  ExecutorError,
  Operation,
} from "../../operation-runner/mod.ts";
import type { Receipt } from "./effects.ts";
import type { Outcome } from "./recipe.ts";

type Post = (suffix: string, body: Record<string, unknown>) => Promise<unknown>;
const Result = z.record(z.string(), z.unknown());
const Child = z.object({
  id: z.number().int().positive(),
  status: z.string(),
  error_code: z.string().nullable().optional(),
  result: Result.nullable().optional(),
});
export async function executeRollout(
  operation: z.infer<typeof Operation>,
  ctx: Context,
  post: Post,
  readChild: (id: number) => Promise<z.infer<typeof Child>>,
  readReceipt: (key: string) => Promise<z.infer<typeof Receipt>>,
): Promise<Outcome | null> {
  const input = z.object({
    node_id: z.number(),
    user: z.string(),
    release: Result,
    profile: z.string(),
    downgrade_to: z.string().nullable(),
    repairs: z.array(z.string()),
    first_visit: z.boolean(),
  }).parse(operation.input);
  if (input.node_id !== operation.node_id) {
    throw new Error("Wrong rollout Node");
  }
  const fail = (code: string, result: Record<string, unknown>): Outcome => ({
    status: code === "operation.cancelled" ? "cancelled" : "failed",
    result,
    error_code: code,
  });
  const uncertain = (error: unknown) =>
    !(error instanceof ExecutorError) ||
    [
      "operation.execution_failed",
      "operation.outcome_unknown",
      "operation.execution_uncertain",
      "operation.receipt_unavailable",
    ].includes(error.code);
  const invoke = async (
    key: string,
    name: string,
    body: Record<string, unknown>,
    attempt = 0,
  ): Promise<Record<string, unknown>> => {
    let result: Record<string, unknown> | null = null;
    let receipt: z.infer<typeof Receipt> | null = null;
    let lost: unknown;
    try {
      result = Result.parse(
        await post("run", { key, transport: "agent", name, input: body }),
      );
    } catch (error) {
      if (!uncertain(error)) throw error;
      lost = error;
      try {
        receipt = await readReceipt(key);
      } catch (readError) {
        if (!uncertain(readError)) throw readError;
      }
      if (receipt?.state === "running") {
        throw new ExecutorError("operation.effect_running");
      }
      if (receipt?.state === "succeeded" || receipt?.state === "failed") {
        result = receipt.result;
        if (result === null) {
          throw new ExecutorError("operation.receipt_unavailable");
        }
      }
    }
    const transportFailed = result?.error_code === "node.ssh_unreachable" ||
      result?.outcome === "failed" && result.exit_code === 255 &&
        result.report_present !== true;
    const retryableInstall = name === "cli.install" &&
      [
        "cli.download_failed",
        "cli.checksum_mismatch",
        "cli.candidate_invalid",
        "cli.install_failed",
      ].includes(String(result?.error_code));
    if (result !== null && !transportFailed && !retryableInstall) return result;
    const probe = Result.parse(
      await post("run", {
        key: `${key}${
          attempt === 1 && !key.endsWith("-retry") ? "-retry" : ""
        }-probe`,
        transport: "gateway",
        name: "fleet.probe",
        input: {},
      }),
    );
    if (probe.reachable === false) {
      return { error_code: "node.ssh_unreachable" };
    }
    if (result === null && receipt?.state !== "unknown") throw lost;
    if (attempt === 1) {
      return result ?? { error_code: "fleet.cli_transport_failed" };
    }
    // Unknown means the original key has no target effect. A proven failed command gets one new attempt.
    return await invoke(result === null ? key : `${key}-retry`, name, body, 1);
  };
  const effect = async (
    step: string,
    name: string,
    body: Record<string, unknown>,
  ) => {
    if (operation.step_results[step]) {
      return Result.parse(operation.step_results[step]);
    }
    await post("step", { key: `${step}:start`, name: step, status: "running" });
    return await invoke(step, name, body);
  };
  const finish = async (
    step: string,
    result: Record<string, unknown>,
    error: string | null,
  ) => {
    await post("step", {
      key: `${step}:finish`,
      name: step,
      status: error ? "failed" : "succeeded",
      result,
      error_code: error,
    });
  };
  if (operation.input.reachable === false) {
    return fail("node.ssh_unreachable", {});
  }
  const installed = await effect("cli-install", "cli.install", {
    user: input.user,
    release: input.release,
    profile: input.profile,
  });
  let error = typeof installed.error_code === "string"
    ? installed.error_code === "cli.update_busy"
      ? "node.update_busy"
      : installed.error_code
    : null;
  if (!operation.step_results["cli-install"]) {
    await finish("cli-install", installed, error);
  }
  if (error) return fail(error, installed);
  let update = await effect("self-update", "cli.self-update", {
    user: input.user,
    downgrade_to: null,
  });
  if (
    update.error_code === "self_update.downgrade_refused" &&
    input.downgrade_to !== null
  ) {
    update = await invoke("self-update-downgrade", "cli.self-update", {
      user: input.user,
      downgrade_to: input.downgrade_to,
    });
  }
  error = typeof update.error_code === "string"
    ? update.error_code
    : update.outcome === "pending"
    ? "fleet.release_pending"
    : update.outcome === "incomplete"
    ? "fleet.self_update_incomplete"
    : !["updated", "unchanged"].includes(String(update.outcome))
    ? "fleet.self_update_failed"
    : null;
  if (!operation.step_results["self-update"]) {
    await finish("self-update", update, error);
  }
  if (error) return fail(error, update);
  const resource = `operation-${operation.id}-footprint`;
  let child = await ctx.readResource(resource);
  if (!child) {
    child = { key: "footprint" };
    await ctx.writeResource("child", resource, child);
  }
  let id = typeof child.id === "number" ? child.id : null;
  if (id === null) {
    id = Child.pick({ id: true }).parse(
      await post("children", { key: "footprint" }),
    ).id;
    await ctx.writeResource("child", resource, { ...child, id });
  }
  const footprint = await readChild(id);
  if (!["succeeded", "failed", "cancelled"].includes(footprint.status)) {
    return null;
  }
  if (footprint.status !== "succeeded") {
    return fail(footprint.error_code ?? "node.footprint_failed", {});
  }
  const proven = operation.step_results.verify;
  if (proven) {
    return {
      status: "succeeded",
      result: Result.parse(proven),
      error_code: null,
    };
  }
  await post("step", {
    key: "verify:start",
    name: "verify",
    status: "running",
  });
  const result = Result.parse(
    await post("run", {
      key: "verify",
      transport: "gateway",
      name: "fleet.verify",
      input: { mode: "rollout" },
    }),
  );
  error = typeof result.error_code === "string"
    ? result.error_code
    : result.passed === true
    ? null
    : "fleet.verify_failed";
  await finish("verify", result, error);
  return error
    ? fail(error, result)
    : { status: "succeeded", result, error_code: null };
}
