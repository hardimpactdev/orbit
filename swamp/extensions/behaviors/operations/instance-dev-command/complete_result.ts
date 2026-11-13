import { z } from "npm:zod@4.4.3";
import { fleetFailure, Public, Step } from "./result.ts";

/** The step outcomes of a clone completion (docs/cli/instance/complete.mmd), as the `finish` step hands them over. */
export const CompletePhases = z.object({
    stage: z.string(),
    database: z.string(),
    fleet: z.string(),
    services: z.string(),
    check: Step.nullable(),
    route: z.string(),
    certificates: z.array(z.string()).default([]),
    route_observation: Public.default({}),
});
export type CompletePhases = z.infer<typeof CompletePhases>;

/**
 * What the check phase said. It accepts every exit code, because a failed step keeps no output: exit 0 with the phase's own line is
 * a pass; a dead unit prints its public receipt and exits nonzero; any other end is a failed check without a receipt.
 */
function checkOutcome(step: z.infer<typeof Step> | null): { finished: boolean; failed: boolean; receipt: Record<string, unknown> | null } {
    if (step === null || step.status === "skipped") return { finished: false, failed: false, receipt: null };
    if (step.status !== "succeeded") return { finished: false, failed: true, receipt: null };
    const stdout = String(step.outputs?.stdout ?? "");
    const lines = stdout.split("\n").map((line) => line.trim()).filter((line) => line !== "");
    const parsed = lines.map((line) => {
        try {
            return JSON.parse(line) as unknown;
        } catch {
            return null;
        }
    });
    if (step.outputs?.exitCode === 0 && parsed.some((value) => z.object({ phase: z.literal("check") }).safeParse(value).success)) {
        return { finished: true, failed: false, receipt: null };
    }
    for (const value of parsed) {
        const receipt = z.object({ error_code: z.literal("instance.container_process_inactive"), process_failure: Public }).safeParse(value);
        if (receipt.success) return { finished: false, failed: true, receipt: receipt.data };
    }
    return { finished: false, failed: true, receipt: null };
}

/**
 * Map the completion's steps to its terminal receipt. A step that a failure skipped is not the failure, so a step that ended
 * `failed` wins over the first one that merely did not run. The order is the order of the steps: the database and the fleet,
 * the services part of the workspace (with the stage that feeds it), the check, then the Route.
 */
export function completeResult(intent: Record<string, unknown>, phases: CompletePhases, fleetFile: unknown = null) {
    const check = checkOutcome(phases.check);
    const certificates = phases.certificates;
    // [failed_step, finished, failed]
    const outcomes: [string, boolean, boolean][] = [
        ["database", (intent.database == null && intent.server_database == null && intent.server_valkey == null) || phases.database === "succeeded", phases.database === "failed"],
        // Only a clone of a nested-fleet default has `fleet` in its intent and runs this step.
        ["fleet", intent.fleet == null || phases.fleet === "succeeded", phases.fleet === "failed"],
        ["workspace", phases.stage === "succeeded" && phases.services === "succeeded", phases.stage === "failed" || phases.services === "failed"],
        ["check", check.finished, check.failed],
        // A Project that serves no web pages has no Route; its steps are skipped.
        ["route", intent.route == null || (phases.route === "succeeded" && certificates.every((status) => status === "succeeded")),
            phases.route === "failed" || certificates.includes("failed")],
    ];
    const failed = outcomes.find(([, , failure]) => failure) ?? outcomes.find(([, finished]) => !finished);
    const fleet = failed?.[0] === "fleet" ? fleetFailure(fleetFile) : null;
    const code = failed === undefined ? null
        : failed[0] === "check" ? (check.receipt?.error_code ?? "instance.complete_check_failed")
        : failed[0] === "fleet" ? (fleet?.error_code ?? "instance.create_fleet_failed")
        : "instance.create_" + failed[0] + "_failed";
    const probes: string[] = [];
    const collect = (value: unknown): void => {
        if (value === null || typeof value !== "object") return;
        const row = value as Record<string, unknown>;
        if (typeof row.finishedAt === "string" && Number.isFinite(Date.parse(row.finishedAt))) probes.push(new Date(row.finishedAt).toISOString());
        for (const nested of Object.values(row)) if (nested !== null && typeof nested === "object") collect(nested);
    };
    for (const [name, value] of Object.entries(phases.route_observation)) if (name.startsWith("Probe the site over TLS")) collect(value);
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        ...(failed ? { failed_step: failed[0], error_code: code } : {}),
        ...(check.receipt ? { process_failure: check.receipt.process_failure } : {}),
        ...(fleet ? { ...fleet, error_code: code } : {}),
        readiness: { route_ready_at: probes.sort().at(-1) ?? null },
    };
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}
