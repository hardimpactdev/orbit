import { z } from "npm:zod@4.4.3";
export const Public = z.record(z.string(), z.unknown());
export const Step = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();
type Phase = z.infer<typeof Step> | null | undefined;
type Phases = {
    prepare: Phase;
    source: Phase;
    environment: Phase;
    setup: Phase;
    processes: string[];
};

/** The public JSON a script step printed, or null when the step did not finish its script. */
function printed(step: Phase): Record<string, unknown> | null {
    if (step?.status !== "succeeded" || step.outputs === undefined) return null;
    try {
        return Public.parse(JSON.parse(String(step.outputs.stdout)));
    } catch {
        return null;
    }
}

/**
 * Map the phase steps to the terminal receipt: the checked-out commit, or the first phase that did not finish.
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
    const source = printed(phases.source);
    const outcomes: [string, Record<string, unknown> | null][] = [
        ["prepare", printed(phases.prepare)],
        ["source", source],
        ["environment", printed(phases.environment)],
        ["setup", printed(phases.setup)],
        ["processes", {
            ready: phases.processes.every((status) => status === "succeeded"),
        }],
    ];
    const failed = outcomes.find(([, outcome]) => outcome?.ready !== true);
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        name_converted_from: intent.name_converted_from ?? null,
        ...(source?.ready === true ? { commit: source.commit } : {}),
        ...(failed
            ? {
                failed_step: failed[0],
                error_code: typeof failed[1]?.error_code === "string"
                    ? failed[1].error_code
                    : "instance.create_" + failed[0] + "_failed",
                ...(typeof failed[1]?.failed_command === "string"
                    ? { failed_command: failed[1].failed_command }
                    : {}),
            }
            : {}),
    };
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}
