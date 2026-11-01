import { footprintWorkflow } from "../node/footprint_workflows.ts";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { roleConvergeSteps } from "../node-role-converge-command/root_steps.ts";
import { rolePublicationSteps } from "../node-role-converge-command/publication_steps.ts";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
const placed = { target: "orbit-node-209", queueTimeout: 30 };
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
        "forward",
        "inputs.intent.target",
        globals,
        undefined,
        undefined,
        {
            rootRunIdExpr:
                "has(inputs.parent_run_id) ? inputs.parent_run_id : run.id",
        },
    );
    const residueIndex = target.findIndex((step) =>
        step.name === "forward_php_runtime"
    );
    target.splice(residueIndex, 0, {
        name: "forward_residue",
        ...placed,
        allowFailure: true,
        forEach: {
            item: "residue",
            in: ex(
                "has(inputs.intent.target.residue) ? [inputs.intent.target.residue] : []",
            ),
        },
        dependsOn: [
            ...on("forward_packages"),
            ...on("forward_peer_dns", "always"),
            ...on("forward_caddy", "always"),
        ],
        guard: ex(
            'steps.exists(k,k.startsWith("forward_") && steps[k].status in ["failed","cancelled"])',
        ),
        task: {
            type: "workflow",
            workflowIdOrName: "route-residue",
            inputs: {
                operation_id: ex("inputs.operation_id"),
                node_id: ex("inputs.node_id"),
                kind,
                intent: ex("self.residue"),
            },
        },
    });
    for (const step of target) {
        if (
            [
                "forward_php_runtime",
                "forward_observer",
                "forward_cli",
                "forward_role_result",
            ].includes(String(step.name))
        ) {
            step.dependsOn = [
                ...(step.dependsOn as Fields[] ?? []),
                ...on("forward_residue", "always"),
            ];
        }
    }
    target.push({
        name: "target_proven",
        ...placed,
        dependsOn: on("forward_role_result"),
        task: {
            type: "assert",
            severity: "high",
            message: "The target must be ready before source withdrawal",
            expr:
                "steps.forward_role_result.outputs.role_proven && steps.forward_role_result.outputs.runtime_proven && steps.forward_role_result.outputs.certificates_proven && steps.forward_role_result.outputs.publication_proven",
        },
    });
    // Phase jobs gate later effects. Optional forEach phases may have no items;
    // their boundary accepts skipped items but never a failed/cancelled action.
    const resultIndex = target.findIndex((step) =>
        step.name === "forward_role_result"
    );
    const phases = target.slice(0, resultIndex);
    const final = target.slice(resultIndex);
    const jobs: Fields[] = [];
    let previous: string | undefined;
    for (const phase of phases) {
        delete phase.dependsOn;
        const name = String(phase.name) + "_job";
        const steps: Fields[] = [phase];
        if (phase.name !== "forward_residue") {
            const base = JSON.stringify(phase.name);
            steps.push({
                name: String(phase.name) + "_accepted",
                ...placed,
                dependsOn: on(String(phase.name), "always"),
                task: {
                    type: "assert",
                    severity: "high",
                    message: "Complete the role phase before later effects",
                    expr: "steps.filter(k,k == " + base + " || k.startsWith(" +
                        base +
                        '+"-")).all(k,steps[k].status in ["succeeded","skipped"])',
                },
            });
        }
        jobs.push({
            name,
            ...(previous
                ? {
                    dependsOn: [{
                        job: previous,
                        condition: { type: "succeeded" },
                    }],
                }
                : {}),
            steps,
        });
        previous = name;
    }
    const finalNames = new Set(final.map((step) => String(step.name)));
    for (const step of final) {
        step.dependsOn = ((step.dependsOn as Fields[] | undefined) ?? [])
            .filter((dependency) => finalNames.has(String(dependency.step)));
    }
    jobs.push({
        name: "target",
        dependsOn: jobs.map((job) => ({
            job: job.name,
            condition: { type: "always" },
        })),
        steps: final,
    });
    if (!relocate) {
        jobs.push({
            name: "rollback",
            dependsOn: [{ job: "target", condition: { type: "always" } }],
            steps: [{
                name: "target_rollback",
                ...placed,
                guard: ex(
                    '!(inputs.intent.target.role == "websocket" && inputs.intent.target.created && steps.exists(k,k == "forward_runtime-0" && steps[k].status == "succeeded") && !steps.exists(k,k == "forward_publication-0" && steps[k].status == "succeeded"))',
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
            name: "forward_source_grace",
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
                "forward_source_grace",
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
                inputs: {
                    kind,
                    steps: Object.fromEntries(
                        [
                            "role_admission",
                            "role_result",
                            "route_residue",
                            "target_proven",
                            ...(relocate
                                ? [
                                    "source_grace",
                                    "source_publication",
                                    "source_publication_proven",
                                    "source_cleanup",
                                ]
                                : ["target_rollback"]),
                        ].map((name) => {
                            const key = JSON.stringify(name);
                            const sourceKey = JSON.stringify(
                                name === "role_result"
                                    ? "forward_role_result"
                                    : name === "role_admission"
                                    ? "forward_role_admission"
                                    : name === "route_residue"
                                    ? "forward_residue-0"
                                    : name === "source_grace"
                                    ? "forward_source_grace"
                                    : name,
                            );
                            const step = "steps[" + sourceKey + "]";
                            const status = sourceKey + " in steps ? " + step +
                                '.status : "skipped"';
                            const succeeded = sourceKey + " in steps && " +
                                step +
                                '.status == "succeeded"';
                            return [name, {
                                status: ex("(" + status + ")"),
                                ...(name === "role_result"
                                    ? {
                                        outputs: ex(
                                            "(" + succeeded + " ? " + step +
                                                ".outputs : {})",
                                        ),
                                    }
                                    : [
                                            "source_cleanup",
                                            "target_rollback",
                                            "route_residue",
                                        ]
                                            .includes(name)
                                    ? {
                                        outputs: {
                                            result: ex(
                                                "(" + succeeded + " ? " + step +
                                                    ".outputs.result : {})",
                                            ),
                                        },
                                    }
                                    : {}),
                            }];
                        }),
                    ),
                },
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
    // Keep the existing Node-worker sequence for roles whose contribution is not
    // converted yet. Native jobs use the shared factory's prefix, so both paths
    // retain the original run identity without colliding step names.
    const legacy = footprintWorkflow(true, relocate);
    const retained = legacy.jobs as Fields[];
    for (const job of jobs) {
        for (const step of job.steps as Fields[]) {
            step.queueTimeout = 30;
            const condition = String(step.guard ?? "${{ false }}").slice(4, -3);
            step.guard = ex(
                "!has(inputs.intent) || inputs.intent == null || (" +
                    condition + ")",
            );
            if (step.forEach) {
                const loop = step.forEach as Fields;
                loop.in = ex(
                    "has(inputs.intent) && inputs.intent != null ? (" +
                        String(loop.in).slice(4, -3) + ") : []",
                );
            }
        }
    }
    for (const job of retained) {
        for (const step of job.steps as Fields[]) {
            step.queueTimeout = 30;
            step.target ??= legacy.target;
            delete step.labels;
            const condition = String(step.guard ?? "${{ false }}").slice(4, -3);
            step.guard = ex(
                "(has(inputs.intent) && inputs.intent != null) || (" +
                    condition + ")",
            );
            if (step.forEach) {
                const loop = step.forEach as Fields;
                loop.in = ex(
                    "has(inputs.intent) && inputs.intent != null ? [] : (" +
                        String(loop.in).slice(4, -3) + ")",
                );
            }
        }
    }
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
                kind: {
                    type: "string",
                    enum: relocate ? [kind] : [kind, "node.role_converge"],
                },
                intent: { type: "object" },
                parent_run_id: { type: "string", format: "uuid" },
                execution_secrets: references,
            },
            required: [
                "operation_id",
                "node_id",
                "kind",
            ],
            additionalProperties: false,
        },
        jobs: [...jobs, ...retained],
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
