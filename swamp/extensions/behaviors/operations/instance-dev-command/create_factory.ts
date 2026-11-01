import {
    createCompletionSteps,
    createPreparationSteps,
} from "./create_steps.ts";
import { vpPreparationJobs } from "../instance-vp-command/steps.ts";
import { expression as e } from "../instance-vp-command/native_steps.ts";
type Fields = Record<string, unknown>;

/** Compose the canonical create in the caller's root; input is a normal admitted envelope. */
export function createPhaseSteps(input = "inputs"): {
    jobs: Fields[];
    terminalResult: { job: string; step: string };
} {
    const intent = input + ".intent";
    const completion = createCompletionSteps(input);
    completion.push({
        name: "accepted",
        target: "orbit-node-209",
        dependsOn: [{
            step: "finish-result",
            condition: { type: "succeeded" },
        }],
        task: {
            type: "assert",
            expr: 'steps["finish-result"].outputs.ready == true',
            severity: "high",
            message: "The development Instance action did not complete.",
        },
    });
    const jobs = [
        {
            name: "prepare",
            target: "orbit-node-209",
            steps: createPreparationSteps(input),
        },
        ...vpPreparationJobs("vp", intent, "prepare"),
        {
            name: "create",
            target: "orbit-node-209",
            dependsOn: [{ job: "prepare", condition: { type: "always" } }, {
                job: "vp-install",
                condition: { type: "always" },
            }],
            steps: completion,
        },
    ];
    for (const job of jobs) {
        const name = String(job.name) + "-admitted";
        const steps = job.steps as Fields[];
        for (const step of steps) {
            step.queueTimeout = 15;
            if (step.name === "finish-result" || step.name === "accepted") {
                continue;
            }
            if (step.forEach) {
                const loop = step.forEach as { in: string };
                loop.in = e(
                    intent + ".already_active ? [] : (" +
                        loop.in.slice(3, -3).trim() + ")",
                );
            }
            step.dependsOn = [...((step.dependsOn as Fields[]) ?? []), {
                step: name,
                condition: { type: "succeeded" },
            }];
        }
        steps.unshift({
            name,
            target: "orbit-node-209",
            queueTimeout: 15,
            guard: e(intent + ".already_active"),
            task: {
                type: "assert",
                expr: "true",
                severity: "high",
                message: "The Instance is already active.",
            },
        });
    }
    return { jobs, terminalResult: { job: "create", step: "finish-result" } };
}
