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
    const steps: Fields[] = [];
    const names = () => steps.map((s) => String(s.name));
    const failure =
        'inputs.intent.effectful && (!("forward" in steps) || steps.forward.status != "succeeded" || !steps.forward.outputs.result.ready || steps.exists(k,k.startsWith("retire-") && steps[k].status == "failed"))';
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
    steps.push({
        name: "forward",
        ...placed,
        dependsOn: after(...names()),
        guard: expr(
            '!inputs.intent.effectful || steps.exists(k,k.startsWith("prepare-") && steps[k].status == "failed")',
        ),
        task: {
            type: "workflow",
            workflowIdOrName: "route-mutation-forward",
            inputs: common,
        },
    });
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
        id: "b1a5e413-5c52-4d05-966b-f78d57c2da06",
        name: "operation-route-update",
        description:
            "Immutable Route update and restoration through official209 publication children.",
        vaults: ["orbit-control-inputs"],
        inputs: envelope,
        jobs: [{ name: "update", steps }],
    };
}
export function mutationWorkflow(mode: "forward" | "restore"): Fields {
    const steps = routeMutationSteps(
        mode,
        "inputs.intent.route_mutations",
        mode,
        undefined,
        "inputs.parent_run_id",
        true,
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
        id: mode === "forward"
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
