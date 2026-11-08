import { dotless } from "../_lib/behavior_name.ts";
/** The two direct roots share native action assembly, never an execution engine. */
import { stringify } from "jsr:@std/yaml@1.0.5";
import { INSPECT } from "./assets.ts";
const KEY = "/etc/wireguard/orbit.key";
const CONF = "/etc/wireguard/orbit.conf";
const PEERS = "/etc/wireguard/orbit-peers.conf";
/** syncconf drops the interface key when its input has none, so the Node composes it from its own key file at apply time. */
const SYNCCONF =
    'wg syncconf orbit <(printf "[Interface]\\nPrivateKey = %s\\n\\n" "$(cat "$1")"; cat "$2")';
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
            labels: { role: "swamp" },
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
        // Named with its type: fixed names such as `result` recur across behavior types.
        behaviorName: expr(
            JSON.stringify(name.toLowerCase().replaceAll(" ", "-") + "-net-") +
                " + string(inputs.node_id)",
        ),
        methodName: method,
        inputs,
    }, guard);
    // The hub keeps the name the hub publication child gives it; the peer is named after its SSH address.
    const target = (connection: string) =>
        expr(
            dotless(
                connection === "inputs.intent.hub.ssh"
                    ? '"wireguard-hub-" + string(inputs.intent.hub_id)'
                    : '"network-peer-" + string(' + connection +
                        ".target.host)",
            ),
        );
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
            behaviorName: target(connection),
            methodName: method,
            globalArgs: {
                name: target(connection),
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
    const cfg = (
        name: string,
        type: string,
        family: string,
        connection: string,
        globalArgs: Record<string, unknown>,
        guard?: string,
        method = "apply",
    ) => add(
        name,
        {
            type: "behavior",
            behaviorType: "@swamp/cfgmgmt/" + type,
            behaviorName: family,
            methodName: method,
            globalArgs: {
                nodeHost: expr(connection + ".target.host"),
                nodeUser: expr(connection + ".target.user"),
                nodePort: expr(connection + ".target.port"),
                nodeIdentityFile: expr(connection + ".identity_file"),
                become: true,
                ...globalArgs,
            },
            inputs: {},
        },
        guard,
        method === "apply",
    );
    const peerName = (family: string) =>
        expr(
            dotless(
                JSON.stringify(family + "-") +
                    " + string(inputs.intent.ssh.target.host)",
            ),
        );
    const read = (name: string, argv: unknown) =>
        ssh(name, "inputs.intent.ssh", "exec", { argv }, undefined, false);
    const execStep = (name: string, argv: unknown, guard?: string) =>
        ssh(name, "inputs.intent.ssh", "exec", { argv }, guard);
    return { list, typed, ssh, cfg, peerName, read, execStep };
}
const KEY_STEP = 'steps["Check the key"]';
const DECISION = 'steps["Decide the DNS change"]';
const POLICY = expr(
    '{"server":inputs.intent.peer.dns_server,"dns_server":inputs.intent.peer.dns_server,"domains":inputs.intent.peer.domains,"public_key":' +
        KEY_STEP + ".outputs.public_key}",
);
/** The hook brings the policy back when the tunnel starts; the marker names the link, server and domains. Both are idempotent. */
function persistDns(
    s: ReturnType<typeof steps>,
    server: string,
    domains: string,
    guard?: string,
) {
    s.cfg(
        "Keep the DNS hook",
        "line",
        s.peerName("network-dns-hook"),
        "inputs.intent.ssh",
        {
            path: CONF,
            regexp: "^PostUp\\s*=\\s*resolvectl dns %i ",
            line: expr("inputs.intent.dns_hook"),
            insertAfter: "^\\[Interface\\]",
            createFile: false,
        },
        guard,
    );
    s.cfg(
        "Write the DNS marker",
        "file",
        s.peerName("network-dns-link"),
        "inputs.intent.ssh",
        {
            path: "/etc/wireguard/orbit.dns-link",
            ensure: "present",
            content: expr(
                '"orbit\\n" + ' + server + ' + "\\n" + ' + domains +
                    '.join("\\n") + "\\n"',
            ),
            owner: "root",
            group: "root",
            mode: "0600",
        },
        guard,
    );
}
/** One `orbit` link setting, trimmed: `Link 7 (orbit): no` reads `no`. */
const live = (setting: string) =>
    '"$(resolvectl ' + setting + ' orbit | cut -d: -f2- | xargs)"';
