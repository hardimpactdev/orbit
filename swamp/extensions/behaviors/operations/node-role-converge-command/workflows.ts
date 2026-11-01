import { roleConvergeSteps } from "./root_steps.ts";
import { stringify } from "jsr:@std/yaml@1.0.12";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
const on = (
    step: string,
    type = "succeeded",
) => [{ step, condition: { type } }];
const target = { target: "orbit-node-209" };
const references = {
    type: "object",
    additionalProperties: {
        type: "object",
        properties: {
            vault: { type: "string", enum: ["orbit-control-inputs"] },
            key: {
                type: "string",
                pattern: "^node-role-[0-9]+-(observer|runtime_material)$",
            },
        },
        required: ["vault", "key"],
        additionalProperties: false,
    },
};
export function roleWorkflow() {
    const ssh = "inputs.intent.ssh";
    const globals = {
        name: ex('"role-converge-"+run.id'),
        transport: {
            kind: "ssh",
            user: ex(ssh + ".target.user"),
            port: ex(ssh + ".target.port"),
            identityFile: ex(ssh + ".identity_file"),
        },
        hosts: [{
            name: "target",
            address: ex(ssh + ".target.host"),
            transport: { hostKeys: ex(ssh + ".target.host_keys") },
        }],
    };
    const steps = roleConvergeSteps(
        "",
        "inputs.intent",
        globals,
        undefined,
        undefined,
        { rootRunIdExpr: "inputs.parent_run_id" },
    );
    return {
        id: "21b08662-58e5-4485-b42c-540dd9bd6004",
        name: "node-role-converge",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                intent: { type: "object" },
                parent_run_id: { type: "string", minLength: 1 },
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                execution_secrets: references,
            },
            required: [
                "intent",
                "parent_run_id",
                "execution_secrets",
                "operation_id",
                "node_id",
            ],
            additionalProperties: false,
        },
        ...target,
        jobs: [{ name: "effects", steps }, {
            name: "result",
            dependsOn: [{ job: "effects", condition: { type: "always" } }],
            steps: [{
                name: "result",
                ...target,
                task: {
                    type: "behavior",
                    behaviorType: "@nckrtl/node-role-converge-result",
                    behaviorName: "database-result",
                    methodName: "assemble",
                    inputs: {
                        intent: ex("inputs.intent"),
                        steps: ex(
                            "steps",
                        ),
                    },
                },
            }],
        }],
    };
}
export function dispatcherWorkflow() {
    return {
        id: "463c1e47-f612-4296-a3f8-5e4b1c4bcbfa",
        name: "operation-node-role-converge-209",
        vaults: ["orbit-control-inputs"],
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
                        parent_run_id: ex("run.id"),
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
    return [roleWorkflow(), dispatcherWorkflow()].map((workflow) => ({
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
