import { stringify } from "jsr:@std/yaml@1.0.11";
import {
    createCompletionSteps,
    createPreparationSteps,
} from "./create_steps.ts";
import { vpPreparationJobs } from "../instance-vp-command/steps.ts";
import { destroySteps } from "./destroy_steps.ts";
import { expression as e } from "../instance-vp-command/native_steps.ts";
const reference = {
    type: "object",
    properties: {
        vault: { type: "string", enum: ["orbit-control-inputs"] },
        key: { type: "string", pattern: "^[a-z][a-z0-9_.-]{0,254}$" },
    },
    required: ["vault", "key"],
    additionalProperties: false,
};
export function workflow(kind: "create" | "destroy") {
    const steps = kind === "create" ? createCompletionSteps() : destroySteps();
    const terminal = kind === "create" ? "finish-result" : "remove-result";
    steps.push({
        name: "accepted",
        target: "orbit-node-209",
        dependsOn: [{ step: terminal, condition: { type: "succeeded" } }],
        task: {
            type: "assert",
            expr: 'steps["' + terminal + '"].outputs.ready == true',
            severity: "high",
            message: "The development Instance action did not complete.",
        },
    });
    const result: Record<string, unknown> & {
        jobs: Record<string, unknown>[];
    } = {
        id: kind === "create"
            ? "c2c6c4a5-a667-4a81-9f5a-fae18fdf5d4a"
            : "10c1e646-19cd-4527-8be5-01f524976346",
        name: "operation-instance-" + kind,
        description: "Development Instance " + kind +
            " from209 through separately placed official SSH actions.",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", const: "instance." + kind },
                intent: { type: "object" },
                execution_secrets: {
                    type: "object",
                    additionalProperties: reference,
                },
            },
            required: [
                "operation_id",
                "node_id",
                "kind",
                "intent",
                "execution_secrets",
            ],
            additionalProperties: false,
        },
        jobs: kind === "create"
            ? [
                {
                    name: "prepare",
                    target: "orbit-node-209",
                    steps: createPreparationSteps(),
                },
                ...vpPreparationJobs("vp", "inputs.intent", "prepare"),
                {
                    name: "create",
                    target: "orbit-node-209",
                    dependsOn: [{
                        job: "prepare",
                        condition: { type: "always" },
                    }, { job: "vp-install", condition: { type: "always" } }],
                    steps,
                },
            ]
            : [{ name: kind, target: "orbit-node-209", steps }],
    };
    if (kind === "create") {
        for (const job of result.jobs) {
            const name = String(job.name) + "-admitted";
            const jobSteps = job.steps as Record<string, unknown>[];
            for (const step of jobSteps) {
                if (step.name === "finish-result" || step.name === "accepted") {
                    continue;
                }
                if (step.forEach) {
                    const loop = step.forEach as { in: string };
                    loop.in = e(
                        "inputs.intent.already_active ? [] : (" +
                            loop.in.slice(3, -3).trim() + ")",
                    );
                }
                step.dependsOn = [
                    ...((step.dependsOn as Record<string, unknown>[]) ?? []),
                    { step: name, condition: { type: "succeeded" } },
                ];
            }
            jobSteps.unshift({
                name,
                target: "orbit-node-209",
                guard: e("inputs.intent.already_active"),
                task: {
                    type: "assert",
                    expr: "true",
                    severity: "high",
                    message: "The Instance is already active.",
                },
            });
        }
    }
    return result;
}
if (import.meta.main) {
    for (const kind of ["create", "destroy"] as const) {
        await Deno.writeTextFile(
            new URL(
                "../../../../workflows/workflow-operation-instance-" + kind +
                    ".yaml",
                import.meta.url,
            ),
            stringify(workflow(kind), { lineWidth: 160 }),
        );
    }
}
