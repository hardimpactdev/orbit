type Fields = Record<string, unknown>;
export type PeerAction =
    | "snapshot"
    | "prepare_key"
    | "publish"
    | "record_dns"
    | "restore"
    | "cleanup";
export type PeerExecution = {
    placement: Fields;
    globalArgs: Fields;
};
const expression = (source: string) => "${{ " + source + " }}";
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

/** One shipped action through official SSH. The parent owns ordering and compensation. */
export function peerActionSteps(
    name: string,
    action: PeerAction,
    input: string,
    execution: PeerExecution,
    dependency?: string,
    guard?: string,
): Fields[] {
    const asset = name + "_asset", command = name + "_command";
    const guarded = guard ? { guard: expression(guard) } : {};
    return [{
        name: asset,
        ...execution.placement,
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        ...guarded,
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-peer-command",
            behaviorName: name + "-asset",
            methodName: "read",
            inputs: { action, input: expression(input) },
        },
    }, {
        name: command,
        writes: true,
        ...execution.placement,
        dependsOn: after(asset),
        ...guarded,
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: execution.globalArgs.name,
            methodName: "script",
            globalArgs: execution.globalArgs,
            inputs: {
                hosts: "name:peer",
                interpreter: "python3",
                sudo: true,
                script: expression(
                    'steps["' + asset + '"].outputs.script_body',
                ),
            },
        },
    }, {
        name,
        ...execution.placement,
        dependsOn: after(command),
        ...guarded,
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-peer-command",
            behaviorName: name + "-result",
            methodName: "decode",
            inputs: {
                stdout: expression('steps["' + command + '"].outputs.stdout'),
            },
        },
    }];
}
