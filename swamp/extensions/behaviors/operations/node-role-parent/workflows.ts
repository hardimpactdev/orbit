import { stringify } from "jsr:@std/yaml@1.0.12";
import { roleConvergeSteps } from "../node-role-converge-command/root_steps.ts";
import { rolePublicationSteps } from "../node-role-converge-command/publication_steps.ts";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
const placed = { target: "orbit-node-209" };
const on = (
    step: string,
    type = "succeeded",
) => [{ step, condition: { type } }];
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
export function parentWorkflow(relocate = false) {
    const kind = relocate ? "node.role_relocate" : "node.role_add";
    const ssh = "inputs.intent.target.ssh";
    const globals = {
        name: ex('"role-target-"+run.id'),
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
    const target = roleConvergeSteps(
        "",
        "inputs.intent.target",
        globals,
        undefined,
        undefined,
        { rootRunIdExpr: "run.id" },
    );
    target.push({
        name: "target_proven",
        ...placed,
        dependsOn: on("role_result"),
        task: {
            type: "assert",
            severity: "high",
            message: "The target must be ready before source withdrawal",
            expr:
                "steps.role_result.outputs.role_proven && steps.role_result.outputs.runtime_proven && steps.role_result.outputs.certificates_proven && steps.role_result.outputs.publication_proven",
        },
    });
    const jobs: Fields[] = [{ name: "target", steps: target }];
    if (!relocate) {
        jobs.push({
            name: "rollback",
            dependsOn: [{ job: "target", condition: { type: "always" } }],
            steps: [{
                name: "target_rollback",
                ...placed,
                guard: ex(
                    '!(inputs.intent.target.role == "websocket" && inputs.intent.target.created && steps.exists(k,k == "runtime-0" && steps[k].status == "succeeded") && !steps.exists(k,k == "publication-0" && steps[k].status == "succeeded"))',
                ),
                task: {
                    type: "workflow",
                    workflowIdOrName: "node-role-remove",
                    inputs: {
                        operation_id: ex("inputs.operation_id"),
                        node_id: ex("inputs.node_id"),
                        kind,
                        execution_secrets: {},
                        offline: false,
                        intent: {
                            schema_version: 1,
                            node_id: ex("inputs.node_id"),
                            assignment_id: ex(
                                "inputs.intent.target.assignment_id",
                            ),
                            role: "websocket",
                            ssh: ex("inputs.intent.target.ssh"),
                            publications: [],
                            stop_units: [{
                                ssh: ex("inputs.intent.target.ssh"),
                                input: { unit: "orbit-websocket.service" },
                            }],
                            remove_units: [{
                                ssh: ex("inputs.intent.target.ssh"),
                                input: { unit: "orbit-websocket.service" },
                            }],
                            reload_units: [{
                                ssh: ex("inputs.intent.target.ssh"),
                                input: {},
                            }],
                            remove_footprints: [],
                            stop_containers: [],
                            remove_containers: [],
                            firewall: [],
                            certificates: [],
                            ci_host: [],
                        },
                    },
                },
            }],
        });
    }
    if (relocate) {
        const source: Fields[] = [{
            name: "source_grace",
            ...placed,
            task: {
                type: "workflow",
                workflowIdOrName: "route-dns-grace",
                inputs: {},
            },
        }];
        source.push(
            ...rolePublicationSteps(
                "source_publication",
                "inputs.intent.source",
                "forward",
                "source_grace",
            ),
        );
        source.push({
            name: "source_publication_proven",
            ...placed,
            dependsOn: on("source_publication"),
            task: {
                type: "assert",
                expr: "steps.source_publication.outputs.ready",
                severity: "high",
                message: "Publish source withdrawal before runtime cleanup",
            },
        });
        const sourceIntent = Object.fromEntries(
            [
                "schema_version",
                "node_id",
                "assignment_id",
                "role",
                "ssh",
                "stop_units",
                "remove_footprints",
                "reload_units",
                "remove_units",
                "stop_containers",
                "remove_containers",
                "firewall",
                "certificates",
                "ci_host",
            ].map((key) => [key, ex("inputs.intent.source." + key)]),
        );
        source.push({
            name: "source_cleanup",
            ...placed,
            dependsOn: on("source_publication_proven"),
            task: {
                type: "workflow",
                workflowIdOrName: "node-role-remove",
                inputs: {
                    operation_id: ex("inputs.operation_id"),
                    node_id: ex("inputs.intent.source.node_id"),
                    kind,
                    intent: { ...sourceIntent, publications: [] },
                    execution_secrets: {},
                    offline: false,
                },
            },
        });
        jobs.push({
            name: "source",
            dependsOn: [{ job: "target", condition: { type: "succeeded" } }],
            steps: source,
        });
    }
    jobs.push({
        name: "result",
        dependsOn: jobs.map((job) => ({
            job: job.name,
            condition: { type: "always" },
        })),
        steps: [{
            name: "result",
            ...placed,
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/node-role-parent-result",
                behaviorName: ex('"role-parent-result-"+run.id'),
                methodName: "assemble",
                inputs: { kind, steps: ex("steps") },
            },
        }, {
            name: "accepted",
            ...placed,
            dependsOn: on("result"),
            task: {
                type: "assert",
                expr: "steps.result.outputs.ready",
                severity: "high",
                message: "Role parent did not complete",
            },
        }],
    });
    return {
        id: relocate
            ? "48ce785a-7429-463e-a776-7b5a59bb2961"
            : "36af16a4-008f-41f3-81ed-187cb11b1a6b",
        name: relocate
            ? "operation-node-role-relocate-direct"
            : "operation-node-role-add-direct",
        vaults: ["orbit-control-inputs"],
        queueTimeout: 30,
        ...placed,
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", enum: [kind] },
                intent: { type: "object" },
                execution_secrets: references,
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
        jobs,
    };
}
export function generatedParentWorkflows() {
    return [parentWorkflow(), parentWorkflow(true)].map((workflow) => ({
        path: new URL(
            "../../../../workflows/workflow-" + workflow.name + ".yaml",
            import.meta.url,
        ),
        text:
            "# deno-fmt-ignore-file\n# Generated from node-role-parent/workflows.ts\n" +
            stringify(workflow, { flowLevel: 4, lineWidth: -1 }),
    }));
}
if (import.meta.main) {
    for (const { path, text } of generatedParentWorkflows()) {
        await Deno.writeTextFile(path, text);
    }
}
