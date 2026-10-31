type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
export function hubPeerSteps(
    name: string,
    intent: string,
    mode: string,
    guard?: string,
    prepared?: string,
): Fields[] {
    const assets = name + "_assets", command = name + "_command";
    const steps: Fields[] = [{
        name: assets,
        ...placement,
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-hub-command",
            behaviorName: expr(
                '"wireguard-hub-assets-" + string(' + intent + ".hub_id)",
            ),
            methodName: "read",
            inputs: {
                intent: expr(intent),
                mode,
                prepared_peer: expr(prepared ?? "null"),
            },
        },
    }, {
        name: command,
        writes: true,
        ...placement,
        dependsOn: [{ step: assets, condition: { type: "succeeded" } }],
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expr(
                '"wireguard-hub-" + string(' + intent + ".hub_id)",
            ),
            methodName: "script",
            inputs: {
                hosts: "hub",
                interpreter: "python3",
                sudo: true,
                script: expr('steps["' + assets + '"].outputs.script_body'),
            },
        },
    }, {
        name,
        ...placement,
        dependsOn: [{ step: command, condition: { type: "succeeded" } }],
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-hub-command",
            behaviorName: expr(
                '"wireguard-hub-result-" + string(' + intent + ".hub_id)",
            ),
            methodName: "result",
            inputs: {
                hub_id: expr(intent + ".hub_id"),
                stdout: expr(
                    'data.latest("wireguard-hub-" + string(' + intent +
                        '.hub_id), "run-script-hub").attributes.stdout',
                ),
            },
        },
    }];
    if (guard) { for (const step of steps) step.guard = guard; }
    return steps;
}
