type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";

/** Public prerequisites only. Credential delivery and unit activation belong to the parent. */
export function workerPrerequisiteSteps(
    name: string,
    node: string,
    user: string,
    execution: { placement: Fields; globalArgs: Fields },
    dependency: string,
    selected = "true",
): Fields[] {
    const assets = name + "_assets";
    const output = 'steps["' + assets + '-0"].outputs';
    const model = execution.globalArgs.name;
    const step = (
        name: string,
        previous: string,
        task: Fields,
        writes = false,
    ): Fields => ({
        name,
        ...execution.placement,
        forEach: { item: "worker", in: expr(selected + " ? [{}] : []") },
        dependsOn: [{ step: previous, condition: { type: "succeeded" } }],
        ...(writes ? { writes: true } : {}),
        task,
    });
    const ssh = (methodName: string, inputs: Fields): Fields => ({
        type: "behavior",
        behaviorType: "@swamp/ssh",
        behaviorName: model,
        methodName,
        globalArgs: execution.globalArgs,
        inputs: { hosts: "name:peer", sudo: true, ...inputs },
    });
    return [
        step(assets, dependency, {
            type: "behavior",
            behaviorType: "@nckrtl/node-worker-assets",
            behaviorName: expr('"node-worker-assets-" + string(' + node + ")"),
            methodName: "read",
            inputs: { node_id: expr(node), user: expr(user) },
        }),
        step(
            name + "_preflight",
            assets,
            ssh("script", {
                interpreter: "python3",
                script: expr(output + ".preflight"),
            }),
        ),
        step(
            name + "_checker_directory",
            name + "_preflight",
            ssh("exec", {
                argv: [
                    "/usr/bin/install",
                    "-d",
                    "-m",
                    "0755",
                    "/usr/local/libexec",
                ],
            }),
            true,
        ),
        step(
            name + "_checker",
            name + "_checker_directory",
            ssh("exec", {
                argv: [
                    "/usr/bin/install",
                    "-m",
                    "0755",
                    "/dev/stdin",
                    expr(output + ".checker_path"),
                ],
                stdin: expr(output + ".checker"),
            }),
            true,
        ),
    ];
}
