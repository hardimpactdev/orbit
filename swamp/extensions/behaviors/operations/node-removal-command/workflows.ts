import { stringify } from "jsr:@std/yaml@1.0.12";
type Fields = Record<string, unknown>;
const ex = (source: string) => "${{ " + source + " }}";
const placed = { target: "orbit-node-209", queueTimeout: 30 };
const settled = {
    type: "or",
    conditions: [{ type: "succeeded" }, { type: "skipped" }],
};
const dep = (step: string) => [{ step, condition: settled }];
const resource = (
    name: string,
    methodName: string,
    inputs: Fields,
): Fields => ({
    name,
    ...placed,
    task: {
        type: "behavior",
        behaviorType: "@nckrtl/node-removal-command",
        behaviorName: ex(JSON.stringify(name + "-") + "+run.id"),
        methodName,
        inputs,
    },
});
const globals = (source: string, name: string): Fields => ({
    name: ex(JSON.stringify(name + "-") + '+run.id+"-"+string(self._index)'),
    transport: {
        kind: "ssh",
        user: ex(source + ".target.user"),
        port: ex(source + ".target.port"),
        identityFile: ex(source + ".identity_file"),
    },
    hosts: [{
        name: "target",
        address: ex(source + ".target.host"),
        transport: { hostKeys: ex(source + ".target.host_keys") },
    }],
});
const baseInputs = {
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
function action(
    name: string,
    method: string,
    items: string,
    previous: string,
    sudo = true,
): Fields[] {
    const each = { item: "action", in: ex(items) };
    const identity = ex(
        JSON.stringify(name + "-") + '+run.id+"-"+string(self._index)',
    );
    return [{
        ...resource(name + "_assets", "read", {
            action: method,
            input: ex("self.action.input"),
        }),
        forEach: each,
        dependsOn: dep(previous),
        task: {
            ...(resource(name + "_assets", "read", {
                action: method,
                input: ex("self.action.input"),
            }).task as Fields),
            behaviorName: ex(
                JSON.stringify(name + "-assets-") +
                    '+run.id+"-"+string(self._index)',
            ),
        },
    }, {
        name,
        ...placed,
        writes: true,
        concurrency: 1,
        forEach: each,
        dependsOn: dep(name + "_assets"),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: identity,
            methodName: "script",
            globalArgs: globals("self.action.ssh", name),
            inputs: {
                hosts: "name:target",
                interpreter: "python3",
                sudo,
                script: ex(
                    'steps["' + name +
                        '_assets-"+string(self._index)].outputs.script_body',
                ),
            },
        },
    }];
}
function workflow(
    name: string,
    id: string,
    steps: Fields[],
    inputs: Fields = baseInputs,
) {
    // An empty fan-out is skipped. A skipped successor of a failed phase must
    // never reopen the cleanup chain: every later step sees the original failure.
    for (const step of steps) {
        const prior = typeof step.guard === "string"
            ? step.guard.slice(3, -2).trim()
            : "";
        const tokenPhase = name === "operation-node-remove" &&
            [
                "revoke_worker",
                "revoke_access",
            ].includes(String(step.name));
        step.guard = ex(
            'steps.exists(k,steps[k].status == "failed" || steps[k].status == "cancelled")' +
                (tokenPhase ? " || inputs.intent.task_vm_guest" : "") +
                (prior ? " || (" + prior + ")" : ""),
        );
    }
    // A native empty forEach has no concrete step result. Phase jobs provide
    // native success barriers, without turning an empty fan-out into a failure
    // or allowing a skipped dependency to reopen a failed chain.
    const groups: Fields[][] = [];
    for (let index = 0; index < steps.length;) {
        const step = steps[index];
        const count = name === "operation-node-remove" && index === 0
            ? 4
            : String(step.name).endsWith("_assets") ||
                    step.name === "prepare_result"
            ? 2
            : 1;
        const group = steps.slice(index, index + count);
        const localNames = group.map((s) => s.name);
        for (const member of group) {
            if (member.dependsOn) {
                const edges = (member.dependsOn as Fields[]).filter((edge) =>
                    localNames.includes(edge.step)
                );
                if (edges.length) member.dependsOn = edges;
                else delete member.dependsOn;
            }
        }
        groups.push(group);
        index += count;
    }
    const jobs = groups.map((group, index) => ({
        name: index === groups.length - 1
            ? (name === "operation-node-remove" ? "remove" : "role")
            : "phase_" + String(group[0].name),
        ...(index
            ? {
                dependsOn: [{
                    job: "phase_" + String(groups[index - 1][0].name),
                    condition: { type: "succeeded" },
                }],
            }
            : {}),
        steps: group,
    }));
    return {
        id,
        name,
        description:
            "Public input, native209 actions and typed terminal output; no Gateway callbacks.",
        vaults: [],
        inputs,
        target: "orbit-node-209",
        concurrency: 1,
        jobs,
    };
}
const child = (
    name: string,
    childName: string,
    items: string,
    intent: Fields,
    previous: string,
): Fields => ({
    name,
    ...placed,
    concurrency: 1,
    forEach: { item: "publication", in: ex(items) },
    dependsOn: dep(previous),
    task: {
        type: "workflow",
        workflowIdOrName: childName,
        inputs: {
            operation_id: ex("inputs.operation_id"),
            node_id: ex("inputs.node_id"),
            kind: ex("inputs.kind"),
            execution_secrets: {},
            intent,
        },
    },
});

