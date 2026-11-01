import {
    roleActionSteps,
    rolePackageSteps,
} from "../node-role-command/steps.ts";
import { routeMutationSteps } from "../routes/mutation-command/steps.ts";
type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const queueTimeout = 600;
const placement = { target: "orbit-node-209", queueTimeout };
const queued = (steps: Fields[]) =>
    steps.map((step) => ({ ...step, queueTimeout }));
const completed = { type: "always" };
const after = (
    name: string,
    type = "succeeded",
) => [{ step: name, condition: { type } }];
const envelope = {
    type: "object",
    properties: {
        operation_id: { type: "integer", minimum: 1 },
        node_id: { type: "integer", minimum: 1 },
        kind: { type: "string" },
        intent: { type: "object" },
        execution_secrets: { type: "object", additionalProperties: false },
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
export function sshGlobals(intent = "inputs.intent") {
    return {
        name: expr('"cluster-router-ssh-" + run.id'),
        transport: {
            kind: "ssh",
            user: expr(intent + ".connection.nodeUser"),
            identityFile: expr(intent + ".connection.nodeIdentityFile"),
            port: expr(intent + ".connection.nodePort"),
        },
        hosts: [{
            name: "target",
            address: expr(intent + ".connection.nodeHost"),
            transport: { hostKeys: expr(intent + ".host_keys") },
        }],
    };
}
const common = {
    operation_id: expr("inputs.operation_id"),
    node_id: expr("inputs.node_id"),
    kind: expr("inputs.kind"),
    execution_secrets: {},
};
export function clusterRouterWorkflow(): Fields {
    const steps: Fields[] = [
        {
            name: "prepare",
            ...placement,
            guard: expr(
                "inputs.intent.cluster_router.noop || inputs.intent.cluster_router.candidate == null",
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "cluster-router-prepare",
                inputs: {
                    ...common,
                    intent: expr("inputs.intent.cluster_router.candidate"),
                },
            },
        },
        {
            name: "forward",
            ...placement,
            dependsOn: after("prepare", "always"),
            guard: expr(
                'inputs.intent.cluster_router.noop || steps.exists(key, key == "prepare" && steps[key].status == "failed")',
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "cluster-router-forward",
                inputs: {
                    ...common,
                    intent: expr("inputs.intent"),
                    parent_run_id: expr("run.id"),
                },
            },
        },
        {
            name: "restore",
            ...placement,
            dependsOn: after("forward", "always"),
            guard: expr(
                '!("forward" in steps) || steps.forward.status != "succeeded" || steps.forward.outputs.result.ready',
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "cluster-router-restore",
                inputs: {
                    ...common,
                    intent: expr("inputs.intent"),
                    parent_run_id: expr("run.id"),
                },
            },
        },
        {
            name: "retire",
            ...placement,
            dependsOn: after("forward", "always"),
            forEach: {
                item: "old",
                in: expr("inputs.intent.cluster_router.old_nodes"),
            },
            guard: expr(
                '!("forward" in steps) || steps.forward.status != "succeeded" || !steps.forward.outputs.result.ready',
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "route-lan-apply",
                inputs: {
                    ...common,
                    intent: {
                        rule: expr("self.old.firewall"),
                        control_node_id: 209,
                        connection: expr("self.old.connection"),
                        host_keys: expr("self.old.host_keys"),
                    },
                },
            },
        },
    ];
    steps.push({
        name: "result",
        ...placement,
        dependsOn: steps.map((s) => ({ step: s.name, condition: completed })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/cluster-router-result",
            behaviorName: expr('"cluster-router-result-" + run.id'),
            methodName: "read",
            inputs: {
                noop: expr("inputs.intent.cluster_router.noop"),
                old_count: expr("size(inputs.intent.cluster_router.old_nodes)"),
                steps: expr("steps"),
            },
        },
    });
    steps.push({
        name: "complete",
        ...placement,
        dependsOn: after("result"),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/cluster-router-result",
            behaviorName: expr('"cluster-router-complete-" + run.id'),
            methodName: "complete",
            inputs: { stdout: expr("steps.result.outputs.stdout") },
        },
    });
    return {
        id: "30f3bb33-0431-4fdf-9cd5-b54c5da45354",
        name: "operation-cluster-router",
        description:
            "One immutable Router transition on209 through shared native publication children.",
        vaults: ["orbit-control-inputs"],
        inputs: envelope,
        queueTimeout,
        jobs: [{ name: "router", steps: queued(steps) }],
    };
}
/** Reuse the canonical Route factory; native allowed failures preserve its partial public outcomes. */
export function publicationWorkflow(mode: "forward" | "restore"): Fields {
    const predicate =
        '!steps.exists(key, key.startsWith("verify-") && steps[key].status == "failed")';
    const steps = routeMutationSteps(
        mode,
        "inputs.intent.route_mutations",
        mode,
        predicate,
        "inputs.parent_run_id",
    );
    if (mode === "forward") {
        const verify: Fields = {
            name: "verify",
            ...placement,
            forEach: {
                item: "probe",
                in: expr(
                    "inputs.intent.cluster_router.candidate == null ? [] : inputs.intent.cluster_router.candidate.probes",
                ),
            },
            dependsOn: steps.slice(0, 3).map((s) => ({
                step: s.name,
                condition: completed,
            })),
            guard: expr(
                'steps.exists(key, key.startsWith("forward_") && steps[key].status == "failed")',
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "cluster-router-probe",
                inputs: { ...common, intent: expr("self.probe") },
            },
        };
        for (const step of steps.slice(3)) {
            (step.dependsOn as Fields[]).push({
                step: "verify",
                condition: completed,
            });
        }
        steps.splice(3, 0, verify);
    }
    for (const step of steps) step.allowFailure = true;
    steps.push({
        name: "result",
        ...placement,
        dependsOn: steps.map((s) => ({ step: s.name, condition: completed })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/cluster-router-result",
            behaviorName: expr('"cluster-router-phase-" + run.id'),
            methodName: "phase",
            inputs: {
                mode,
                dns_count: expr(
                    `size(inputs.intent.route_mutations.${mode}.publications.filter(p,p.prepared.dns != null))`,
                ),
                steps: expr("steps"),
            },
        },
    });
    return {
        id: mode === "forward"
            ? "8c9d019a-a5f2-46b6-8042-c1e8e28e6ab4"
            : "52f89117-562e-409e-a91a-07969d17a37b",
        name: `cluster-router-${mode}`,
        description:
            "Shared Route publication with exact contribution proof and public recovery summary.",
        vaults: ["orbit-control-inputs"],
        inputs: {
            ...envelope,
            properties: {
                ...envelope.properties,
                parent_run_id: { type: "string", format: "uuid" },
            },
            required: [...envelope.required, "parent_run_id"],
        },
        queueTimeout,
        jobs: [{ name: mode, steps: queued(steps) }],
    };
}
export function prepareWorkflow(): Fields {
    const globals = sshGlobals();
    const steps = [
        ...roleActionSteps(
            "account",
            "account",
            '{"user": inputs.intent.user}',
            globals,
        ),
        ...rolePackageSteps(
            "packages",
            "inputs.intent.prerequisites",
            globals,
            "account",
        ),
        ...roleActionSteps(
            "caddy",
            "caddy_package",
            "inputs.intent.caddy_package",
            globals,
            "packages",
        ),
    ];
    const phaseSeconds: Record<string, string> = {
        account_command: "30",
        packages: "600",
        caddy_command: "300",
    };
    for (const step of steps) {
        const seconds = phaseSeconds[step.name as string];
        if (seconds === undefined) continue;
        const task = step.task as Fields;
        const inputs = task.inputs as Fields;
        task.methodName = "exec";
        task.inputs = {
            hosts: inputs.hosts,
            sudo: inputs.sudo,
            timeoutSec: Number(seconds) + 30,
            argv: [
                "/usr/bin/timeout",
                seconds,
                "/usr/bin/python3",
                "-I",
                "-c",
                inputs.script,
            ],
        };
    }
    steps.push({
        name: "firewall",
        ...placement,
        dependsOn: after("caddy"),
        task: {
            type: "workflow",
            workflowIdOrName: "route-lan-apply",
            inputs: {
                ...common,
                intent: {
                    rule: expr("inputs.intent.firewall"),
                    control_node_id: 209,
                    connection: expr("inputs.intent.connection"),
                    host_keys: expr("inputs.intent.host_keys"),
                },
            },
        },
    });
    return {
        id: "19bf171c-cf2a-4509-beb7-3373c29f19af",
        name: "cluster-router-prepare",
        description:
            "Canonical Router prerequisites, Caddy package and owned firewall policy through official SSH.",
        vaults: [],
        inputs: envelope,
        queueTimeout,
        jobs: [{ name: "prepare", steps: queued(steps) }],
    };
}
export function probeWorkflow(): Fields {
    return {
        id: "5cbf1292-9922-444b-9225-fb2b682111f7",
        name: "cluster-router-probe",
        queueTimeout,
        description:
            "Verify one admitted workload TLS endpoint from the candidate Router through official SSH.",
        vaults: [],
        inputs: envelope,
        jobs: [{
            name: "probe",
            steps: [{
                name: "probe",
                ...placement,
                task: {
                    type: "behavior",
                    behaviorType: "@swamp/ssh",
                    behaviorName: expr('"cluster-router-probe-" + run.id'),
                    methodName: "exec",
                    globalArgs: sshGlobals(),
                    inputs: {
                        hosts: "name:target",
                        timeoutSec: 40,
                        argv: expr(
                            '"protocol" in inputs.intent && inputs.intent.protocol == "http" ? ["/usr/bin/timeout","10","/usr/bin/curl","--head","--noproxy","*","--silent","--connect-timeout","10","--max-time","10","--output","/dev/null","--header","Host: " + inputs.intent.probe.domain,"http://" + inputs.intent.probe.address + ":" + string(inputs.intent.probe.port) + "/"] : ["/usr/bin/timeout","10","/usr/bin/openssl","s_client","-quiet","-no_ign_eof","-connect",inputs.intent.probe.address + ":" + string(inputs.intent.probe.port),"-servername",inputs.intent.probe.domain,"-verify_hostname",inputs.intent.probe.domain,"-verify_return_error"]',
                        ),
                    },
                },
            }],
        }],
    };
}
if (import.meta.main) {
    console.log(JSON.stringify({
        "workflow-operation-cluster-router.yaml": clusterRouterWorkflow(),
        "workflow-cluster-router-prepare.yaml": prepareWorkflow(),
        "workflow-cluster-router-forward.yaml": publicationWorkflow("forward"),
        "workflow-cluster-router-restore.yaml": publicationWorkflow("restore"),
        "workflow-cluster-router-probe.yaml": probeWorkflow(),
    }));
}