/** Private names resolve on `orbit` and public names elsewhere; prints nothing, fails with one stderr line. */
/** Peer network child: read, decide, then persist and set only when the policy changed. */
function dnsActions(s: ReturnType<typeof steps>) {
    const server = "inputs.intent.peer.dns_server",
        domains = "inputs.intent.peer.domains";
    s.read("Read the DNS policy", ["sh", "-c", INSPECT]);
    s.typed("Decide the DNS change", "decideDns", {
        stdout: expr('steps["Read the DNS policy"].outputs.stdout'),
        policy: POLICY,
        require_uplink: expr("inputs.intent.peer.operator_dns == null"),
    });
    const guard = expr("!" + DECISION + ".outputs.changed");
    persistDns(s, server, domains, guard);
    // One command sets the whole policy on the tunnel link: server, routing domains, no default route.
    s.execStep(
        "Set the DNS policy",
        expr(
            '["bash","-c","s=$1; shift; resolvectl dns orbit \\"$s\\" && resolvectl domain orbit \\"$@\\" && resolvectl default-route orbit no","bash",' +
                server + "]+" + domains + '.map(d,"~"+d)',
        ),
        guard,
    );
    // An old Orbit selection on the uplink is cleared, and networkd gives the uplink its own DNS back.
    s.execStep(
        "Clear the old link",
        expr(
            '["bash","-c","resolvectl dns \\"$1\\" \\"\\" && resolvectl domain \\"$1\\" \\"\\" && resolvectl default-route \\"$1\\" yes && networkctl reconfigure \\"$1\\"","bash",' +
                DECISION + ".outputs.old_link]",
        ),
        expr(
            "!" + DECISION + ".outputs.changed || " + DECISION +
                '.outputs.old_link == "orbit"',
        ),
    );
    s.read("Read the DNS policy again", ["sh", "-c", INSPECT]);
}
/** The member Ansible phase is shared with Cluster publication, convergence and repair. */
function memberWorkflow() {
    const env = {
        ORBIT_RUN: expr('"/tmp/orbit-peer-dns-" + run.id'),
    };
    const shell = (name: string, run: string, extra: Record<string, string> = {}, writes = false) => ({
        name, labels: { role: "swamp" }, ...(writes ? { writes: true } : {}),
        task: { type: "behavior", behaviorType: "command/shell",
            behaviorName: expr('"peer-dns-' + name.toLowerCase().replaceAll(" ", "-") + '-" + string(inputs.node_id)'),
            methodName: "execute", inputs: { run, env: { ...env, ...extra } } },
    });
    const after = (step: string, type = "succeeded") => [{ step, condition: { type } }];
    const phase = "Apply member DNS policy";
    return {
        id: "f05d2ee0-5aa7-4271-9924-fda8b65a786d",
        name: "operation-fleet-peer-dns",
        description: "One shared Ansible member phase selects the Cluster delegate and remaining VPN scope.",
        vaults: [], queueTimeout: 60,
        inputs: { type: "object", properties: {
            operation_id: { type: "integer", minimum: 1 }, node_id: { type: "integer", minimum: 1 },
            kind: { type: "string" }, intent: { type: "object" },
            execution_secrets: { type: "object", additionalProperties: false },
        }, required: ["operation_id", "node_id", "kind", "intent", "execution_secrets"], additionalProperties: false },
        jobs: [{ name: "dns", labels: { role: "swamp" }, steps: [
            { name: "Write the intent", labels: { role: "swamp" }, writes: true,
                task: { type: "behavior", behaviorType: "@nckrtl/ansible-input", behaviorName: "ansible-input", methodName: "write",
                    inputs: { intent: expr("inputs.intent"), path: expr('"/tmp/orbit-peer-dns-" + run.id + "/intent.json"') } } },
            { ...shell(phase, 'timeout 300 /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags member "$ORBIT_ANSIBLE/peer-dns.yml"', {
                ORBIT_ANSIBLE: expr('steps["Write the intent"].outputs.assets'),
                ANSIBLE_CONFIG: expr('steps["Write the intent"].outputs.assets + "/ansible.cfg"'),
                ANSIBLE_SSH_ARGS: expr('"-o IdentitiesOnly=yes -o GlobalKnownHostsFile=/dev/null -o UserKnownHostsFile=/tmp/orbit-peer-dns-" + run.id + "/known_hosts"'),
            }, true), dependsOn: after("Write the intent") },
            { ...shell("Check resolution", 'timeout 10 cat "$ORBIT_RUN/result.json"'), dependsOn: after(phase, "always") },
            { ...shell("Remove the run folder", 'timeout 30 rm -rf -- "$ORBIT_RUN"', {}, true),
                dependsOn: after("Check resolution", "always") },
        ] }],
    };
}

