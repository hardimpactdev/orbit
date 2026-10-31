type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

/** The tracked agent footprint and bootstrap share one idempotent authorized-key action. */
export function authorizedKeySteps(
    name: string,
    user: string,
    publicKey: string,
    globalArgs: Fields,
    dependency?: string,
): Fields[] {
    const asset = name + "_assets";
    return [{
        name: asset,
        ...placement,
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-ssh-access",
            behaviorName: name + "-assets",
            methodName: "read",
            inputs: { user: expr(user), public_key: expr(publicKey) },
        },
    }, {
        name,
        ...placement,
        writes: true,
        dependsOn: after(asset),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globalArgs.name,
            methodName: "script",
            globalArgs,
            inputs: {
                hosts: "name:target",
                interpreter: "python3",
                sudo: false,
                script: expr('steps["' + asset + '"].outputs.script_body'),
            },
        },
    }];
}
