import { stringify } from "jsr:@std/yaml@1.0.11";
import {
    createCompletionSteps,
    createPreparationSteps,
} from "./create_steps.ts";
import { vpPreparationJobs } from "../instance-vp-command/steps.ts";
import { destroySteps } from "./destroy_steps.ts";
import { instanceActions } from "../instance-vp-command/native_steps.ts";
import { expression as e } from "../instance-vp-command/native_steps.ts";
const reference = {
    type: "object",
    properties: {
        vault: { type: "string", const: "orbit-control-inputs" },
        key: { type: "string" },
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
    return {
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
}
if (import.meta.main) {
    const command = instanceActions("project", "inputs.intent.ssh_execution");
    command.sshAction("execute", "exec", {
        argv: ["/usr/bin/python3", "-B", e("inputs.intent.script")],
        timeoutSec: e("inputs.intent.timeout_seconds"),
        stdin: e(
            "vault.get(inputs.intent.reference.vault,inputs.intent.reference.key)",
        ),
    });
    await Deno.writeTextFile(
        new URL(
            "../../../../workflows/workflow-instance-project-command.yaml",
            import.meta.url,
        ),
        stringify({
            id: "e8bc85c5-e568-46d8-8818-2ba87659ef75",
            name: "instance-project-command",
            description:
                "One admitted private Project lifecycle command over official SSH.",
            vaults: ["orbit-control-inputs"],
            inputs: {
                type: "object",
                properties: {
                    operation_id: { type: "integer" },
                    node_id: { type: "integer" },
                    kind: { type: "string" },
                    intent: { type: "object" },
                },
                required: ["operation_id", "node_id", "kind", "intent"],
                additionalProperties: false,
            },
            jobs: [{
                name: "command",
                target: "orbit-node-209",
                steps: command.steps,
            }],
        }, { lineWidth: 160 }),
    );
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
