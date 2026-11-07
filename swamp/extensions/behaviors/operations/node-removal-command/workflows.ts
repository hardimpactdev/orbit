import { stringify } from "jsr:@std/yaml@1.0.12";
type Fields = Record<string, any>;
const ex = (s: string) => "${{ " + s.replaceAll("}}", "} }") + " }}";
const placed = { labels: { role: "swamp" } };
const schema = {
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
const nameFor = (family: string, source = "self.action.ssh") =>
    ex(`("remove-${family}-" + string(${source}.target.host) + "-" + string(self._index)).split(".").join("-")`);
const connection = (source: string) => ({
    nodeHost: ex(source + ".target.host"),
    nodeUser: ex(source + ".target.user"),
    nodePort: ex(source + ".target.port"),
    nodeIdentityFile: ex(source + ".identity_file"),
    become: true,
});
function cfg(
    name: string,
    type: string,
    items: string,
    args: Fields,
    method = "apply",
): Fields {
    return {
        name,
        ...placed,
        ...(type === "docker_container"
            ? {
                dataOutputOverrides: [{
                    specName: "state",
                    vaultName: "orbit-control-inputs",
                }],
            }
            : {}),
        ...(method === "apply" ? { writes: true } : {}),
        forEach: { item: "action", in: ex(items) },
        task: {
            type: "behavior",
            behaviorType: "@swamp/cfgmgmt/" + type,
            behaviorName: nameFor(
                type + "-" + name.toLowerCase().replaceAll(" ", "-"),
            ),
            methodName: method,
            globalArgs: { ...connection("self.action.ssh"), ...args },
            inputs: {},
        },
    };
}
function ssh(
    name: string,
    items: string,
    argv: unknown,
    options: Fields = {},
): Fields {
    const identity = nameFor(name.toLowerCase().replaceAll(" ", "-"));
    return {
        name,
        ...placed,
        writes: true,
        forEach: { item: "action", in: ex(items) },
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: identity,
            methodName: "exec",
            globalArgs: {
                name: identity,
                transport: {
                    kind: "ssh",
                    user: ex("self.action.ssh.target.user"),
                    port: ex("self.action.ssh.target.port"),
                    identityFile: ex("self.action.ssh.identity_file"),
                    hostKeys: ex("self.action.ssh.target.host_keys"),
                },
                hosts: [{
                    name: "target",
                    address: ex("self.action.ssh.target.host"),
                }],
            },
            inputs: { hosts: "name:target", sudo: true, argv, ...options },
        },
    };
}
const assert = (name: string, expr: string): Fields => ({
    name,
    ...placed,
    task: {
        type: "assert",
        expr,
        message: "node.removal_incomplete",
        severity: "high",
    },
});
function child(
    name: string,
    workflow: string,
    items: string,
    intent: unknown,
    kind = ex("inputs.kind"),
    nodeId = ex("inputs.node_id"),
): Fields {
    return {
        name,
        ...placed,
        forEach: { item: "publication", in: ex(items) },
        task: {
            type: "workflow",
            workflowIdOrName: workflow,
            inputs: {
                operation_id: ex("inputs.operation_id"),
                node_id: nodeId,
                kind,
                intent,
                execution_secrets: {},
            },
        },
    };
}
function workflow(
    name: string,
    id: string,
    phases: Fields[][],
    inputs: Fields = schema,
): Fields & { jobs: Fields[] } {
    return {
        id,
        name,
        description:
            "Official resource removal and single-command SSH actions; retained user data, native end check.",
        vaults: ["orbit-control-inputs"],
        queueTimeout: 30,
        ...placed,
        inputs,
        jobs: phases.map((steps, i) => ({
            name: i === phases.length - 1 ? "remove" : "phase_" + i,
            ...(i
                ? {
                    dependsOn: [{
                        job: "phase_" + (i - 1),
                        condition: { type: "succeeded" },
                    }],
                }
                : {}),
            steps,
        })),
    };
}
const compliant = (name: string) =>
    `steps.filter(k,k.startsWith(${
        JSON.stringify(name + "-")
    })).all(k,steps[k].outputs.status == "compliant")`;
