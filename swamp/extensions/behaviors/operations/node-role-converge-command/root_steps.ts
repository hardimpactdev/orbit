import { roleResultInputs } from "./result_inputs.ts";
import { roleBaselinePhases, rolePhase } from "./phase_steps.ts";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
const on = (
    step: string,
    type = "succeeded",
) => [{ step, condition: { type } }];
const target = { target: "orbit-node-209" };
/** Empty/singleton actions bind optional data to self before expression evaluation. */
function select(group: Fields[], item: string, list: string): Fields[] {
    for (const step of group) {
        step.forEach = { item, in: ex(list) };
        let task = JSON.stringify(step.task);
        for (const member of group) {
            task = task.replaceAll(
                String(member.name) + '\\"',
                String(member.name) + '-0\\"',
            );
        }
        step.task = JSON.parse(task);
        const typed = step.task as Fields;
        typed.behaviorName = ex(
            '"' + step.name + '-"+run.id+"-"+string(self._index)',
        );
        if (typed.globalArgs) {
            typed.globalArgs = {
                ...typed.globalArgs as Fields,
                name: typed.behaviorName,
            };
        }
    }
    return group;
}
export type RoleConvergeOptions = {
    preparedToolsExpr?: string;
    observerDigestExpr?: string;
    executionSecretsExpr?: string;
    rootRunIdExpr?: string;
};
export function roleConvergeSteps(
    name: string,
    intent: string,
    globals: Fields,
    dependency?: string,
    guard?: string,
    options: RoleConvergeOptions = {},
): Fields[] {
    const qualify = (key: string) => name ? name + "_" + key : key;
    const secrets = "__ROLE_EXECUTION_SECRETS__";
    const steps = roleBaselinePhases(
        globals,
        Boolean(options.preparedToolsExpr),
    );
    const ingress =
        'inputs.intent.role in ["ingress","app-prod"] ? [inputs.intent] : []';
    steps.push(...select(
        [{
            name: "runtime",
            ...target,
            dependsOn: on("packages"),
            task: {
                type: "workflow",
                workflowIdOrName: "node-role-websocket",
                inputs: {
                    runtime: ex("self.role.runtime"),
                    ssh: ex("self.role.ssh"),
                    material: ex("self.role.runtime_material"),
                },
            },
        }],
        "role",
        'inputs.intent.role == "websocket" ? [{"runtime":inputs.intent.runtime,"ssh":inputs.intent.ssh,"runtime_material":' +
            secrets + ".runtime_material}] : []",
    ));
    steps.push(...select(
        [{
            name: "certificates",
            ...target,
            dependsOn: [...on("packages"), ...on("runtime", "always")],
            guard: ex(
                'steps.exists(k,k.startsWith("runtime-") && steps[k].status != "succeeded")',
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "node-role-certificate-publish",
                inputs: {
                    operation_id: ex("inputs.operation_id"),
                    node_id: ex("inputs.node_id"),
                    kind: "node.role_converge",
                    parent_run_id: ex("__ROLE_ROOT_RUN_ID__"),
                    leaf: ex("self.leaf"),
                    ssh: globals,
                },
            },
        }],
        "leaf",
        'inputs.intent.role == "websocket" ? inputs.intent.certificates.leaves : []',
    ));

    steps.push(
        ...select(
            [{
                name: "caddy_service",
                ...target,
                writes: true,
                dependsOn: [
                    ...on("packages"),
                    ...on("runtime", "always"),
                    ...on("certificates", "always"),
                ],
                guard: ex(
                    'steps.exists(k,(k.startsWith("runtime-") || k.startsWith("certificates-")) && steps[k].status != "succeeded")',
                ),
                task: {
                    type: "behavior",
                    behaviorType: "@swamp/ssh",
                    methodName: "exec",
                    globalArgs: globals,
                    inputs: {
                        hosts: "name:target",
                        sudo: true,
                        argv: [
                            "/usr/bin/timeout",
                            "30",
                            "/usr/bin/python3",
                            "-B",
                            ex('__ROLE_STAGE__+"/phase_caddy_service.py"'),
                        ],
                    },
                },
            }, {
                name: "caddy",
                ...target,
                dependsOn: on("caddy_service"),
                task: {
                    type: "workflow",
                    workflowIdOrName: "operation-route-caddy",
                    inputs: {
                        operation_id: ex("inputs.operation_id"),
                        node_id: ex("inputs.node_id"),
                        kind: "node.role_converge",
                        execution_secrets: {},
                        intent: { caddy_publication: ex("self.role.caddy") },
                    },
                },
            }],
            "role",
            ingress,
        ),
    );
    steps.push(...select(
        [{
            name: "websocket_caddy_service",
            ...target,
            writes: true,
            dependsOn: [
                ...on("packages"),
                ...on("runtime", "always"),
                ...on("certificates", "always"),
            ],
            guard: ex(
                'steps.exists(k,(k.startsWith("runtime-") || k.startsWith("certificates-")) && steps[k].status != "succeeded")',
            ),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                methodName: "exec",
                globalArgs: globals,
                inputs: {
                    hosts: "name:target",
                    sudo: true,
                    argv: [
                        "/usr/bin/timeout",
                        "30",
                        "/usr/bin/python3",
                        "-B",
                        ex('__ROLE_STAGE__+"/phase_caddy_service.py"'),
                    ],
                },
            },
        }],
        "role",
        'inputs.intent.role == "websocket" ? [inputs.intent] : []',
    ));
    steps.push(...select(
        [{
            name: "publication",
            ...target,
            dependsOn: [
                ...on("websocket_caddy_service"),
                ...on("packages"),
                ...on("runtime", "always"),
                ...on("certificates", "always"),
            ],
            guard: ex(
                'steps.exists(k,(k.startsWith("runtime-") || k.startsWith("certificates-")) && steps[k].status != "succeeded")',
            ),
            task: {
                type: "workflow",
                workflowIdOrName: "node-role-publication",
                inputs: {
                    operation_id: ex("inputs.operation_id"),
                    node_id: ex("inputs.node_id"),
                    kind: "node.role_converge",
                    intent: ex("self.role"),
                },
            },
        }],
        "role",
        'inputs.intent.role == "websocket" ? [inputs.intent] : []',
    ));
    const firewall = rolePhase(
        "firewall",
        "phase_firewall",
        globals,
        60,
        "packages",
    );
    const firewallTask = firewall.task as Fields;
    const firewallIdentity = ex(
        '"role-firewall-"+run.id+"-"+string(self._index)',
    );
    firewallTask.behaviorName = firewallIdentity;
    firewallTask.globalArgs = { ...globals, name: firewallIdentity };
    firewall.forEach = {
        item: "firewall",
        in: ex(
            'inputs.intent.role in ["ingress","websocket","app-prod"] ? [inputs.intent.firewall] : []',
        ),
    };
    firewall.dependsOn = [
        ...on("packages"),
        ...on("caddy", "always"),
        ...on("publication", "always"),
    ];
    firewall.guard = ex(
        '(inputs.intent.role in ["ingress","app-prod"] && !("caddy-0" in steps && steps["caddy-0"].status == "succeeded")) || (inputs.intent.role == "websocket" && !("publication-0" in steps && steps["publication-0"].status == "succeeded"))',
    );
    steps.push(firewall);
    const transport = globals.transport as Fields;
    const host = (globals.hosts as Fields[])[0];
    steps.push({
        name: "peer_dns",
        ...target,
        forEach: {
            item: "dns",
            in: ex(
                "inputs.intent.footprint.network == null ? [] : [inputs.intent.footprint.network.peer_dns]",
            ),
        },
        dependsOn: on("packages"),
        task: {
            type: "workflow",
            workflowIdOrName: "operation-fleet-peer-dns",
            inputs: {
                operation_id: ex("inputs.operation_id"),
                node_id: ex("inputs.node_id"),
                kind: "fleet.peer_dns",
                execution_secrets: {},
                intent: {
                    server: ex("self.dns.dns_server"),
                    dns_server: ex("self.dns.dns_server"),
                    public_key: ex("self.dns.public_key"),
                    domains: ex("self.dns.domains"),
                    ssh: {
                        control_node_id: 209,
                        identity_file: transport.identityFile,
                        target: {
                            host: host.address,
                            user: transport.user,
                            port: transport.port,
                            host_keys: (host.transport as Fields).hostKeys,
                        },
                    },
                },
            },
        },
    });
    steps.push(
        rolePhase(
            "php_runtime",
            "phase_php_runtime",
            globals,
            120,
            "packages",
            {
                guard: '! ("php-runtime" in inputs.intent.footprint.payloads)',
            },
        ),
    );
    steps.push(
        rolePhase("observer", "phase_observer", globals, 120, "packages", {
            stdin: "__ROLE_OBSERVER_DIGEST__",
            delivery: true,
        }),
    );
    steps.push(rolePhase("cli", "phase_cli", globals, 240, "packages", {
        guard:
            "inputs.intent.cli.skip_reason != null || inputs.intent.cli.plan_error != null",
    }));
    for (const step of steps) {
        delete step.labels;
        step.allowFailure = true;
        step.queueTimeout = 30;
    }

    if (options.preparedToolsExpr) {
        const admission = {
            name: "prepared_admission",
            ...target,
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/node-role-converge-command",
                behaviorName: qualify("prepared-admission"),
                methodName: "prepared_tools",
                inputs: {
                    node_id: ex("inputs.intent.node_id"),
                    required_packages: ex(
                        "inputs.intent.prerequisites.packages",
                    ),
                    contribution: ex("__PREPARED_TOOLS__"),
                },
            },
        };
        steps[0].dependsOn = on("prepared_admission");
        steps.unshift(admission);
    }
    for (const step of steps) {
        step.dependsOn = [
            ...((step.dependsOn as Fields[] | undefined) ?? []),
            ...on("role_admission"),
            ...(options.preparedToolsExpr && step.name !== "prepared_admission"
                ? on("prepared_admission")
                : []),
        ];
    }
    steps.unshift({
        name: "role_admission",
        ...target,
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: qualify("role-admission"),
            methodName: "plan",
            inputs: { intent: ex("inputs.intent") },
        },
    });
    const owned = steps.map((s) => String(s.name)).sort((a, b) =>
        b.length - a.length
    );
    const bind = (value: unknown, key = ""): unknown => {
        if (
            key === "globalArgs" || (key === "ssh" && typeof value === "object")
        ) return value;
        if (Array.isArray(value)) return value.map((v) => bind(v));
        if (value && typeof value === "object") {
            return Object.fromEntries(
                Object.entries(value).map(([k, v]) => [k, bind(v, k)]),
            );
        }
        if (
            typeof value !== "string" ||
            (!value.startsWith("${{") && key !== "expr")
        ) return value;
        let out = value.replaceAll("inputs.intent", intent).replaceAll(
            "__ROLE_STAGE__",
            JSON.stringify("/tmp/orbit-role-" + (name ? name + "-" : "")) +
                "+run.id",
        ).replaceAll(
            "__PREPARED_TOOLS__",
            options.preparedToolsExpr ?? "null",
        ).replaceAll(
            "__ROLE_OBSERVER_DIGEST__",
            options.observerDigestExpr ??
                "vault.get(inputs.execution_secrets.observer.vault,inputs.execution_secrets.observer.key)",
        ).replaceAll(
            "__ROLE_EXECUTION_SECRETS__",
            options.executionSecretsExpr ?? "inputs.execution_secrets",
        ).replaceAll("__ROLE_ROOT_RUN_ID__", options.rootRunIdExpr ?? "run.id");
        for (const source of owned) {
            const dest = qualify(source);
            out = out.replaceAll(
                "steps." + source + ".",
                "steps[" + JSON.stringify(dest) + "].",
            )
                .replaceAll(
                    "steps[" + JSON.stringify(source) + "]",
                    "steps[" + JSON.stringify(dest) + "]",
                )
                .replaceAll(
                    "steps[" + JSON.stringify(source + "-"),
                    "steps[" + JSON.stringify(dest + "-"),
                )
                .replaceAll(
                    "steps[" + JSON.stringify(source + "-0") + "]",
                    "steps[" + JSON.stringify(dest + "-0") + "]",
                )
                .replaceAll(
                    "k.startsWith(" + JSON.stringify(source) + ")",
                    "k.startsWith(" + JSON.stringify(dest) + ")",
                )
                .replaceAll(
                    "k.startsWith(" + JSON.stringify(source + "-") + ")",
                    "k.startsWith(" + JSON.stringify(dest + "-") + ")",
                )
                .replaceAll(
                    JSON.stringify(source + "-0") + " in steps",
                    JSON.stringify(dest + "-0") + " in steps",
                )
                .replaceAll(
                    JSON.stringify(source) + " in steps",
                    JSON.stringify(dest) + " in steps",
                );
        }
        // Observer predicates carry explicit generated step-name arrays, never public data.
        out = out.replace(
            /(\[)([^\[\]]*)(\]\.exists\(prior,)/g,
            (all, a, list, z) =>
                a + list.replace(/"([^"\n]+)"/g, (raw: string, k: string) =>
                    owned.includes(k) ? JSON.stringify(qualify(k)) : raw) +
                z,
        );
        return out;
    };
    for (const step of steps) {
        step.name = qualify(String(step.name));
        step.task = bind(step.task);
        if (step.dependsOn) {
            step.dependsOn = (step.dependsOn as Fields[]).map((d) => ({
                ...d,
                step: qualify(String(d.step)),
            }));
        }
        if (step.guard) step.guard = bind(step.guard);
        if (step.forEach) step.forEach = bind(step.forEach);
        if (guard) {
            step.guard = ex(
                "(" + guard + ") || (" + (typeof step.guard === "string"
                    ? step.guard.slice(3, -2)
                    : "false") +
                    ")",
            );
        }
    }
    if (dependency) steps[0].dependsOn = on(dependency);
    steps.push({
        name: qualify("role_result"),
        ...target,
        ...(guard ? { guard: ex(guard) } : {}),
        dependsOn: steps.map((s) => ({
            step: s.name,
            condition: { type: "always" },
        })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-result",
            behaviorName: qualify("role-result"),
            methodName: "assemble",
            inputs: roleResultInputs(intent, name ? name + "_" : ""),
        },
    });
    for (const step of steps) step.queueTimeout = 30;
    return steps;
}
