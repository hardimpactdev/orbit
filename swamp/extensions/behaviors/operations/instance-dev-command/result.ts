import { z } from "npm:zod@4.4.3";
export const Public = z.record(z.string(), z.unknown());
export const Step = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();
type Phase = z.infer<typeof Step> | null | undefined;
type Phases = {
    checkout: Phase;
    database: string;
    setup: string;
    processes: string[];
    route: string;
};

/** The checkout phase prints the commit it left checked out as one `orbit-commit=<sha>` line. */
const COMMIT = /orbit-commit=([0-9a-f]{40}|[0-9a-f]{64})\b/;

/**
 * Map the step statuses to the terminal receipt: the checked-out commit, or the first phase that did not finish.
 * The Gateway already holds the rendered environment, the branch and the Project's profile.
 */
export function createResult(intent: Record<string, unknown>, phases: Phases) {
    if (intent.already_active === true) {
        const result = {
            ready: true,
            instance_id: intent.instance_id,
            already_active: true,
        };
        return { ready: true, stdout: JSON.stringify(result) };
    }
    const commit = phases.checkout?.status === "succeeded"
        ? COMMIT.exec(String(phases.checkout.outputs?.stdout ?? ""))?.[1] ??
            null
        : null;
    const steps = z.array(z.object({ name: z.string() }).passthrough()).parse(
        intent.setup_steps,
    );
    const outcomes: [string, boolean][] = [
        ["checkout", commit !== null],
        // Only a create that copies the default database or owns a server database runs the database phase.
        [
            "database",
            (intent.database == null && intent.server_database == null) ||
            phases.database === "succeeded",
        ],
        // A Project without setup steps runs no setup phase.
        ["setup", steps.length === 0 || phases.setup === "succeeded"],
        [
            "processes",
            phases.processes.every((status) => status === "succeeded"),
        ],
        // A Project that serves no web pages has no Route; its route step is skipped.
        ["route", intent.route == null || phases.route === "succeeded"],
    ];
    const failed = outcomes.find(([, ready]) => !ready);
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        name_converted_from: intent.name_converted_from ?? null,
        ...(commit ? { commit } : {}),
        ...(failed
            ? {
                failed_step: failed[0],
                // A setup command keeps the public step-list code; other phases name themselves.
                error_code: failed[0] === "setup"
                    ? "instance.setup_step_failed"
                    : "instance.create_" + failed[0] + "_failed",
            }
            : {}),
    };
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}