const firewallsOK = (name: string) =>
    `steps.filter(k,k.startsWith(${
        JSON.stringify(name + "-")
    })).all(k,steps[k].outputs.exitCode == 0 || (steps[k].outputs.exitCode == 1 && (steps[k].outputs.stdout + steps[k].outputs.stderr).contains("Could not delete non-existent rule")))`;
function resources(items: (field: string) => string): Fields[][] {
    return [
        [cfg("Disable the services", "systemd", items("stop_units"), {
            service: ex("self.action.input.unit"),
            ensure: "stopped",
            enabled: false,
        })],
        // systemd.apply stops active units, but not activating units. Stop only loaded units with the official exec guard.
        [cfg("Stop activating services", "exec", items("stop_units"), {
            command: ex(
                '"timeout 60 systemctl stop " + self.action.input.unit',
            ),
            onlyIf: ex(
                '"test $(systemctl show --property=LoadState --value " + self.action.input.unit + ") = loaded"',
            ),
        })],
        [
            cfg("Remove managed files", "file", items("files"), {
                path: ex("self.action.path"),
                ensure: "absent",
            }),
            cfg(
                "Remove service config",
                "directory",
                items("directories"),
                { path: ex("self.action.path"), ensure: "absent" },
            ),
            cfg(
                "Remove the containers",
                "docker_container",
                items("remove_containers"),
                {
                    name: ex("self.action.input.name"),
                    image: "unused",
                    ensure: "absent",
                },
            ),
        ],
        [ssh("Reload unit catalogue", items("reload_units"), [
            "timeout",
            "60",
            "systemctl",
            "daemon-reload",
        ])],
        [ssh(
            "Withdraw firewall rules",
            items("firewall"),
            ex('["timeout","60"] + self.action.input.argv'),
            { okExitCodes: [0, 1] },
        )],
    ];
}
export function roleRemovalWorkflow(): ReturnType<typeof workflow> {
    const items = (field: string) =>
        `inputs.intent.${field}.filter(a,!inputs.offline || inputs.intent.ssh == null || a.ssh.target.host != inputs.intent.ssh.target.host)`;
    const pubs =
        "inputs.intent.publications.filter(p,!inputs.offline || p.node_id != inputs.intent.node_id)";
    const guests = ssh(
        "Read CI guests",
        items("ci_host"),
        [
            "timeout",
            "60",
            "incus",
            "list",
            "--project",
            "orbit-ci",
            "--format=json",
        ],
        { okExitCodes: [0, 1], timeoutSec: 70 },
    );
    delete guests.writes;
    const phases = [
        [guests],
        [assert(
            "Require drained CI",
            'steps.filter(k,k.startsWith("Read CI guests-")).all(k,(steps[k].outputs.exitCode == 0 && steps[k].outputs.stdout in ["[]","[]\\n"]) || (steps[k].outputs.exitCode == 1 && steps[k].outputs.stderr.contains("Project not found")))',
        )],
        [ssh(
            "Keep public SSH access",
            `inputs.kind == "node.role_remove" ? ${items("public_ssh")} : []`,
            ex('["timeout","60"] + self.action.input.argv'),
        )],
        [child(
            "Withdraw PHP publication",
            "operation-route-php-delta",
            pubs + ".filter(p,p.after.php != null)",
            {
                node_id: ex("self.publication.node_id"),
                user: ex("self.publication.connection.nodeUser"),
                control_node_id: ex("self.publication.control_node_id"),
                host_keys: ex("self.publication.host_keys"),
                connection: ex("self.publication.connection"),
                before: ex("self.publication.before.php"),
                after: ex("self.publication.after.php"),
                direction: "forward",
            },
        )],
        [child(
            "Withdraw the Caddy sites",
            "operation-route-caddy",
            pubs + ".filter(p,p.after.caddy != null)",
            {
                caddy_publication: {
                    node_id: ex("self.publication.node_id"),
                    user: ex("self.publication.connection.nodeUser"),
                    control_node_id: ex("self.publication.control_node_id"),
                    host_keys: ex("self.publication.host_keys"),
                    connection: ex("self.publication.connection"),
                    caddy: ex("self.publication.after.caddy"),
                },
            },
        )],
        [child(
            "Withdraw the role DNS",
            "dns-publish",
            pubs + ".filter(p,p.after.dns != null)",
            {
                node_id: ex("self.publication.node_id"),
                connection: ex("self.publication.connection"),
                host_keys: ex("self.publication.host_keys"),
                dns: ex("self.publication.after.dns"),
            },
            ex("inputs.kind"),
            // The behavior names of the child follow the VPN Node it publishes to.
            ex("self.publication.node_id"),
        )],
        ...resources(items),
        // The role certificate current path may be a directory or a symlink. Neither cfgmgmt file nor directory covers both.
        [ssh(
            "Remove role certificates",
            items("certificates"),
            ex('["timeout","60","rm","-rf","--",self.action.path]'),
        )],
        [
            cfg("Check removed files", "file", items("files"), {
                path: ex("self.action.path"),
                ensure: "absent",
            }, "check"),
            cfg(
                "Check removed directories",
                "directory",
                items("directories"),
                { path: ex("self.action.path"), ensure: "absent" },
                "check",
            ),
            cfg(
                "Check removed containers",
                "docker_container",
                items("remove_containers"),
                {
                    name: ex("self.action.input.name"),
                    image: "unused",
                    ensure: "absent",
                },
                "check",
            ),
        ],
        [assert(
            "Confirm role removal",
            [
                compliant("Check removed files"),
                compliant("Check removed directories"),
                compliant("Check removed containers"),
                firewallsOK("Withdraw firewall rules"),
            ].join(" && "),
        )],
    ];
    phases.splice(phases.length - 2, 0, [{
        ...cfg("Remove role data", "exec", items("commands"), {
            command: ex("self.action.command"),
            onlyIf: ex("self.action.only_if"),
        }),
        concurrency: 1,
    }]);
    const inputs = structuredClone(schema) as Fields;
    inputs.properties.offline = { type: "boolean" };
    inputs.required.push("offline");
    return workflow(
        "node-role-remove",
        "a49d3155-9cda-4a77-97c0-ce389d1a3b4a",
        phases,
        inputs,
    );
}
export function nodeRemovalWorkflow(): ReturnType<typeof workflow> {
    const reachable =
        '"Log in to departing Node-0" in steps && steps["Log in to departing Node-0"].status == "succeeded"';
    const online = `(${reachable})`;
    const linux = `(${online} && inputs.intent.platform == "linux")`;
    const one = `${linux} ? [{"ssh": inputs.intent.ssh}] : []`;
    const items = (field: string) =>
        field === "stop_units"
            ? `${linux} ? ["orbit-agent.service","prometheus-node-exporter.service","orbit-cadvisor.service","orbit-fpm-exporter.service"].map(u,{"ssh":inputs.intent.ssh,"input":{"unit":u}}) : []`
            : field === "files" || field === "directories"
            ? `${linux} ? inputs.intent.footprints.${field} : []`
            : field === "reload_units"
            ? one
            : "[]";
    const probe = ssh(
        "Log in to departing Node",
        'inputs.intent.ssh == null || inputs.intent.machine_deleted ? [] : [{"ssh":inputs.intent.ssh}]',
        ["true"],
        { sudo: false, timeoutSec: 10 },
    );
    delete probe.writes;
    probe.allowFailure = true;
    const phases = [
        [probe],
        [assert(
            "Require offline consent",
            `${reachable} || inputs.intent.offline || inputs.intent.machine_deleted`,
        )],
        [cfg(
            "Publish Metrics membership",
            "file",
            "inputs.intent.metrics_files",
            {
                path: ex("self.action.input.path"),
                ensure: "present",
                content: ex("self.action.input.content"),
                mode: "0644",
                owner: "root",
                group: "root",
            },
        )],
        [ssh("Reload Metrics membership", "inputs.intent.metrics_reload", [
            "timeout",
            "60",
            "docker",
            "kill",
            "--signal",
            "HUP",
            "orbit-metrics-prometheus",
        ])],
        [{
            ...child(
                "Remove Node roles",
                "node-role-remove",
                "inputs.intent.roles",
                ex("self.publication"),
            ),
            task: {
                ...child("", "node-role-remove", "", {}).task,
                inputs: {
                    operation_id: ex("inputs.operation_id"),
                    node_id: ex("inputs.node_id"),
                    kind: ex("inputs.kind"),
                    intent: ex("self.publication"),
                    execution_secrets: {},
                    offline: ex(`!${online}`),
                },
            },
        }],
        [ssh(
            "Keep public SSH access",
            `${linux} ? [{"ssh":inputs.intent.ssh,"input":inputs.intent.public_ssh}] : []`,
            ex('["timeout","60"] + self.action.input.argv'),
        )],
        ...resources(items),
        [
            child(
                "Remove hub peer",
                "node-network-hub",
                "inputs.intent.hub",
                ex("self.publication"),
                "node.remove",
            ),
            ssh(
                "Remove router ingress",
                "inputs.intent.router_rules",
                ex('["timeout","60"] + self.action.input.argv'),
                { okExitCodes: [0, 1] },
            ),
            child(
                "Refresh peer DNS",
                "operation-fleet-peer-dns",
                "inputs.intent.peers",
                ex("self.publication"),
                "fleet.peer_dns",
            ),
            child(
                "Withdraw Node DNS",
                "dns-publish",
                "inputs.intent.dns",
                {
                    node_id: ex("self.publication.node_id"),
                    connection: ex("self.publication.connection"),
                    host_keys: ex("self.publication.host_keys"),
                    dns: ex("self.publication.dns"),
                },
                ex("inputs.kind"),
                ex("self.publication.node_id"),
            ),
        ],
        [
            cfg("Check removed files", "file", items("files"), {
                path: ex("self.action.path"),
                ensure: "absent",
            }, "check"),
            cfg(
                "Check removed directories",
                "directory",
                items("directories"),
                { path: ex("self.action.path"), ensure: "absent" },
                "check",
            ),
        ],
        // Revoke the active control identity last; apply reads back using its existing connection.
        [cfg(
            "Revoke management keys",
            "authorized_key",
            `${online} ? inputs.intent.managed_keys.filter(k,k != inputs.intent.managed_keys[size(inputs.intent.managed_keys)-1]).map(k,{"ssh":inputs.intent.ssh,"key":k}) : []`,
            {
                user: ex("self.action.ssh.target.user"),
                key: ex("self.action.key"),
                ensure: "absent",
            },
        )],
        [cfg(
            "Revoke control key",
            "authorized_key",
            `${online} ? [{"ssh":inputs.intent.ssh,"key":inputs.intent.managed_keys[size(inputs.intent.managed_keys)-1]}] : []`,
            {
                user: ex("self.action.ssh.target.user"),
                key: ex("self.action.key"),
                ensure: "absent",
            },
        )],
        [assert(
            "Confirm Node removal",
            [
                compliant("Check removed files"),
                compliant("Check removed directories"),
                firewallsOK("Remove router ingress"),
                'steps.filter(k,k.startsWith("Revoke management keys-") || k.startsWith("Revoke control key-")).all(k,steps[k].outputs.status in ["compliant","applied"] && !steps[k].outputs.current.keyPresent)',
            ].join(" && "),
        )],
    ];
    const inputs = structuredClone(schema) as Fields;
    // Task VM teardown shares this boundary and retains its parent Operation kind.
    inputs.properties.kind.enum = [
        "node.remove",
        "tasks.provision",
        "tasks.cancel",
        "tasks.maintenance_finish",
    ];
    return workflow(
        "operation-node-remove",
        "450e57df-e174-4ad6-bba5-306c2581d919",
        phases,
        inputs,
    );
}
export function generatedRemovalWorkflows() {
    return [
        roleRemovalWorkflow(),
        nodeRemovalWorkflow(),
    ].map((value) => ({
        path: new URL(
            "../../../../workflows/workflow-" + value.name + ".yaml",
            import.meta.url,
        ),
        text:
            "# deno-fmt-ignore-file\n# Generated from node-removal-command/workflows.ts\n" +
            stringify(value, { flowLevel: 5, lineWidth: -1 }).replace(
                /    kind:\n      type: string\n      enum:\n((?:        - [\w.]+\n)+)/,
                (_match, kinds: string) =>
                    "    kind: {type: string, enum: [" +
                    kinds.trim().split("\n").map((kind) => kind.trim().slice(2))
                        .join(", ") +
                    "]}\n",
            ),
    }));
}
if (import.meta.main) {
    for (const file of generatedRemovalWorkflows()) {
        await Deno.writeTextFile(file.path, file.text);
    }
}
