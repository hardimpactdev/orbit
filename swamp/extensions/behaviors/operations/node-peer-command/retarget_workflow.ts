import { hubPeerSteps } from "../node-hub-command/steps.ts";
import { type PeerAction, peerActionSteps } from "./steps.ts";
type Step = Record<string, unknown>;
const e = (s: string) => "${{ " + s + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
const globals = {
    name: e('"node-retarget-" + string(inputs.intent.node_id)'),
    transport: {
        kind: "ssh",
        user: e("inputs.intent.target.user"),
        identityFile: e("inputs.intent.identity_file"),
        hostKeys: e("inputs.intent.target.host_keys"),
    },
    hosts: [{
        name: "peer",
        address: e("inputs.intent.target.host"),
        transport: { port: e("inputs.intent.target.port") },
    }],
};
const after = (
    step: string,
    type = "succeeded",
) => [{ step, condition: { type } }];
const roles = "inputs.intent.has_roles";
const succeeded = (step: string) =>
    `("${step}" in steps && steps.${step}.status == "succeeded")`;
const output = (step: string) =>
    `("${step}" in steps && "outputs" in steps.${step})`;
function ssh(
    name: string,
    method: string,
    inputs: unknown,
    dependency?: string,
    guard?: string,
): Step {
    return {
        name,
        writes: true,
        ...placement,
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        ...(guard ? { guard: e(guard) } : {}),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globals.name,
            methodName: method,
            globalArgs: globals,
            inputs,
        },
    };
}
function action(
    name: string,
    method: PeerAction,
    input: string,
    dependency: string,
    guard: string,
): Step[] {
    return peerActionSteps(
        name,
        method,
        input,
        { placement, globalArgs: globals },
        dependency,
        guard,
    );
}
export function retargetWorkflow() {
    const forwardHub = hubPeerSteps(
        "hub_publish",
        "inputs.intent.hub",
        "publish",
        e(roles),
        '{"node_id": inputs.intent.node_id, "public_key": steps.key.outputs.public_key}',
    );
    forwardHub[0].dependsOn = after("key");
    const steps: Step[] = [
        ssh("verify_endpoint", "exec", {
            hosts: "name:peer",
            argv: ["/usr/bin/true"],
        }),
        ...action("snapshot", "snapshot", "{}", "verify_endpoint", roles),
        ...action(
            "key",
            "prepare_key",
            '{"existing_key": inputs.intent.network.existing_key}',
            "snapshot",
            roles,
        ),
        ...forwardHub,
        ...action(
            "peer_file",
            "publish",
            '{"peer": inputs.intent.network.peer}',
            "hub_publish",
            roles,
        ),
        ssh(
            "enable_peer",
            "exec",
            {
                hosts: "name:peer",
                sudo: true,
                argv: [
                    "/usr/bin/systemctl",
                    "enable",
                    "wg-quick@orbit.service",
                ],
            },
            "peer_file",
            roles,
        ),
        ssh(
            "start_peer",
            "exec",
            {
                hosts: "name:peer",
                sudo: true,
                argv: [
                    "/usr/bin/systemctl",
                    "restart",
                    "wg-quick@orbit.service",
                ],
            },
            "enable_peer",
            roles,
        ),
        ...action(
            "record_dns",
            "record_dns",
            '{"peer": inputs.intent.network.peer, "snapshot": steps.snapshot.outputs}',
            "start_peer",
            roles,
        ),
        {
            ...ssh(
                "verify_peer",
                "exec",
                { hosts: "name:peer", argv: ["/usr/bin/true"] },
                "record_dns",
                roles,
            ),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: globals.name,
                methodName: "exec",
                globalArgs: {
                    ...globals,
                    hosts: [{
                        name: "peer",
                        address: e(
                            'inputs.intent.network.peer.address.split("/")[0]',
                        ),
                    }],
                },
                inputs: { hosts: "name:peer", argv: ["/usr/bin/true"] },
            },
        },
    ];
    const restoreGuard = `${roles} || !${output("snapshot")} || ${
        succeeded("verify_peer")
    }`;
    const restoration = action(
        "restore_peer",
        "restore",
        '{"snapshot": steps.snapshot.outputs}',
        "snapshot",
        restoreGuard,
    );
    restoration[0].dependsOn = [{
        step: "snapshot",
        condition: { type: "succeeded" },
    }, { step: "verify_peer", condition: { type: "always" } }];
    steps.push(...restoration);
    const hubRestore = hubPeerSteps(
        "restore_hub",
        "inputs.intent.hub",
        "restore-publish",
        e(`${restoreGuard} || !${
            output("key")
        } || !("hub_publish_command" in steps && steps.hub_publish_command.status != "skipped")`),
        '{"node_id": inputs.intent.node_id, "public_key": steps.key.outputs.public_key}',
    );
    hubRestore[0].dependsOn = after("restore_peer", "always");
    steps.push(...hubRestore);
    const cleanup = action(
        "cleanup",
        "cleanup",
        '{"snapshot": steps.snapshot.outputs}',
        "snapshot",
        `${roles} || !${output("snapshot")} || (!${
            succeeded("verify_peer")
        } && !${succeeded("restore_hub")})`,
    );
    cleanup[0].dependsOn = [{
        step: "snapshot",
        condition: { type: "succeeded" },
    }, { step: "restore_hub", condition: { type: "always" } }];
    for (const step of cleanup) step.allowFailure = true;
    steps.push(...cleanup, {
        name: "terminal",
        ...placement,
        dependsOn: [
            ...after("cleanup", "always"),
            ...after("verify_endpoint", "always"),
        ],
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-peer-command",
            behaviorName: e(
                '"retarget-terminal-" + string(inputs.intent.node_id)',
            ),
            methodName: "retarget_result",
            inputs: {
                success: e(
                    `${roles} ? ${succeeded("verify_endpoint")} : ${
                        succeeded("verify_peer")
                    }`,
                ),
                restored: e(
                    `!${roles} && ${succeeded("restore_peer")} && (${
                        succeeded("restore_hub")
                    } || !${output("key")})`,
                ),
                public_key: e(
                    `${output("key")} ? steps.key.outputs.public_key : null`,
                ),
            },
        },
    });
    const expanded = steps.filter((step) =>
        step.name !== "verify_endpoint" && step.name !== "terminal"
    ).map((step) => String(step.name));
    const bindOutputs = (value: unknown, candidate = false): unknown => {
        if (typeof value === "string") {
            if (!value.startsWith("${{ ")) return value;
            let expression = candidate
                ? value.replaceAll("inputs.intent", "self.candidate")
                : value;
            for (const name of expanded) {
                expression = expression.replace(
                    new RegExp(`steps\\.${name}\\b`, "g"),
                    `steps["${name}-0"]`,
                )
                    .replaceAll(`steps["${name}"]`, `steps["${name}-0"]`)
                    .replaceAll(`"${name}" in steps`, `"${name}-0" in steps`);
            }
            return expression;
        }
        if (Array.isArray(value)) {
            return value.map((item) => bindOutputs(item, candidate));
        }
        if (value !== null && typeof value === "object") {
            return Object.fromEntries(
                Object.entries(value).map((
                    [key, item],
                ) => [key, bindOutputs(item, candidate)]),
            );
        }
        return value;
    };
    for (const step of steps) {
        const conditional = step.name !== "verify_endpoint" &&
            step.name !== "terminal";
        step.task = bindOutputs(step.task, conditional);
        if (step.guard) step.guard = bindOutputs(step.guard);
        if (conditional) {
            step.forEach = {
                item: "candidate",
                in: e(`${roles} ? [] : [inputs.intent]`),
            };
        }
    }
    return {
        id: "acc7e20c-3c8f-4892-b92e-ac8a979ccdea",
        name: "operation-node-retarget",
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer" },
                node_id: { type: "integer" },
                kind: { type: "string", enum: ["node.retarget"] },
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
        jobs: [{ name: "retarget", steps }],
    };
}
if (import.meta.main) console.log(JSON.stringify(retargetWorkflow(), null, 2));
