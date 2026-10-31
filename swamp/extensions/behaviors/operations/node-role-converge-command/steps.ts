type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

/** One baseline action; the parent supplies its immutable public input and SSH globals. */
export function roleBaselineSteps(
    name: string,
    action: "storage" | "directory" | "ci_resource",
    input: Fields,
    globalArgs: Fields,
    dependency?: string,
): Fields[] {
    const assets = name + "_assets", command = name + "_command";
    return [{
        name: assets,
        ...placement,
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: assets,
            methodName: "read",
            inputs: { action, input },
        },
    }, {
        name: command,
        ...placement,
        writes: true,
        dependsOn: after(assets),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globalArgs.name,
            methodName: "script",
            globalArgs,
            inputs: {
                hosts: "name:target",
                interpreter: "python3",
                sudo: true,
                script: expr('steps["' + assets + '"].outputs.script_body'),
            },
        },
    }, {
        name,
        ...placement,
        dependsOn: after(command),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: name,
            methodName: "decode",
            inputs: { stdout: expr('steps["' + command + '"].outputs.stdout') },
        },
    }];
}
