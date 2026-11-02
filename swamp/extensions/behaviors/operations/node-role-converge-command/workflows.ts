import { stringify } from "jsr:@std/yaml@1.0.12";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
const on = (
    step: string,
    type = "succeeded",
) => [{ step, condition: { type } }];
const target = { labels: { role: "swamp" } };
// The role child takes no execution copy; its intent carries the public verifier.
const references = { type: "object", additionalProperties: false };
export function dispatcherWorkflow() {
    return {
        id: "463c1e47-f612-4296-a3f8-5e4b1c4bcbfa",
        name: "operation-node-role-converge-209",
        vaults: [],
        ...target,
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", enum: ["node.role_converge"] },
                intent: { type: "object" },
                execution_secrets: references,
            },
            required: ["operation_id", "node_id", "kind"],
            additionalProperties: true,
        },
        jobs: [{
            name: "dispatch",
            steps: [{
                name: "native",
                ...target,
                forEach: {
                    item: "choice",
                    in: ex(
                        "has(inputs.intent) && inputs.intent != null ? [0] : []",
                    ),
                },
                task: {
                    type: "workflow",
                    workflowIdOrName: "node-role-converge",
                    inputs: {
                        intent: ex("has(inputs.intent) ? inputs.intent : null"),
                        operation_id: ex("inputs.operation_id"),
                        node_id: ex("inputs.node_id"),
                        execution_secrets: ex("inputs.execution_secrets"),
                    },
                },
            }, {
                name: "retained",
                ...target,
                forEach: {
                    item: "choice",
                    in: ex(
                        "has(inputs.intent) && inputs.intent != null ? [] : [0]",
                    ),
                },
                task: {
                    type: "workflow",
                    workflowIdOrName: "operation-node-role-add-direct",
                    inputs: {
                        operation_id: ex("inputs.operation_id"),
                        node_id: ex("inputs.node_id"),
                        kind: ex("inputs.kind"),
                        parent_run_id: ex("run.id"),
                    },
                },
            }],
        }, {
            name: "result",
            dependsOn: [{ job: "dispatch", condition: { type: "always" } }],
            steps: [{
                name: "result",
                ...target,
                task: {
                    type: "behavior",
                    behaviorType: "@nckrtl/node-role-converge-result",
                    behaviorName: "converge-result",
                    methodName: "relay",
                    inputs: {
                        result: ex(
                            '("native-0" in steps) && ("outputs" in steps["native-0"]) && ("result" in steps["native-0"].outputs) ? steps["native-0"].outputs.result : {}',
                        ),
                    },
                },
            }, {
                name: "proven",
                ...target,
                dependsOn: on("result"),
                task: {
                    type: "assert",
                    expr:
                        "!has(inputs.intent) || inputs.intent == null || (has(steps.result.outputs.role_proven) && steps.result.outputs.role_proven)",
                    severity: "high",
                    message: "Required role actions must complete",
                },
            }],
        }],
    };
}
export function generatedRoleWorkflows() {
    return [dispatcherWorkflow()].map((workflow) => ({
        path: new URL(
            "../../../../workflows/workflow-" + workflow.name + ".yaml",
            import.meta.url,
        ),
        text:
            "# deno-fmt-ignore-file\n# Generated from node-role-converge-command/workflows.ts\n" +
            stringify(workflow, { flowLevel: 4, lineWidth: -1 }),
    }));
}
if (import.meta.main) {
    for (const { path, text } of generatedRoleWorkflows()) {
        await Deno.writeTextFile(path, text);
    }
}
