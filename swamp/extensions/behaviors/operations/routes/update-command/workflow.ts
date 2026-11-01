import { nodeCertificateGroups } from "../../certificates/batch_steps.ts";
import { routeMutationSteps } from "../mutation-command/steps.ts";
type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const placed = { target: "orbit-node-209" };
const after = (...names: string[]) =>
    names.map((step) => ({ step, condition: { type: "always" } }));
const envelope = {
    type: "object",
    properties: {
        operation_id: { type: "integer", minimum: 1 },
        node_id: { type: "integer", minimum: 1 },
        kind: { type: "string", enum: ["route.update"] },
        intent: { type: "object" },
        execution_secrets: {
            type: "object",
            additionalProperties: {
                type: "object",
                additionalProperties: false,
                properties: {
                    vault: { type: "string", enum: ["orbit-control-inputs"] },
                    key: {
                        type: "string",
                        pattern: "^[A-Za-z0-9][A-Za-z0-9_.:-]{0,255}$",
                    },
                },
                required: ["vault", "key"],
            },
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
};
const common = {
    operation_id: expr("inputs.operation_id"),
    node_id: expr("inputs.node_id"),
    kind: expr("inputs.kind"),
    intent: expr("inputs.intent"),
    execution_secrets: expr("inputs.execution_secrets"),
    parent_run_id: expr("run.id"),
};
export function updateWorkflow(): Fields {
    return workloadMutationWorkflow(
        "b1a5e413-5c52-4d05-966b-f78d57c2da06",
        "operation-route-update",
        "route.update",
        "route.update_failed",
    );
}
export function targetWorkflow(): Fields {
    return workloadMutationWorkflow(
        "ca5ceca0-f7da-4338-a1dd-196e06c64cd7",
        "operation-route-target-set",
        "route.target_set",
        "route.target_set_failed",
    );
}
export function createWorkflow(): Fields {
    return workloadMutationWorkflow(
        "8882537c-e250-4b80-8690-ce8c0fa419b8",
        "operation-route-create",
        "route.create",
        "route.create_failed",
    );
}
export function removalWorkflow(): Fields {
    const flow = workloadMutationWorkflow(
        "855a0c69-2eab-4eea-8488-0b63b2312cb1",
        "operation-route-removal",
        "route.destroy",
        "route.withdrawal_failed",
    );
    const value = JSON.parse(
        JSON.stringify(flow).replaceAll(
            "inputs.intent",
            "steps.selection.outputs.intent",
        ),
    );
    value.inputs.properties.kind.enum = ["route.destroy", "route.withdraw"];
    value.jobs[0].dependsOn = [{
        job: "readiness",
        condition: { type: "always" },
    }];
    const steps: Fields[] = [{
        name: "reachable",
        ...placed,
        forEach: {
            item: "part",
            in: expr("inputs.intent.route_mutations.forward.publications"),
        },
        allowFailure: true,
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expr(
                '"route-readiness-" + run.id + "-" + string(self._index)',
            ),
            methodName: "exec",
            globalArgs: {
                name: expr(
                    '"route-readiness-" + run.id + "-" + string(self._index)',
                ),
                transport: {
                    kind: "ssh",
                    user: expr("self.part.connection.nodeUser"),
                    identityFile: expr("self.part.connection.nodeIdentityFile"),
                    port: expr("self.part.connection.nodePort"),
                },
                hosts: [{
                    name: "target",
                    address: expr("self.part.connection.nodeHost"),
                    transport: { hostKeys: expr("self.part.host_keys") },
                }],
            },
            inputs: { hosts: "name:target", argv: ["/usr/bin/true"] },
        },
    }, {
        name: "selection",
        ...placed,
        dependsOn: [{ step: "reachable", condition: { type: "always" } }],
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/route-update-result",
            behaviorName: "route-removal-selection",
            methodName: "select",
            inputs: { intent: expr("inputs.intent"), steps: expr("steps") },
        },
    }];
    value.jobs.unshift({ name: "readiness", steps });
    const result = value.jobs[1].steps.find((step: Fields) =>
        step.name === "result"
    );
    result.task.inputs.admitted = expr("steps.selection.outputs.admitted");
    result.task.inputs.retained_on_nodes = expr(
        "steps.selection.outputs.retained_on_nodes",
    );
    return value;
}
function workloadMutationWorkflow(
    id: string,
    name: string,
    kind: string,
    failureCode: string,
): Fields {
    const steps: Fields[] = [];
    const certificateNodes = nodeCertificateGroups(
        "inputs.intent.preparation.certificates",
    );
    const names = () => steps.map((s) => String(s.name));
    const failure = kind === "route.create"
        ? 'inputs.intent.effectful && (!("forward" in steps) || steps.forward.status != "succeeded" || !steps.forward.outputs.result.ready || steps.exists(k,(k.startsWith("retire-") || k.startsWith("verify-")) && steps[k].status == "failed"))'
        : 'inputs.intent.effectful && (!("forward" in steps) || steps.forward.status != "succeeded" || !steps.forward.outputs.result.ready || steps.exists(k,k.startsWith("retire-") && steps[k].status == "failed"))';
    function workloads(name: string, side: string, guard?: string) {
        steps.push({
            name,
            ...placed,
            forEach: { item: "workload", in: expr("inputs.intent.workloads") },
            dependsOn: after(...names()),
            ...(guard ? { guard: expr(guard) } : {}),
            task: {
                type: "workflow",
                workflowIdOrName: "instance-route-workload",
                inputs: {
                    ...common,
                    execution_secrets: {},
                    parent_run_id: undefined,
                    intent: {
                        node_id: expr("self.workload.node_id"),
                        instance_id: expr("self.workload.instance_id"),
                        connection: expr("self.workload.connection"),
                        host_keys: expr("self.workload.host_keys"),
                        plan: expr(`self.workload.${side}`),
                    },
                },
            },
        });
    }
    workloads("prepare", "prepare");
    if (["route.target_set", "route.create"].includes(kind)) {
        function preparation(
            name: string,
            list: string,
            workflow: string,
            intent: Fields | string,
            extra: Fields = {},
        ) {
            steps.push({
                name,
                ...placed,
                dependsOn: after(...names()),
                forEach: {
                    item: "part",
                    in: expr(
                        `inputs.intent.effectful ? ${
                            list === "certificates"
                                ? certificateNodes
                                : "inputs.intent.preparation." + list
                        } : []`,
                    ),
                },
                guard: expr(
                    'steps.exists(k,k.startsWith("prepare-") && steps[k].status == "failed")',
                ),
                task: {
                    type: "workflow",
                    workflowIdOrName: workflow,
                    inputs: {
                        operation_id: common.operation_id,
                        node_id: common.node_id,
                        kind: common.kind,
                        intent,
                        execution_secrets: {},
                        ...extra,
                    },
                },
            });
        }
        const connection = (payload: string) => ({
            [payload]: expr("self.part"),
            control_node_id: 209,
            connection: expr(
                "inputs.intent.route_mutations.forward.publications.filter(p,p.node_id == self.part.node_id)[0].connection",
            ),
            host_keys: expr(
                "inputs.intent.route_mutations.forward.publications.filter(p,p.node_id == self.part.node_id)[0].host_keys",
            ),
        });
        if (kind === "route.create") {
            preparation(
                "prepare-upstream",
                "upstream_firewalls",
                "route-upstream-firewall",
                expr("self.part"),
            );
        }
        preparation(
            "prepare-certificates",
            "certificates",
            "route-certificate-publish",
            {
                ...connection("leaves"),
                node_id: expr("self.part.node_id"),
                leaves: expr(
                    "inputs.intent.preparation.certificates.filter(leaf,leaf.node_id == self.part.node_id)",
                ),
            },
            { parent_run_id: expr("run.id"), restoring: false },
        );
        preparation(
            "prepare-lan",
            "lan",
            "route-lan-apply",
            connection("rule"),
        );
        preparation(
            "prepare-caddy",
            "publications",
            "operation-route-caddy-delta",
            {
                node_id: expr("self.part.node_id"),
                user: expr("self.part.user"),
                control_node_id: expr("self.part.control_node_id"),
                connection: expr("self.part.connection"),
                host_keys: expr("self.part.host_keys"),
                direction: expr("self.part.direction"),
                before: expr(
                    "inputs.intent.route_mutations.artifacts.caddy[self.part.before]",
                ),
                after: expr(
                    "inputs.intent.route_mutations.artifacts.caddy[self.part.after]",
                ),
                alternatives: expr(
                    "self.part.alternatives.map(ref,inputs.intent.route_mutations.artifacts.caddy[ref])",
                ),
            },
        );
        preparation(
            "prepare-probe",
            "probes",
            "cluster-router-probe",
            expr("self.part"),
        );
    }
    steps.push({
        name: "forward",
        ...placed,
        dependsOn: after(...names()),
        guard: expr(
            '!inputs.intent.effectful || steps.exists(k,k.startsWith("prepare-") && steps[k].status == "failed")',
        ),
        task: {
            type: "workflow",
            workflowIdOrName: kind === "route.destroy"
                ? "route-mutation-withdraw"
                : "route-mutation-forward",
            inputs: common,
        },
    });
    if (kind === "route.create") {
        steps.push({
            name: "verify",
            ...placed,
            forEach: {
                item: "part",
                in: expr(
                    "inputs.intent.effectful ? inputs.intent.verifications : []",
                ),
            },
            dependsOn: after(...names()),
            guard: expr(
                '!("forward" in steps) || steps.forward.status != "succeeded" || !steps.forward.outputs.result.ready',
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "cluster-router-probe",
                inputs: {
                    operation_id: common.operation_id,
                    node_id: common.node_id,
                    kind: common.kind,
                    intent: expr("self.part"),
                    execution_secrets: {},
                },
            },
        });
    }
    workloads(
        "retire",
        "selected",
        '!("forward" in steps) || steps.forward.status != "succeeded" || !steps.forward.outputs.result.ready',
    );
    workloads("restore-workload", "restore", `!(${failure})`);
    steps.push({
        name: "restore",
        ...placed,
        dependsOn: after(...names()),
        guard: expr(`!(${failure})`),
        task: {
            type: "workflow",
            workflowIdOrName: "route-mutation-restore",
            inputs: common,
        },
    });
    steps.push({
        name: "result",
        ...placed,
        dependsOn: after(...names()),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/route-update-result",
            behaviorName: "route-update-result",
            methodName: "read",
            inputs: {
                effectful: expr("inputs.intent.effectful"),
                failure_code: failureCode,
                ...(["route.target_set", "route.create"].includes(kind)
                    ? {
                        preparation_counts: expr(
                            kind === "route.create"
                                ? `inputs.intent.effectful ? {"prepare-certificates": size(${certificateNodes}), "prepare-lan": size(inputs.intent.preparation.lan), "prepare-caddy": size(inputs.intent.preparation.publications), "prepare-probe": size(inputs.intent.preparation.probes), "prepare-upstream": "upstream_firewalls" in inputs.intent.preparation ? size(inputs.intent.preparation.upstream_firewalls) : 0, "verify": "verifications" in inputs.intent ? size(inputs.intent.verifications) : 0} : {}`
                                : `inputs.intent.effectful ? {"prepare-certificates": size(${certificateNodes}), "prepare-lan": size(inputs.intent.preparation.lan), "prepare-caddy": size(inputs.intent.preparation.publications), "prepare-probe": size(inputs.intent.preparation.probes)} : {}`,
                        ),
                    }
                    : {}),
                route_id: expr("inputs.intent.route_id"),
                result_route_id: expr("inputs.intent.result_route_id"),
                workload_count: expr("size(inputs.intent.workloads)"),
                steps: expr("steps"),
            },
        },
    });
    steps.push({
        name: "complete",
        ...placed,
        dependsOn: after("result"),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/route-update-result",
            behaviorName: "route-update-complete",
            methodName: "complete",
            inputs: { stdout: expr("steps.result.outputs.stdout") },
        },
    });
    return {
        id,
        name,
        description:
            "Immutable Route workload mutation and restoration through official209 publication children.",
        vaults: ["orbit-control-inputs"],
        inputs: {
            ...envelope,
            properties: {
                ...envelope.properties,
                kind: { type: "string", enum: [kind] },
            },
        },
        jobs: [{ name: "update", steps }],
    };
}
export function mutationWorkflow(
    mode: "forward" | "restore" | "withdraw",
): Fields {
    const steps = routeMutationSteps(
        mode,
        "inputs.intent.route_mutations",
        mode === "withdraw" ? "forward" : mode,
        undefined,
        "inputs.parent_run_id",
        true,
        mode === "withdraw",
    );
    for (const step of steps) {
        step.allowFailure = true;
        delete step.target;
    }
    steps.push({
        name: "result",
        dependsOn: after(...steps.map((s) => String(s.name))),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/route-update-result",
            behaviorName: "route-mutation-phase",
            methodName: "phase",
            inputs: { mode, steps: expr("steps") },
        },
    });
    return {
        id: mode === "withdraw"
            ? "ab396849-8327-40c1-bcc0-983a99ca412c"
            : mode === "forward"
            ? "b2ec4a6d-fc95-4a2c-b6b8-e26c7bf871c7"
            : "f11fa84e-418c-44cf-86d9-a84f46ef0146",
        name: `route-mutation-${mode}`,
        target: "orbit-node-209",
        description:
            "Canonical Route publications and complete native contribution proof.",
        vaults: ["orbit-control-inputs"],
        inputs: {
            ...envelope,
            properties: {
                ...envelope.properties,
                kind: { type: "string" },
                parent_run_id: { type: "string", format: "uuid" },
            },
            required: [...envelope.required, "parent_run_id"],
        },
        jobs: [{ name: mode, steps }],
    };
}

