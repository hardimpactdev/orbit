import { z } from "npm:zod@4.4.3";
import {
  AppStep,
  AppStepResult,
  AppVpRuntime,
  sha256,
  StepTarget,
  sudoAppStep,
} from "./app_step.ts";

export const DeployAppInput = z.object({
  instance_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  target: StepTarget,
  vp_environment: AppVpRuntime.nullable().optional(),
  steps: z.array(
    AppStep.pick({
      name: true,
      phase: true,
      command_digest: true,
      timeout_seconds: true,
    }).extend({
      phase: z.enum(["before_activation", "after_activation"]),
      required: z.boolean(),
    }).strict(),
  ).max(32),
}).strict().superRefine((input, ctx) => {
  const names = new Set<string>();
  for (const step of input.steps) {
    if (
      names.has(step.name) ||
      (input.target.variant === "checkout" &&
        step.phase !== "before_activation")
    ) ctx.addIssue({ code: "custom", message: "Invalid admitted deploy list" });
    names.add(step.name);
  }
  if (input.steps.reduce((n, step) => n + step.timeout_seconds, 0) > 3600) {
    ctx.addIssue({
      code: "custom",
      message: "Invalid admitted deploy timeout",
    });
  }
});
export type DeployAppInput = z.infer<typeof DeployAppInput>;
type Phase = "before_activation" | "after_activation";
type Failure = { error_code: string; failed_step: string | null };
type PhaseResult = Failure | { completed: number; warnings: number };
type Port = {
  /** Canonical direct read revalidates the current target and entire admitted list. */
  read: () => Promise<{ input: unknown; cancel_requested: boolean }>;
  /** The parent selects the admitted command; private bytes stay inside this callback. */
  program: (
    step: AppStep,
    execute: (script: string) => Promise<void>,
  ) => Promise<void>;
  run?: typeof sudoAppStep;
  now?: () => number;
};

function same(left: DeployAppInput, right: DeployAppInput) {
  return left.instance_id === right.instance_id &&
    left.project_id === right.project_id &&
    JSON.stringify(left.vp_environment ?? null) ===
      JSON.stringify(right.vp_environment ?? null) &&
    JSON.stringify(left.target) === JSON.stringify(right.target) &&
    left.steps.length === right.steps.length &&
    left.steps.every((step, index) => {
      const other = right.steps[index];
      return step.name === other.name && step.phase === other.phase &&
        step.command_digest === other.command_digest &&
        step.timeout_seconds === other.timeout_seconds &&
        step.required === other.required;
    });
}

/** Swamp owns the list and full deadlines. No PHP runner or keyed effect receipt. */
export async function runDeployPhase(
  input: DeployAppInput,
  phase: Phase,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<PhaseResult> {
  const admitted = DeployAppInput.parse(input);
  const now = port.now ?? Date.now;
  if (!Number.isFinite(deadline)) {
    return { error_code: "deployment.deadline_exceeded", failed_step: null };
  }
  let completed = 0, warnings = 0;
  for (const row of admitted.steps.filter((step) => step.phase === phase)) {
    const failed_step = `${phase}.${row.name}`;
    try {
      const current = await port.read();
      if (current.cancel_requested || signal?.aborted) {
        return { error_code: "operation.cancelled", failed_step };
      }
      if (!same(admitted, DeployAppInput.parse(current.input))) {
        return { error_code: "deployment.identity_changed", failed_step };
      }
    } catch {
      return { error_code: "deployment.identity_changed", failed_step };
    }
    const step = AppStep.parse({
      name: row.name,
      phase: row.phase,
      command_digest: row.command_digest,
      timeout_seconds: row.timeout_seconds,
      instance_id: admitted.instance_id,
      project_id: admitted.project_id,
      target: admitted.target,
      vp_environment: admitted.vp_environment,
    });
    let outcome: z.infer<typeof AppStepResult> = {
      error_code: "instance.step_not_admitted",
    };
    let attempted = false, accepting = true;
    try {
      await port.program(step, async (program) => {
        if (!accepting || attempted) return;
        attempted = true;
        if (signal?.aborted) {
          outcome = { error_code: "operation.cancelled" };
          return;
        }
        if (
          typeof program !== "string" ||
          new TextEncoder().encode(program).length > 16384 ||
          program.includes("\0") ||
          await sha256(program) !== step.command_digest
        ) return;
        if (now() + step.timeout_seconds * 1000 > deadline) {
          outcome = { error_code: "deployment.deadline_exceeded" };
          return;
        }
        outcome = { error_code: "deployment.interrupted" };
        try {
          outcome = AppStepResult.parse(
            await (port.run ?? sudoAppStep)(step, program, signal),
          );
        } catch {
          /* Private callback errors never become public diagnostics. */
        }
      });
    } catch {
      // A transport may fail after invoking its callback. Preserve the local outcome.
    } finally {
      accepting = false;
    }
    if (signal?.aborted) {
      return { error_code: "operation.cancelled", failed_step };
    }
    const result = AppStepResult.parse(outcome);
    if ("error_code" in result) {
      return { error_code: result.error_code, failed_step };
    }
    if (result.timed_out) {
      return { error_code: "deployment.command_timed_out", failed_step };
    }
    if (result.exit_code !== 0) {
      if (row.required) {
        return { error_code: "deployment.step_failed", failed_step };
      }
      warnings++;
    }
    completed++;
  }
  return { completed, warnings };
}
