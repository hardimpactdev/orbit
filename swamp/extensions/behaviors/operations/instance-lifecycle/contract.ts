import { z } from "npm:zod@4.4.3";

export const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
export const StepListResult = z.discriminatedUnion("outcome", [
  z.object({ outcome: z.literal("succeeded") }),
  z.object({
    outcome: z.enum([
      "busy",
      "missing",
      "failed",
      "unconfirmed",
      "deadline",
      "cancelled",
    ]),
    error_code: ErrorCode.nullable(),
    failed_step: z.string().nullable(),
  }),
]);
export type Failure = {
  error_code: string;
  outcome: Exclude<z.infer<typeof StepListResult>["outcome"], "succeeded">;
};

export function canRollbackSetup(
  failure: Failure,
  activatedByThisOperation: boolean,
): boolean {
  return activatedByThisOperation &&
    ["missing", "failed", "deadline"].includes(failure.outcome);
}

/** Busy and uncertain teardown retain the checkout even after a confirmed setup failure. */
export function canRemoveAfterRollbackTeardown(
  failure: Failure | null,
): boolean {
  return failure === null ||
    ["missing", "failed", "deadline"].includes(failure.outcome);
}

export const SourceResolution = z.object({
  branch: z.string().min(1),
  commit: z.string().regex(/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/),
});
export function sourceResolution(
  raw: unknown,
  expectedBranch: string,
): z.infer<typeof SourceResolution> {
  const result = SourceResolution.parse(raw);
  if (result.branch !== expectedBranch) {
    throw new Error("instance.source_identity_invalid");
  }
  return result;
}