export function ingressWorkflow(): Fields {
    return {
        id: "4977fbb9-658c-4d9c-a984-d003f4dbe225",
        name: "route-ingress-apply",
        queueTimeout: 30,
        description:
            "Converge public ingress through the canonical pinned tuple child.",
        vaults: [],
        inputs: {
            ...envelope,
            properties: {
                ...envelope.properties,
                kind: { type: "string" },
                execution_secrets: {
                    type: "object",
                    additionalProperties: false,
                },
            },
        },
        jobs: [{
            name: "firewall",
            steps: [{
                name: "converge",
                ...placed,
                task: {
                    type: "workflow",
                    workflowIdOrName: "route-lan-apply",
                    inputs: {
                        operation_id: expr("inputs.operation_id"),
                        node_id: expr("inputs.node_id"),
                        kind: expr("inputs.kind"),
                        execution_secrets: {},
                        intent: {
                            control_node_id: 209,
                            connection: expr("inputs.intent.connection"),
                            host_keys: expr("inputs.intent.host_keys"),
                            rule: {
                                node_id: expr("inputs.intent.rule.node_id"),
                                migrate: false,
                                rules: expr(
                                    'inputs.intent.rule.rules.map(r, {"model_name": "public-" + string(r.port), "port": r.port, "protocol": "tcp", "source": "any", "ensure": r.ensure})',
                                ),
                            },
                        },
                    },
                },
            }, {
                name: "result",
                ...placed,
                dependsOn: [{
                    step: "converge",
                    condition: { type: "succeeded" },
                }],
                task: {
                    type: "behavior",
                    behaviorType: "@nckrtl/route-update-result",
                    behaviorName: "route-ingress-result",
                    methodName: "firewall",
                    inputs: {
                        ready: expr("steps.converge.outputs.result.ready"),
                    },
                },
            }],
        }],
    };
}
