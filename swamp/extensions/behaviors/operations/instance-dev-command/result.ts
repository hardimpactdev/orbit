import { z } from "npm:zod@4.4.3";
export const Public = z.record(z.string(), z.unknown());
export const Step = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();
type Phase = z.infer<typeof Step> | null | undefined;
type Phases = {
    prepare: string;
    fetch: string;
    source: Phase;
    environment: string;
    database: string;
    setup: string[];
    processes: string[];
    route: string;
};

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
    const commit = phases.source?.status === "succeeded"
        ? String(phases.source.outputs?.stdout ?? "").trim()
        : null;
    const steps = z.array(z.object({ name: z.string() }).passthrough()).parse(
        intent.setup_steps,
    );
    const failedSetup = phases.setup.findIndex((status) =>
        status !== "succeeded"
    );
    const outcomes: [string, boolean][] = [
        ["prepare", phases.prepare === "succeeded"],
        ["source", phases.fetch !== "failed" && commit !== null],
        ["environment", phases.environment === "succeeded"],
        // Only a create that copies the default database or owns a server database runs the database phase.
        [
            "database",
            (intent.database == null && intent.server_database == null) ||
            phases.database === "succeeded",
        ],
        ["setup", failedSetup < 0],
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
                ...(failed[0] === "setup" && failedSetup >= 0
                    ? { failed_command: steps[failedSetup].name }
                    : {}),
            }
            : {}),
    };
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}