export function roleRemovalWorkflow() {
    const offline = "inputs.offline";
    const steps: Fields[] = [{
        name: "start",
        ...placed,
        task: {
            type: "assert",
            expr: "inputs.intent.node_id != 209",
            message: "The control worker cannot remove its own runtime",
            severity: "high",
        },
    }];
    let prior = "start";
    for (
        const [name, method, field] of [
            ["stop_units", "stop_unit", "stop_units"],
            ["remove_units", "remove_unit", "remove_units"],
            ["remove_footprints", "remove_footprint", "remove_footprints"],
            ["reload_units", "reload_units", "reload_units"],
            ["stop_containers", "remove_container", "stop_containers"],
            ["remove_containers", "remove_container", "remove_containers"],
            ["ci_project", "ci_project", "ci_host"],
            ["firewall", "firewall", "firewall"],
        ]
    ) {
        steps.push(
            ...action(
                name,
                method,
                `inputs.intent.${field}.filter(a,!inputs.offline || (inputs.intent.ssh == null || a.ssh.target.host != inputs.intent.ssh.target.host))`,
                prior,
            ),
        );
        prior = name;
    }
    const pubs =
        "inputs.intent.publications.filter(p,!inputs.offline || p.node_id != inputs.intent.node_id)";
    steps.push(
        child(
            "php",
            "operation-route-php-delta",
            pubs + ".filter(p,p.after.php != null)",
            {
                node_id: ex("self.publication.node_id"),
                user: ex("self.publication.connection.nodeUser"),
                control_node_id: 209,
                host_keys: ex("self.publication.host_keys"),
                connection: ex("self.publication.connection"),
                before: ex("self.publication.before.php"),
                after: ex("self.publication.after.php"),
                direction: "forward",
            },
            prior,
        ),
    );
    steps.push(
        child(
            "caddy",
            "operation-route-caddy",
            pubs + ".filter(p,p.after.caddy != null)",
            {
                caddy_publication: {
                    node_id: ex("self.publication.node_id"),
                    user: ex("self.publication.connection.nodeUser"),
                    control_node_id: 209,
                    host_keys: ex("self.publication.host_keys"),
                    connection: ex("self.publication.connection"),
                    caddy: ex("self.publication.after.caddy.caddy"),
                },
            },
            "php",
        ),
    );
    steps.push(
        child(
            "dns",
            "route-dns-publish",
            pubs + ".filter(p,p.after.dns != null)",
            {
                node_id: ex("self.publication.node_id"),
                connection: ex("self.publication.connection"),
                host_keys: ex("self.publication.host_keys"),
                before: ex("self.publication.before.dns"),
                after: ex("self.publication.after.dns"),
                alternatives: [],
            },
            "caddy",
        ),
    );
    steps.push(
        ...action(
            "certificates",
            "remove_certificate",
            "inputs.intent.certificates.filter(c,!inputs.offline || (inputs.intent.ssh == null || c.ssh.target.host != inputs.intent.ssh.target.host))",
            "dns",
        ),
    );
    steps.push({
        ...resource("result", "roleResult", {
            node_id: ex("inputs.intent.node_id"),
            assignment_id: ex("inputs.intent.assignment_id"),
            role: ex("inputs.intent.role"),
            offline: ex("inputs.offline"),
        }),
        dependsOn: dep("certificates"),
    });
    const inputs = structuredClone(baseInputs) as {
        properties: Fields;
        required: string[];
    };
    inputs.properties.offline = { type: "boolean" };
    inputs.required.push("offline");
    return workflow(
        "node-role-remove",
        "a49d3155-9cda-4a77-97c0-ce389d1a3b4a",
        steps,
        inputs,
    );
}

