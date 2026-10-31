import { hubPeerSteps } from "../node-hub-command/steps.ts";
import { peerActionSteps } from "../node-peer-command/steps.ts";

type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const after = (
    step: string,
    type = "succeeded",
) => [{ step, condition: { type } }];
const output = (step: string) => 'steps["' + step + '-0"].outputs';
const hasOutput = (step: string) =>
    '("' + step + '-0" in steps && "outputs" in steps["' + step + '-0"])';
const attempted = (step: string) =>
    '("' + step + '-0" in steps && steps["' + step +
    '-0"].status != "skipped")';

function execution(
    intent: string,
    observation: string,
    restoring = false,
) {
    const model = expr('"node-enrollment-" + string(' + intent + ".node_id)");
    return {
        placement: {
            target: expr(
                '"orbit-node-" + string(' + intent + ".executor_node_id)",
            ),
            labels: { node: expr("string(" + intent + ".executor_node_id)") },
        },
        globalArgs: {
            name: model,
            transport: {
                kind: "ssh",
                user: expr(
                    intent +
                        (restoring
                            ? ".bootstrap.target.user"
                            : ".bootstrap.managed_user"),
                ),
                port: expr(intent + ".bootstrap.target.port"),
                identityContent: expr(
                    "vault.get(inputs.execution_secrets.bootstrap_identity.vault, " +
                        "inputs.execution_secrets.bootstrap_identity.key)",
                ),
                hostKeys: expr(observation + ".host_keys"),
            },
            hosts: [{
                name: "peer",
                address: expr(intent + ".bootstrap.target.host"),
            }],
        },
    };
}

function conditional(
    steps: Fields[],
    selection: string,
    predicate?: string,
): Fields[] {
    const names = steps.map((step) => String(step.name));
    const bind = (value: unknown): unknown => {
        if (typeof value === "string") {
            if (!value.startsWith("${{ ")) return value;
            let expression = value;
            for (const name of names) {
                const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
                expression = expression.replace(
                    new RegExp(`steps\\.${escaped}\\b`, "g"),
                    `steps["${name}-0"]`,
                )
                    .replaceAll(`steps["${name}"]`, `steps["${name}-0"]`)
                    .replaceAll(`"${name}" in steps`, `"${name}-0" in steps`);
            }
            return expression;
        }
        if (Array.isArray(value)) return value.map(bind);
        if (value !== null && typeof value === "object") {
            return Object.fromEntries(
                Object.entries(value).map(([key, item]) => [key, bind(item)]),
            );
        }
        return value;
    };
    for (const step of steps) {
        step.task = bind(step.task);
        if (predicate) step.guard = expr("!(" + predicate + ")");
        if (step.guard) step.guard = bind(step.guard);
        step.forEach = {
            item: "enrollment",
            in: expr(selection + " ? [{}] : []"),
        };
    }
    return steps;
}

/** The enrollment parent retains the snapshot until its entire forward chain settles. */
export function enrollmentNetworkForwardSteps(
    name: string,
    intent: string,
    observation: string,
    prerequisite: string,
): Fields[] {
    const selected = intent + '.mode == "linux"';
    const context = execution(intent, observation);
    const snapshot = name + "_snapshot",
        key = name + "_key",
        hub = name + "_hub";
    const file = name + "_file", start = name + "_start", dns = name + "_dns";
    const prepared = '{"node_id": ' + intent + '.node_id, "public_key": ' +
        output(key) + ".public_key}";
    const peer = peerActionSteps(
        snapshot,
        "snapshot",
        "{}",
        context,
        prerequisite,
    );
    peer.push(
        ...peerActionSteps(
            key,
            "prepare_key",
            '{"existing_key": ' + intent +
                ".network.existing_key}",
            context,
            snapshot,
        ),
    );
    const hubSteps = hubPeerSteps(
        hub,
        intent + ".hub",
        "publish",
        undefined,
        prepared,
    );
    hubSteps[0].dependsOn = after(key);
    peer.push(...hubSteps);
    peer.push(
        ...peerActionSteps(
            file,
            "publish",
            '{"peer": ' + intent + ".network.peer}",
            context,
            hub,
        ),
    );
    for (
        const [step, method, previous] of [
            [name + "_enable", "enable", file],
            [start, "restart", name + "_enable"],
        ]
    ) {
        peer.push({
            name: step,
            ...context.placement,
            writes: true,
            dependsOn: after(previous),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: context.globalArgs.name,
                methodName: "exec",
                globalArgs: context.globalArgs,
                inputs: {
                    hosts: "name:peer",
                    sudo: true,
                    argv: [
                        "/usr/bin/systemctl",
                        method,
                        "wg-quick@orbit.service",
                    ],
                },
            },
        });
    }
    peer.push(...peerActionSteps(
        dns,
        "record_dns",
        '{"peer": ' + intent +
            '.network.peer, "snapshot": ' + output(snapshot) + "}",
        context,
        start,
    ));
    peer.push({
        name: name + "_verify",
        ...context.placement,
        dependsOn: after(dns),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: context.globalArgs.name,
            methodName: "exec",
            globalArgs: {
                ...context.globalArgs,
                hosts: [{
                    name: "peer",
                    address: expr(
                        intent + '.network.peer.address.split("/")[0]',
                    ),
                }],
            },
            inputs: { hosts: "name:peer", argv: ["/usr/bin/true"] },
        },
    });
    return conditional(peer, selected);
}

/** Compensation follows the parent boundary, including failures after network verification. */
export function enrollmentNetworkRestoreSteps(
    name: string,
    intent: string,
    observation: string,
    settled: string,
    succeeded: string,
): Fields[] {
    const snapshot = name + "_snapshot",
        key = name + "_key",
        hub = name + "_hub";
    const restore = name + "_restore_peer", restoreHub = name + "_restore_hub";
    const selected = intent + '.mode == "linux"';
    const enabled = selected + " && " + hasOutput(snapshot);
    const restoring = enabled + " && !(" + succeeded + ")";
    const context = execution(intent, observation, true);
    const peer = conditional(
        peerActionSteps(
            restore,
            "restore",
            '{"snapshot": ' + output(snapshot) +
                "}",
            context,
        ),
        selected,
        restoring,
    );
    peer[0].dependsOn = after(settled, "always");
    const hubSteps = hubPeerSteps(
        restoreHub,
        intent + ".hub",
        "restore-publish",
        undefined,
        '{"node_id": ' + intent + '.node_id, "public_key": ' + output(key) +
            ".public_key}",
    );
    hubSteps[0].dependsOn = after(restore, "always");
    peer.push(
        ...conditional(
            hubSteps,
            selected,
            restoring + " && " + hasOutput(key) + " && " +
                attempted(hub + "_command"),
        ),
    );
    const cleaned = enabled + " && ((" + succeeded + ') || ("' + restore +
        '-0" in steps && steps["' + restore +
        '-0"].status == "succeeded" && (!' +
        attempted(hub + "_command") + ' || ("' + restoreHub +
        '-0" in steps && steps["' +
        restoreHub + '-0"].status == "succeeded"))))';
    const cleanup = conditional(
        peerActionSteps(
            name + "_cleanup",
            "cleanup",
            '{"snapshot": ' +
                output(snapshot) + "}",
            context,
        ),
        selected,
        cleaned,
    );
    cleanup[0].dependsOn = after(restoreHub, "always");
    for (const step of cleanup) step.allowFailure = true;
    return [...peer, ...cleanup];
}
