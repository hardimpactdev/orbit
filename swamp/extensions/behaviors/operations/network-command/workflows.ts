/** The two direct roots share native action assembly, never an execution engine. */
import { stringify } from "jsr:@std/yaml@1.0.5";
const expr = (s: string) => "${{ " + s + " }}";
const done = {
    type: "or",
    conditions: [{ type: "succeeded" }, { type: "skipped" }],
};
function steps() {
    const list: Record<string, unknown>[] = [];
    const add = (
        name: string,
        task: unknown,
        guard?: string,
        writes = false,
    ) => {
        list.push({
            name,
            target: "orbit-node-209",
            ...(list.length
                ? {
                    dependsOn: list.map((prior) => ({
                        step: prior.name,
                        condition: done,
                    })),
                }
                : {}),
            ...(guard ? { guard } : {}),
            ...(writes ? { writes: true } : {}),
            task,
        });
    };
    const typed = (
        name: string,
        method: string,
        inputs: unknown,
        guard?: string,
    ) => add(name, {
        type: "behavior",
        behaviorType: "@nckrtl/network-command",
        behaviorName: expr('"' + name + '-"+run.id'),
        methodName: method,
        inputs,
    }, guard);
    const ssh = (
        name: string,
        connection: string,
        method: string,
        inputs: unknown,
        guard?: string,
        writes = true,
    ) => add(
        name,
        {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expr(
                (connection === "inputs.intent.hub_ssh"
                    ? '"network-hub-"'
                    : '"network-peer-"') + "+run.id",
            ),
            methodName: method,
            globalArgs: {
                name: expr(
                    (connection === "inputs.intent.hub_ssh"
                        ? '"network-hub-"'
                        : '"network-peer-"') + "+run.id",
                ),
                transport: {
                    kind: "ssh",
                    user: expr(connection + ".target.user"),
                    port: expr(connection + ".target.port"),
                    identityFile: expr(connection + ".identity_file"),
                    hostKeys: expr(connection + ".target.host_keys"),
                },
                hosts: [{
                    name: "target",
                    address: expr(connection + ".target.host"),
                }],
            },
            inputs: { hosts: "name:target", sudo: true, ...inputs as object },
        },
        guard,
        writes,
    );
    const script = (
        name: string,
        action: string,
        input: unknown,
        connection = "inputs.intent.ssh",
        guard?: string,
        writes = true,
    ) => {
        typed(name + "_asset", "read", { action, input }, guard);
        ssh(
            name,
            connection,
            "script",
            {
                interpreter: "python3",
                script: expr("steps." + name + "_asset.outputs.script_body"),
            },
            guard,
            writes,
        );
    };
    const execStep = (name: string, argv: unknown, guard?: string) =>
        ssh(name, "inputs.intent.ssh", "exec", { argv }, guard);
    return { list, typed, script, execStep };
}
const policy = (network: boolean) =>
    network
        ? expr(
            '{"server":inputs.intent.peer.dns_server,"dns_server":inputs.intent.peer.dns_server,"domains":inputs.intent.peer.domains,"public_key":steps.key.outputs.public_key}',
        )
        : expr(
            '{"server":inputs.intent.server,"dns_server":inputs.intent.dns_server,"domains":inputs.intent.domains,"public_key":inputs.intent.public_key}',
        );
