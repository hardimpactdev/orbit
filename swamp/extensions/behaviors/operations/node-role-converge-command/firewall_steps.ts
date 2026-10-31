type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { target: "orbit-node-209" };
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

/** One canonical UFW transaction, including interface and destination ownership. */
export function roleFirewallSteps(
    name: string,
    input: string,
    fullGlobals: Fields,
    dependency?: string,
    guard?: string,
): Fields[] {
    const asset = name + "_assets", command = name + "_command";
    const selected = guard ? { guard: guard.startsWith("${{") ? guard : expr(guard) } : {};
    return [{
        name: asset, ...placement, ...selected,
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        task: {
            type: "behavior", behaviorType: "@nckrtl/node-role-firewall-assets",
            behaviorName: asset, methodName: "read", inputs: { rules: expr(input) },
        },
    }, {
        name: command, ...placement, ...selected, writes: true,
        dependsOn: after(asset),
        task: {
            type: "behavior", behaviorType: "@swamp/ssh", behaviorName: fullGlobals.name,
            methodName: "script", globalArgs: fullGlobals,
            inputs: { hosts: "name:target", interpreter: "python3", sudo: true,
                script: expr(`steps[${JSON.stringify(asset)}].outputs.script_body`) },
        },
    }, {
        name, ...placement, ...selected, dependsOn: after(command),
        task: {
            type: "behavior", behaviorType: "@nckrtl/node-role-firewall-assets",
            behaviorName: name, methodName: "decode",
            inputs: { stdout: expr(`steps[${JSON.stringify(command)}].outputs.stdout`) },
        },
    }];
}
