type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

/** One action through official SSH; the owning role or enrollment root retains ordering. */
export function roleActionSteps(
    name: string,
    action: "account" | "caddy_package",
    input: string,
    globalArgs: Fields,
    dependency?: string,
): Fields[] {
    const asset = name + "_assets", command = name + "_command";
    return [{
        name: asset,
        ...placement,
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-command",
            behaviorName: name + "-assets",
            methodName: "read",
            inputs: { action, input: expr(input) },
        },
    }, {
        name: command,
        ...placement,
        ...(action === "account" ? {} : { writes: true }),
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
                sudo: action !== "account",
                script: expr('steps["' + asset + '"].outputs.script_body'),
            },
        },
    }, {
        name,
        ...placement,
        dependsOn: after(command),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-command",
            behaviorName: name + "-result",
            methodName: "decode",
            inputs: { stdout: expr('steps["' + command + '"].outputs.stdout') },
        },
    }];
}

export function rolePackageSteps(
    name: string,
    prerequisites: string,
    globalArgs: Fields,
    dependency: string,
): Fields[] {
    const asset = name + "_assets";
    return [{
        name: asset,
        ...placement,
        dependsOn: after(dependency),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-bootstrap-command",
            behaviorName: name + "-assets",
            methodName: "packages",
            inputs: {
                releases: expr(prerequisites + ".releases"),
                packages: expr(prerequisites + ".packages"),
            },
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
                sudo: true,
                script: expr('steps["' + asset + '"].outputs.script_body'),
            },
        },
    }];
}

export { authorizedKeySteps } from "../node-bootstrap/authorized_key_steps.ts";