function dnsActions(s: ReturnType<typeof steps>, network: boolean) {
    const p = policy(network),
        server = network
            ? "inputs.intent.peer.dns_server"
            : "inputs.intent.server";
    const domains = network
        ? "inputs.intent.peer.domains"
        : "inputs.intent.domains";
    s.script(
        "inspect",
        "dns_inspect",
        expr('{"server":' + server + "}"),
        undefined,
        undefined,
        false,
    );
    s.typed("decision", "decideDns", {
        stdout: expr("steps.inspect.outputs.stdout"),
        policy: p,
        require_uplink: network
            ? expr("inputs.intent.peer.operator_dns == null")
            : true,
    });
    const guard = expr("!steps.decision.outputs.changed");
    s.script("persist", "dns_persist", p, undefined, guard);
    s.execStep(
        "set_dns",
        expr('["/usr/bin/resolvectl","dns","orbit",' + server + "]"),
        guard,
    );
    s.execStep(
        "set_domains",
        expr(
            '["/usr/bin/resolvectl","domain","orbit"]+' + domains +
                '.map(d,"~"+d)',
        ),
        guard,
    );
    s.execStep("set_default_route", [
        "/usr/bin/resolvectl",
        "default-route",
        "orbit",
        "no",
    ], guard);
    const old = expr(
        '!steps.decision.outputs.changed || steps.decision.outputs.old_link == "orbit"',
    );
    s.execStep(
        "clear_old_dns",
        expr(
            '["/usr/bin/resolvectl","dns",steps.decision.outputs.old_link,""]',
        ),
        old,
    );
    s.execStep(
        "clear_old_domains",
        expr(
            '["/usr/bin/resolvectl","domain",steps.decision.outputs.old_link,""]',
        ),
        old,
    );
    s.execStep(
        "restore_default_route",
        expr(
            '["/usr/bin/resolvectl","default-route",steps.decision.outputs.old_link,"yes"]',
        ),
        old,
    );
    s.execStep(
        "restore_uplink",
        expr(
            '["/usr/bin/networkctl","reconfigure",steps.decision.outputs.old_link]',
        ),
        old,
    );
    s.script(
        "verify",
        "dns_inspect",
        expr('{"server":' + server + "}"),
        undefined,
        undefined,
        false,
    );
}
export function workflow(network: boolean) {
    const s = steps();
    if (network) {
        s.script(
            "prepare_key",
            "prepare_key",
            expr('{"existing_key":inputs.intent.existing_key}'),
        );
        s.typed("key", "key", {
            stdout: expr("steps.prepare_key.outputs.stdout"),
            existing_key: expr("inputs.intent.existing_key"),
            other_public_keys: expr(
                "inputs.intent.other_public_keys+[inputs.intent.peer.server_public_key]",
            ),
        });
        s.script(
            "publish_hub",
            "hub_peer",
            expr(
                '{"existing_key":inputs.intent.existing_key,"public_key":steps.key.outputs.public_key,"address":inputs.intent.peer.address}',
            ),
            "inputs.intent.hub_ssh",
        );
        s.script(
            "configure_peer",
            "peer_config",
            expr(
                '{"public_key":steps.key.outputs.public_key,"peer":inputs.intent.peer}',
            ),
        );
        s.execStep("enable_peer", [
            "/usr/bin/systemctl",
            "enable",
            "wg-quick@orbit",
        ]);
        s.script("apply_peer", "apply_peer", {});
        s.execStep("trust_firewall", expr("inputs.intent.trust_argv"));
    }
    dnsActions(s, network);
    s.typed("result", network ? "networkResult" : "dnsResult", {
        stdout: expr("steps.verify.outputs.stdout"),
        policy: policy(network),
        ...(network
            ? {
                retained: expr("inputs.intent.peer.retain"),
                require_uplink: expr("inputs.intent.peer.operator_dns == null"),
            }
            : {}),
    });
    return {
        id: network
            ? "3c0eb942-9c7f-438d-8d4c-7c739642c155"
            : "f05d2ee0-5aa7-4271-9924-fda8b65a786d",
        name: network
            ? "operation-node-network-converge"
            : "operation-fleet-peer-dns",
        description:
            "Pinned official SSH from209; full public input and typed terminal output.",
        vaults: [],
        target: "orbit-node-209",
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: {
                    type: "string",
                    enum: [
                        network ? "node.network_converge" : "fleet.peer_dns",
                    ],
                },
                intent: { type: "object" },
                execution_secrets: {
                    type: "object",
                    additionalProperties: false,
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
        },
        jobs: [{ name: network ? "network" : "dns", steps: s.list }],
    };
}
if (import.meta.main) {
    for (const network of [true, false]) {
        const w = workflow(network);
        await Deno.writeTextFile(
            new URL(
                "../../../../workflows/workflow-" + w.name + ".yaml",
                import.meta.url,
            ),
            "# deno-fmt-ignore-file\n# Generated from network-command/workflows.ts\n" +
                stringify(w, { flowLevel: 5, lineWidth: -1 }),
        );
    }
}
