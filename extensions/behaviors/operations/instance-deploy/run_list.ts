import { z } from "npm:zod@4.4.3";
import {
  AppStep,
  type AppStepResult,
  sha256,
  StepTarget,
  sudoAppStep,
} from "./app_step.ts";
import { lifecycleFailure } from "./lifecycle.ts";

export const ProjectStepList = z.object({
  instance_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  environment: z.literal("development"),
  list: z.enum(["setup", "teardown"]),
  execution: StepTarget.refine((target) => target.variant === "checkout"),
  steps: z.array(
    AppStep.pick({ name: true, command_digest: true, timeout_seconds: true }),
  ).max(32),
}).strict().refine((list) =>
  new Set(list.steps.map((row) => row.name)).size === list.steps.length
);
export type ProjectStepList = z.infer<typeof ProjectStepList>;
export type StepListResult = {
  instance_id: number;
  outcome: "succeeded";
  no_op: boolean;
} | {
  instance_id: number;
  outcome: "failed" | "missing" | "busy" | "deadline" | "unconfirmed";
  error_code: string;
  failed_step: string | null;
} | { cancelled: true };
export type StepListPort = {
  /** The parent consumer extracts this list from its canonical admitted input. */
  read: () => Promise<{ input: unknown; cancel_requested: boolean }>;
  /** Script bytes exist only during this callback, never in native arguments or results. */
  program: (
    step: AppStep,
    execute: (script: string) => Promise<void>,
  ) => Promise<void>;
  run?: typeof sudoAppStep;
};

/** Native parent step action. It neither starts nor projects a Gateway Operation. */
export async function runProjectStepList(
  raw: ProjectStepList,
  port: StepListPort,
  signal?: AbortSignal,
): Promise<StepListResult> {
  const admitted = ProjectStepList.parse(raw);
  for (const row of admitted.steps) {
    const failed_step = `${admitted.list}.${row.name}`;
    const refused = (
      error_code: string,
      outcome: "missing" | "unconfirmed" = "unconfirmed",
    ): StepListResult => ({
      instance_id: admitted.instance_id,
      outcome,
      error_code,
      failed_step,
    });
    try {
      const current = await port.read();
      if (current.cancel_requested || signal?.aborted) {
        return { cancelled: true };
      }
      if (
        JSON.stringify(ProjectStepList.parse(current.input)) !==
          JSON.stringify(admitted)
      ) return refused("instance.step_not_admitted");
    } catch {
      return refused("instance.step_not_admitted");
    }
    const step = AppStep.parse({
      ...row,
      instance_id: admitted.instance_id,
      project_id: admitted.project_id,
      target: admitted.execution,
      phase: admitted.list,
    });
    let result: AppStepResult = {
      error_code: "instance.step_execution_unconfirmed",
    };
    let attempted = false, accepting = true;
    try {
      await port.program(step, async (script) => {
        // Capture all outcomes inside the private callback, whose transport suppresses errors.
        if (!accepting || attempted) return;
        attempted = true;
        if (signal?.aborted) {
          result = { error_code: "operation.cancelled" };
          return;
        }
        if (
          typeof script !== "string" ||
          new TextEncoder().encode(script).length > 16384 ||
          script.includes("\0") || await sha256(script) !== step.command_digest
        ) {
          result = { error_code: "instance.step_not_admitted" };
          return;
        }
        if (admitted.list === "teardown" && script === "@orbit/ci-caches") {
          result = { error_code: "instance.teardown_step_unavailable" };
          return;
        }
        try {
          result = await (port.run ?? sudoAppStep)(step, script, signal);
        } catch {
          result = { error_code: "instance.step_execution_unconfirmed" };
        }
      });
    } catch {
      // A failed read admits no effect; a lost callback response cannot prove an effect failed.
      if (!attempted) result = { error_code: "instance.step_not_admitted" };
    } finally {
      accepting = false;
    }
    if (
      signal?.aborted ||
      ("error_code" in result && result.error_code === "operation.cancelled")
    ) return { cancelled: true };
    if (
      "error_code" in result &&
      result.error_code === "instance.step_not_admitted"
    ) return refused(result.error_code);
    if (
      "error_code" in result &&
      result.error_code === "instance.teardown_step_unavailable"
    ) return refused(result.error_code, "missing");
    const failure = lifecycleFailure(
      admitted.list,
      result,
      step.timeout_seconds,
      step.timeout_seconds,
    );
    if (failure?.outcome === "cancelled") return { cancelled: true };
    if (failure) {
      return {
        instance_id: admitted.instance_id,
        error_code: failure.error_code,
        outcome: failure.outcome,
        failed_step,
      };
    }
  }
  return {
    instance_id: admitted.instance_id,
    outcome: "succeeded",
    no_op: admitted.steps.length === 0,
  };
}
