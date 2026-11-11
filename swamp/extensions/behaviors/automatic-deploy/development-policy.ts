import { z } from "npm:zod@4.4.3";
import {
    isAutomaticDeployment,
    RowSchema as ProductionRowSchema,
} from "./policy.ts";
import type { Check } from "./policy.ts";

// Production policy is frozen independently; widening development evidence cannot change it.
export { isAutomaticDeployment, PolicyError, sha } from "./policy.ts";
export type { Check, Operation, PolicyEvent } from "./policy.ts";
export const RowSchema = ProductionRowSchema.extend({
    status: z.enum(["running", "succeeded", "failed", "interrupted"]),
});
export type Row = z.infer<typeof RowSchema>;

/** The configured check must prove this exact commit through completed GitHub Actions runs. */
export function mergeCheckResult(
    checks: Check[],
    commit: string,
    name: string,
): "pending" | "passed" | "failed" {
    const runs = checks.filter((r) => r.name === name);
    return !runs.length
        ? "pending"
        : runs.some((r) => r.app !== "github-actions" || r.head_sha !== commit)
        ? "failed"
        : runs.some((r) => r.status !== "completed")
        ? "pending"
        : runs.every((r) => r.conclusion === "success")
        ? "passed"
        : "failed";
}
export const terminalOperation = (status: string) =>
    ["succeeded", "failed", "cancelled", "interrupted"].includes(status);
export const failureStatus = (status: string) =>
    ["failed", "cancelled", "interrupted"].includes(status);
export const eligibleFailure = (r: Row) =>
    isAutomaticDeployment(r) && failureStatus(r.status) && r.commit !== null &&
    r.error_code !== "deployment.paused";
/** Development retries count every terminal failure for the admitted commit. */
export function automaticRetry(
    attempts: number,
    last: string,
    now: string,
): "commit_failed" | "backing_off" | null {
    if (attempts >= 3) return "commit_failed";
    return Date.parse(now) < Date.parse(last) + 600_000 * 2 ** (attempts - 1)
        ? "backing_off"
        : null;
}
