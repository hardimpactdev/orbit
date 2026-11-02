import { stringify } from "jsr:@std/yaml@1.0.11";
import { destroySteps } from "./destroy_steps.ts";
const reference = {
    type: "object",
    properties: {
        vault: { type: "string", enum: ["orbit-control-inputs"] },
        key: { type: "string", pattern: "^[a-z][a-z0-9_.-]{0,254}$" },
    },
    required: ["vault", "key"],
    additionalProperties: false,
};
/** The destroy workflow; create is a hand-written phase workflow. */
export function workflow() {
    const steps = destroySteps();
    steps.push({
        name: "accepted",
        labels: { role: "swamp" },
        dependsOn: [{ step: "remove-result", condition: { type: "succeeded" } }],
        task: {
            type: "assert",
            expr: 'steps["remove-result"].outputs.ready == true',
            severity: "high",
            message: "The development Instance action did not complete.",
        },
    });
    return {
        id: "10c1e646-19cd-4527-8be5-01f524976346",
        name: "operation-instance-destroy",
        description:
            "Development Instance destroy from209 through separately placed official SSH actions.",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", const: "instance.destroy" },
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
        jobs: [{ name: "destroy", labels: { role: "swamp" }, steps }],
    };
}
if (import.meta.main) {
    await Deno.writeTextFile(
        new URL(
            "../../../../workflows/workflow-operation-instance-destroy.yaml",
            import.meta.url,
        ),
        stringify(workflow(), { lineWidth: 160 }),
    );
}
