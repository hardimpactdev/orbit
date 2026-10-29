import { z } from "npm:zod@4.4.3";

export const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
export const Phase = z.enum(["setup", "teardown"]);
export type Phase = z.infer<typeof Phase>;
export const LifecycleResult = z.object({
  exit_code: z.number().int(),
  timed_out: z.boolean(),
});
export const Refusal = z.object({ error_code: ErrorCode });
export type Failure = {
  error_code: string;
  outcome:
    | "busy"
    | "missing"
    | "failed"
    | "unconfirmed"
    | "deadline"
    | "cancelled";
};

/** Only a proven command failure can authorize create rollback. */
export function lifecycleFailure(
  phase: Phase,
  raw: unknown,
  budgetSeconds: number,
  timeoutSeconds: number,
): Failure | null {
  const refusal = Refusal.safeParse(raw);
  if (refusal.success) {
    const code = refusal.data.error_code;
    if (code === "command.deadline_exceeded") {
      return { error_code: code, outcome: "deadline" };
    }
    if (code === "operation.cancelled") {
      return { error_code: code, outcome: "cancelled" };
    }
    if (code === "instance.lifecycle_busy" || code === "instance.source_busy") {
      return { error_code: "instance.lifecycle_busy", outcome: "busy" };
    }
    return {
      error_code: `instance.${phase}_step_failed`,
      outcome: "unconfirmed",
    };
  }
  if (budgetSeconds <= 0) {
    return { error_code: "command.deadline_exceeded", outcome: "deadline" };
  }
  const parsed = LifecycleResult.safeParse(raw);
  if (!parsed.success) {
    return {
      error_code: `instance.${phase}_step_failed`,
      outcome: "unconfirmed",
    };
  }
  const result = parsed.data;
  if (result.exit_code === 0 && !result.timed_out) return null;
  if (result.exit_code === 75) {
    return { error_code: "instance.lifecycle_busy", outcome: "busy" };
  }
  if (result.exit_code === 126 || result.exit_code === 127) {
    return {
      error_code: `instance.${phase}_step_unavailable`,
      outcome: "missing",
    };
  }
  if (
    (result.timed_out || result.exit_code === 124) &&
    budgetSeconds < timeoutSeconds
  ) {
    return { error_code: "command.deadline_exceeded", outcome: "deadline" };
  }
  return {
    error_code: `instance.${phase}_step_failed`,
    outcome:
      result.timed_out || result.exit_code === 1 || result.exit_code === 124
        ? "failed"
        : "unconfirmed",
  };
}
