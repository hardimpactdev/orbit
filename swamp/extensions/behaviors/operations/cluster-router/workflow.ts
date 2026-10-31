import {
    roleActionSteps,
    rolePackageSteps,
} from "../node-role-command/steps.ts";
import { routeMutationSteps } from "../routes/mutation-command/steps.ts";
type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
const completed = {
    type: "or",
    conditions: [{ type: "succeeded" }, { type: "skipped" }],
};
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
function child(
    name: string,
    workflow: string,
    list: string,
    dependency?: string,
    guard?: string,
): Fields {
    return {
        name,
        ...placement,
        forEach: { item: "member", in: expr(list) },
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        ...(guard ? { guard: expr(guard) } : {}),
        task: {
            type: "workflow",
            workflowIdOrName: workflow,
            inputs: { ...common, intent: expr("self.member") },
        },
    };
}
export function clusterRouterWorkflow(): Fields {
    const candidate = "inputs.intent.cluster_router.candidate";
    const active =
        '!inputs.intent.cluster_router.noop && !steps.exists(key, key == "prepare" && steps[key].status == "failed")';
    const prepare: Fields = {
        name: "prepare",
        ...placement,
        guard: expr(
            `inputs.intent.cluster_router.noop || ${candidate} == null`,
        ),
        task: {
            type: "workflow",
            workflowIdOrName: "cluster-router-prepare",
            inputs: { ...common, intent: expr(candidate) },
        },
    };
    const forward = routeMutationSteps(
        "forward",
        "inputs.intent.route_mutations",
        "forward",
        active,
    );
    for (const step of forward) {
        (step.dependsOn as Fields[]).push({
            step: "prepare",
            condition: completed,
        });
    }
    const verify: Fields = {
        name: "verify",
        ...placement,
        forEach: {
            item: "probe",
            in: expr(
                `(${active} && ${candidate} != null) ? ${candidate}.probes : []`,
            ),
        },
        dependsOn: [{ step: "forward_lan", condition: { type: "always" } }],
        guard: expr(
            'steps.exists(key, (key.startsWith("forward_") || key == "prepare") && steps[key].status == "failed")',
        ),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expr('"cluster-router-probe-" + run.id'),
            methodName: "exec",
            globalArgs: sshGlobals(candidate),
            inputs: {
                hosts: "name:target",
                argv: expr(
                    '["/usr/bin/timeout","10","/usr/bin/openssl","s_client","-quiet","-no_ign_eof","-connect",self.probe.probe.address + ":" + string(self.probe.probe.port),"-servername",self.probe.probe.domain,"-verify_hostname",self.probe.probe.domain,"-verify_return_error"]',
                ),
            },
        },
    };
    for (
        const step of forward.filter((s) =>
            ["forward_prepare_caddy", "forward_prepare_dns"].includes(
                String(s.name),
            )
        )
    ) {
        (step.dependsOn as Fields[]).push({
            step: "verify",
            condition: completed,
        });
    }
    const restore = routeMutationSteps(
        "restore",
        "inputs.intent.route_mutations",
        "restore",
        'steps.exists(name, name.startsWith("forward_") && steps[name].status == "failed")',
    );
    for (const step of restore) {
        (step.dependsOn as Fields[]).push({
            step: "forward",
            condition: { type: "always" },
        });
    }
    const retire = child(
        "retire",
        "cluster-router-retire",
        "inputs.intent.cluster_router.old_nodes",
        "forward",
        '!("forward-0" in steps && steps["forward-0"].status == "succeeded")',
    );
    retire.dependsOn = [{ step: "forward", condition: { type: "always" } }];
    const steps = [
        prepare,
        ...forward.slice(0, 3),
        verify,
        ...forward.slice(3),
        ...restore,
        retire,
    ];
    steps.push({
        name: "result",
        ...placement,
        dependsOn: steps.map((s) => ({
            step: s.name,
            condition: { type: "always" },
        })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/cluster-router-result",
            behaviorName: expr('"cluster-router-result-" + run.id'),
            methodName: "read",
            inputs: {
                noop: expr("inputs.intent.cluster_router.noop"),
                dns_count: expr(
                    "size(inputs.intent.route_mutations.forward.publications.filter(p,p.prepared.dns != null))",
                ),
                old_count: expr("size(inputs.intent.cluster_router.old_nodes)"),
                steps: expr("steps"),
            },
        },
    });
    return {
        id: "30f3bb33-0431-4fdf-9cd5-b54c5da45354",
        name: "operation-cluster-router",
        description:
            "One immutable Router transition on209 with official SSH children and terminal-only projection.",
        vaults: [],
        inputs: envelope,
        jobs: [{ name: "router", steps }],
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
    // Canonical Router firewall child is composed after the package action.
    steps.push(
        child(
            "firewall",
            "cluster-router-firewall",
            "[inputs.intent]",
            "caddy",
        ),
    );
    return {
        id: "19bf171c-cf2a-4509-beb7-3373c29f19af",
        name: "cluster-router-prepare",
        description:
            "Canonical Router prerequisites, Caddy package and owned firewall policy through official SSH.",
        vaults: [],
        inputs: envelope,
        jobs: [{ name: "prepare", steps }],
    };
}
export function retireWorkflow(): Fields {
    return {
        id: "a16dc0bb-cdb5-45ea-9341-0cdbfd767a3d",
        name: "cluster-router-retire",
        description:
            "Retire only the admitted obsolete Router firewall policy after native Route cleanup.",
        vaults: [],
        inputs: envelope,
        jobs: [{
            name: "retire",
            steps: [
                child("firewall", "cluster-router-firewall", "[inputs.intent]"),
            ],
        }],
    };
}
export function firewallWorkflow(): Fields {
    const globals = sshGlobals();
    const dir = expr('"/tmp/orbit-router-" + run.id');
    const ssh = (
        name: string,
        argv: unknown[],
        dependency: string,
        sudo = false,
    ): Fields => ({
        name,
        ...placement,
        writes: true,
        dependsOn: after(dependency),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expr('"router-firewall-" + run.id'),
            methodName: "exec",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                argv,
                ...(sudo ? { sudo: true } : {}),
            },
        },
    });
    const steps: Fields[] = [
        {
            name: "assets",
            ...placement,
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/router-firewall-assets",
                behaviorName: expr('"router-firewall-assets-" + run.id'),
                methodName: "read",
                inputs: {
                    firewall: expr("inputs.intent.firewall"),
                    router_lan: expr("inputs.intent.router_lan"),
                },
            },
        },
        ssh(
            "prepare-assets",
            ["/usr/bin/mkdir", "-p", "-m", "0700", dir],
            "assets",
        ),
        {
            name: "copy-assets",
            ...placement,
            dependsOn: after("prepare-assets"),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: expr('"router-firewall-copy-" + run.id'),
                methodName: "copy",
                globalArgs: globals,
                inputs: {
                    hosts: "name:target",
                    src: expr("steps.assets.outputs.scripts_directory"),
                    dst: expr('"/tmp/orbit-router-" + run.id + "/"'),
                    direction: "to",
                    recursive: true,
                },
            },
        },
        ssh(
            "rules",
            [
                "env",
                expr('"ORBIT_ROUTER_INPUT=" + steps.assets.outputs.rules_json'),
                "/usr/bin/python3",
                "-B",
                expr('"/tmp/orbit-router-" + run.id + "/scripts/rules.py"'),
            ],
            "copy-assets",
            true,
        ),
        ssh(
            "router-lan",
            [
                "env",
                expr(
                    '"ORBIT_ROUTER_INPUT=" + steps.assets.outputs.router_json',
                ),
                "/usr/bin/python3",
                "-B",
                expr(
                    '"/tmp/orbit-router-" + run.id + "/scripts/router_lan.py"',
                ),
            ],
            "rules",
            true,
        ),
        ssh("cleanup-assets", ["/usr/bin/rm", "-rf", dir], "router-lan"),
        {
            name: "result",
            ...placement,
            dependsOn: after("cleanup-assets"),
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/router-firewall-assets",
                behaviorName: expr('"router-firewall-result-" + run.id'),
                methodName: "decode",
                inputs: {
                    rules_stdout: expr("steps.rules.outputs.stdout"),
                    router_stdout: expr('steps["router-lan"].outputs.stdout'),
                },
            },
        },
    ];
    return {
        id: "aa3b320a-572a-4fc6-93f0-442e123a9b13",
        name: "cluster-router-firewall",
        description:
            "Two separate canonical UFW actions for one admitted Router through official SSH.",
        vaults: [],
        inputs: envelope,
        jobs: [{ name: "firewall", steps }],
    };
}
if (import.meta.main) {
    console.log(JSON.stringify({
        "workflow-operation-cluster-router.yaml": clusterRouterWorkflow(),
        "workflow-cluster-router-prepare.yaml": prepareWorkflow(),
        "workflow-cluster-router-retire.yaml": retireWorkflow(),
        "workflow-cluster-router-firewall.yaml": firewallWorkflow(),
    }));
}