export function nodeRemovalWorkflow() {
    const host = "inputs.intent.ssh";
    const online = "!steps.preflight.outputs.offline";
    const linux = `(${online} && inputs.intent.platform == "linux")`;
    const single = (input: string) =>
        `${linux} ? [{"ssh":${host},"input":(${input})}] : []`;
    const stop = (unit: string) =>
        single('{"unit":' + JSON.stringify(unit) + "}");
    const steps: Fields[] = [
        {
            name: "start",
            ...placed,
            task: {
                type: "assert",
                expr: "inputs.intent.node_id != 209",
                message: "The control Node cannot remove itself",
                severity: "high",
            },
        },
        resource("assets", "paths", {}),
        {
            name: "probe",
            ...placed,
            guard: ex(
                "has(inputs.intent.machine_deleted) && inputs.intent.machine_deleted",
            ),
            dependsOn: dep("assets"),
            task: {
                type: "behavior",
                behaviorType: "command/shell",
                behaviorName: ex('"node-remove-probe-"+run.id'),
                methodName: "execute",
                inputs: {
                    run: 'python3 "$PROBE_SCRIPT" "$PROBE_HOST" "$PROBE_PORT"',
                    env: {
                        PROBE_SCRIPT: ex("steps.assets.outputs.probe"),
                        PROBE_HOST: ex("inputs.intent.probe.host"),
                        PROBE_PORT: ex("string(inputs.intent.probe.port)"),
                    },
                },
            },
        },
        {
            ...resource("preflight", "preflight", {
                stdout: ex(
                    `has(inputs.intent.machine_deleted) && inputs.intent.machine_deleted ? '{"reachable":false}' : steps.probe.outputs.stdout`,
                ),
                offline: ex("inputs.intent.offline"),
                has_ssh: ex("inputs.intent.ssh != null"),
            }),
            dependsOn: dep("probe"),
        },
        {
            name: "roles",
            ...placed,
            concurrency: 1,
            dependsOn: dep("preflight"),
            forEach: { item: "role", in: ex("inputs.intent.roles") },
            task: {
                type: "workflow",
                workflowIdOrName: "node-role-remove",
                inputs: {
                    operation_id: ex("inputs.operation_id"),
                    node_id: ex("inputs.node_id"),
                    kind: ex("inputs.kind"),
                    intent: ex("self.role"),
                    execution_secrets: {},
                    offline: ex("steps.preflight.outputs.offline"),
                },
            },
        },
    ];
    steps.push(
        ...action(
            "public_ssh",
            "firewall",
            single("inputs.intent.public_ssh"),
            "roles",
        ),
    );
    let prior = "public_ssh";
    for (
        const [name, unit] of [
            ["worker_stop", "orbit-node-worker.service"],
            ["guest_worker_stop", "orbit-swamp-worker.service"],
            ["observer_stop", "orbit-agent.service"],
            ["node_exporter_stop", "prometheus-node-exporter.service"],
            ["cadvisor_stop", "orbit-cadvisor.service"],
            ["fpm_exporter_stop", "orbit-fpm-exporter.service"],
        ]
    ) {
        steps.push(...action(name, "stop_unit", stop(unit), prior));
        prior = name;
    }
    for (const group of ["worker", "observer", "exporter"]) {
        const name = group + "_files";
        steps.push(
            ...action(
                name,
                "remove_footprint",
                single(
                    '{"node_id":inputs.intent.node_id,"group":' +
                        JSON.stringify(group) + "}",
                ),
                prior,
            ),
        );
        prior = name;
    }
    steps.push({
        name: "reload_units",
        ...placed,
        writes: true,
        dependsOn: dep(prior),
        forEach: { item: "action", in: ex(single("{}")) },
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: ex(
                '"node-remove-reload-"+run.id+"-"+string(self._index)',
            ),
            methodName: "exec",
            globalArgs: globals("self.action.ssh", "reload"),
            inputs: {
                hosts: "name:target",
                sudo: true,
                argv: ["/usr/bin/systemctl", "daemon-reload"],
            },
        },
    });
    for (
        const [name, kind, previous] of [
            ["revoke_worker", "worker", "reload_units"],
            ["revoke_access", "access", "revoke_worker"],
        ]
    ) {
        steps.push({
            name,
            ...placed,
            writes: true,
            dependsOn: dep(previous),
            task: {
                type: "behavior",
                behaviorType: "command/shell",
                behaviorName: ex(JSON.stringify(name + "-") + "+run.id"),
                methodName: "execute",
                inputs: {
                    run: '/usr/bin/timeout 30 /usr/bin/python3 -I "$REVOKE_SCRIPT" ' +
                        kind + ' "$NODE_ID"',
                    env: {
                        REVOKE_SCRIPT: ex("steps.assets.outputs.revoke_token"),
                        NODE_ID: ex("string(inputs.intent.node_id)"),
                        SWAMP_HOME: "/home/orbit/.swamp",
                        SWAMP_CONFIG_DIR: "/home/orbit/.config/swamp",
                        SWAMP_NO_UPDATE_CHECK: "1",
                        SWAMP_NO_TELEMETRY: "1",
                    },
                },
            },
        });
    }
    steps.push(
        ...action(
            "keys",
            "revoke_keys",
            `${online} ? [{"ssh":${host},"input":({"user":${host}.target.user,"keys":inputs.intent.managed_keys})}] : []`,
            "revoke_access",
            false,
        ),
    );
    steps.push(...action("hub", "hub_peer", "inputs.intent.hub", "keys"));
    steps.push(
        ...action("router", "firewall", "inputs.intent.router_rules", "hub"),
    );
    // Canonical peer DNS metadata; the effect remains an ordinary official SSH action.
    steps.push({
        name: "peer_assets",
        ...placed,
        dependsOn: dep("router"),
        forEach: { item: "peer", in: ex("inputs.intent.peers") },
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: ex('"peer-assets-"+run.id+"-"+string(self._index)'),
            methodName: "dns",
            inputs: {
                dns_server: ex("self.peer.dns_server"),
                public_key: ex("self.peer.public_key"),
                domains: ex("self.peer.domains"),
            },
        },
    }, {
        name: "peers",
        ...placed,
        writes: true,
        dependsOn: dep("peer_assets"),
        forEach: { item: "peer", in: ex("inputs.intent.peers") },
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: ex('"peer-ssh-"+run.id+"-"+string(self._index)'),
            methodName: "script",
            globalArgs: globals("self.peer.ssh", "peer"),
            inputs: {
                hosts: "name:target",
                sudo: true,
                interpreter: "python3",
                script: ex(
                    'steps["peer_assets-"+string(self._index)].outputs.script_body',
                ),
            },
        },
    });
    steps.push(child("dns", "route-dns-publish", "inputs.intent.dns", {
        node_id: ex("self.publication.node_id"),
        connection: ex("self.publication.connection"),
        host_keys: ex("self.publication.host_keys"),
        before: ex("self.publication.before"),
        after: ex("self.publication.after"),
        alternatives: [],
    }, "peers"));
    steps.push({
        name: "metrics_assets",
        ...placed,
        dependsOn: dep("dns"),
        forEach: { item: "file", in: ex("inputs.intent.metrics_files") },
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: ex(
                '"metrics-assets-"+run.id+"-"+string(self._index)',
            ),
            methodName: "read",
            inputs: { action: "file", input: ex("self.file.input") },
        },
    }, {
        name: "metrics",
        ...placed,
        writes: true,
        dependsOn: dep("metrics_assets"),
        forEach: { item: "file", in: ex("inputs.intent.metrics_files") },
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: ex('"metrics-"+run.id+"-"+string(self._index)'),
            methodName: "script",
            globalArgs: globals("self.file.ssh", "metrics"),
            inputs: {
                hosts: "name:target",
                sudo: true,
                interpreter: "python3",
                script: ex(
                    'steps["metrics_assets-"+string(self._index)].outputs.script_body',
                ),
            },
        },
    });
    steps.push({
        name: "metrics_reload",
        ...placed,
        writes: true,
        dependsOn: dep("metrics"),
        forEach: { item: "owner", in: ex("inputs.intent.metrics_reload") },
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: ex(
                '"metrics-reload-"+run.id+"-"+string(self._index)',
            ),
            methodName: "exec",
            globalArgs: globals("self.owner.ssh", "metrics-reload"),
            inputs: {
                hosts: "name:target",
                sudo: true,
                argv: [
                    "/usr/bin/docker",
                    "kill",
                    "--signal",
                    "HUP",
                    "orbit-metrics-prometheus",
                ],
            },
        },
    });
    steps.push({
        ...resource("prepare_result", "result", {
            node_id: ex("inputs.intent.node_id"),
            name: ex("inputs.intent.name"),
            offline: ex("steps.preflight.outputs.offline"),
            roles_shed: ex("inputs.intent.roles.map(role,role.role)"),
            retained_on_node: ex(
                "inputs.intent.preserved_on_node + (steps.preflight.outputs.offline ? inputs.intent.retained_on_node : [])",
            ),
        }),
        dependsOn: dep("metrics_reload"),
    });
    steps.push({
        name: "result",
        ...placed,
        dependsOn: dep("prepare_result"),
        task: {
            type: "behavior",
            behaviorType: "command/shell",
            behaviorName: ex('"node-remove-result-"+run.id'),
            methodName: "execute",
            inputs: {
                run: "printf '%s\\n' \"$RESULT\"",
                env: { RESULT: ex("steps.prepare_result.outputs.result_json") },
            },
        },
    });
    return workflow(
        "operation-node-remove",
        "450e57df-e174-4ad6-bba5-306c2581d919",
        steps,
    );
}

export function generatedRemovalWorkflows() {
    return [roleRemovalWorkflow(), nodeRemovalWorkflow()].map((value) => ({
        path: new URL(
            "../../../../workflows/workflow-" + value.name + ".yaml",
            import.meta.url,
        ),
        text:
            "# deno-fmt-ignore-file\n# Generated from node-removal-command/workflows.ts\n" +
            stringify(value, { flowLevel: 5, lineWidth: -1 }),
    }));
}
if (import.meta.main) {
    for (const file of generatedRemovalWorkflows()) {
        await Deno.writeTextFile(file.path, file.text);
    }
}