export function workflow(network: boolean) {
    if (!network) return memberWorkflow();
    const s = steps();
    if (network) {
        const peer = "inputs.intent.ssh", hub = "inputs.intent.hub.ssh";
        const file = (path: string, content: string) => ({
            path,
            ensure: "present",
            content: expr(content),
            owner: "root",
            group: "root",
            mode: "0600",
        });
        const section = 'inputs.intent.hub.section.split("@KEY@").join(' +
            KEY_STEP + ".outputs.public_key)";
        // The Node makes its key once and prints only the public key; the private key stays in its 0600 key file.
        s.execStep("Prepare the key", [
            "sh",
            "-c",
            "umask 077 && { test -s " + KEY + " || wg genkey > " + KEY +
            "; } && wg pubkey < " + KEY,
        ]);
        s.typed("Check the key", "key", {
            stdout: expr('steps["Prepare the key"].outputs.stdout'),
            existing_key: expr("inputs.intent.existing_key"),
            other_public_keys: expr(
                "inputs.intent.other_public_keys+[inputs.intent.peer.server_public_key]",
            ),
        });
        // Only this Node's peer file changes on the hub, so runs that overlap never drop each other's peers.
        s.cfg(
            "Write the hub peer file",
            "file",
            expr(
                dotless(
                    '"wireguard-hub-peer-" + string(inputs.intent.hub_id) + "-" + string(inputs.node_id)',
                ),
            ),
            hub,
            {
                ...file("", section),
                path: expr("inputs.intent.hub.file"),
            },
        );
        s.ssh("Load the hub peer", hub, "exec", {
            argv: expr('["wg", "addconf", "orbit", inputs.intent.hub.file]'),
        });
        s.ssh(
            "Drop the old key",
            hub,
            "exec",
            {
                argv: expr(
                    '["wg", "set", "orbit", "peer", inputs.intent.existing_key, "remove"]',
                ),
            },
            expr(
                "inputs.intent.existing_key == null || inputs.intent.existing_key == " +
                    KEY_STEP + ".outputs.public_key",
            ),
        );
        s.cfg(
            "Write the peer config",
            "file",
            s.peerName("network-config"),
            peer,
            file(CONF, "inputs.intent.config"),
        );
        s.cfg(
            "Write the peer list",
            "file",
            s.peerName("network-peers"),
            peer,
            file(PEERS, "inputs.intent.peers"),
        );
        s.cfg(
            "Start the tunnel",
            "systemd",
            s.peerName("network-service"),
            peer,
            {
                service: "wg-quick@orbit",
                ensure: "running",
                enabled: true,
            },
        );
        s.execStep("Load the peer", [
            "timeout",
            "30",
            "bash",
            "-c",
            SYNCCONF,
            "bash",
            KEY,
            PEERS,
        ]);
        s.execStep("Trust WireGuard peers", expr("inputs.intent.trust_argv"));
    }
    if (network) {
        dnsActions(s);
        s.typed("Check the network", "networkResult", {
            stdout: expr('steps["Read the DNS policy again"].outputs.stdout'),
            policy: POLICY,
            retained: expr("inputs.intent.peer.retain"),
            require_uplink: expr("inputs.intent.peer.operator_dns == null"),
        });
    }
    if (network) {
        s.list.push({
            name: "Apply member DNS policy", labels: { role: "swamp" }, writes: true,
            dependsOn: [{ step: "Check the network", condition: done }],
            guard: expr("inputs.intent.peer.operator_dns != null"),
            task: { type: "workflow", workflowIdOrName: "operation-fleet-peer-dns", inputs: {
                operation_id: expr("inputs.operation_id"), node_id: expr("inputs.node_id"), kind: "fleet.peer_dns",
                intent: { ssh: expr("inputs.intent.ssh"), selection: expr("inputs.intent.peer.selection") }, execution_secrets: {},
            } },
        });
    }
    return {
        id: network
            ? "3c0eb942-9c7f-438d-8d4c-7c739642c155"
            : "f05d2ee0-5aa7-4271-9924-fda8b65a786d",
        name: network ? "node-network-peer" : "operation-fleet-peer-dns",
        description:
            "Pinned official SSH from209; full public input and typed terminal output.",
        vaults: [],
        queueTimeout: network ? 30 : 60,
        labels: { role: "swamp" },
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: {
                    type: "string",
                    ...(network ? {} : { const: "fleet.peer_dns" }),
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
                // check-flows finds the kind's workflow by its one-line `kind` declaration.
                stringify(w, { flowLevel: 5, lineWidth: -1 }).replace(
                    /^( +)kind:\n +type: string\n +const: (\S+)$/m,
                    "$1kind: {type: string, const: $2}",
                ),
        );
    }
}
