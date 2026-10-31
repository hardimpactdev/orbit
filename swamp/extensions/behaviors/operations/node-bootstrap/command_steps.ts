type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";

/** Compose public bootstrap input into official SSH methods in the owning run. */
export function bootstrapCommandSteps(
    name: string,
    intent: string,
    identity: string,
    executor = "inputs.node_id",
    guard?: string,
): Fields[] {
    const placement = {
        target: expr('"orbit-node-" + string(' + executor + ")"),
        labels: { node: expr("string(" + executor + ")") },
    };
    const observed = 'steps["' + name + '_observed"].outputs';
    const modelName = expr('"bootstrap-ssh-" + string(inputs.operation_id)');
    const globals = (candidate: boolean, first: boolean): Fields => ({
        name: modelName,
        transport: {
            kind: "ssh",
            user: expr(intent + (candidate ? ".managed_user" : ".target.user")),
            port: expr(intent + ".target.port"),
            identityContent: expr(identity),
        },
        hosts: [{
            name: "target",
            address: expr(intent + ".target.host"),
            transport: first
                ? expr(
                    "size(" + intent + ".target.host_keys) > 0 ? " +
                        '{"hostKeys": ' + intent +
                        '.target.host_keys} : {"hostKeyPolicy": "tofu"}',
                )
                : { hostKeys: expr(observed + ".host_keys") },
        }],
    });
    const metadata = (methodName: string, inputs: Fields): Fields => ({
        type: "behavior",
        behaviorType: "@nckrtl/node-bootstrap-command",
        behaviorName: expr('"bootstrap-assets-" + string(inputs.operation_id)'),
        methodName,
        inputs,
    });
    const after = (
        step: string,
    ) => [{ step, condition: { type: "succeeded" } }];
    const steps: Fields[] = [{
        name: name + "_host_key",
        ...placement,
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: modelName,
            methodName: "collect-host-public-key",
            globalArgs: globals(false, true),
            inputs: { hosts: "name:target" },
        },
    }, {
        name: name + "_platform",
        ...placement,
        dependsOn: after(name + "_host_key"),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: modelName,
            methodName: "exec",
            globalArgs: globals(false, true),
            inputs: { hosts: "name:target", argv: ["uname", "-sm"] },
        },
    }, {
        name: name + "_observed",
        ...placement,
        dependsOn: after(name + "_platform"),
        task: metadata("observe", {
            bootstrap: expr(intent),
            observation: expr('steps["' + name + '_platform"].outputs'),
            host_key: expr('steps["' + name + '_host_key"].outputs'),
        }),
    }];
    let previous = name + "_observed";
    for (
        const action of [
            "packages",
            "account",
            "authorized_key",
            "sudo",
            "observer",
            "verify",
        ]
    ) {
        const assets = name + "_" + action + "_assets";
        const command = name + "_" + action;
        steps.push({
            name: assets,
            ...placement,
            dependsOn: after(previous),
            task: metadata("read", {
                action,
                user: expr(intent + ".managed_user"),
                command: expr(intent + ".command"),
                artifact: expr(intent + '.artifacts["linux-x86_64"]'),
            }),
        }, {
            name: command,
            ...placement,
            ...(action === "verify" ? {} : { writes: true }),
            dependsOn: after(assets),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: modelName,
                methodName: "script",
                globalArgs: globals(action === "verify", false),
                inputs: {
                    hosts: "name:target",
                    interpreter: "python3",
                    sudo: action === "verify"
                        ? false
                        : expr(intent + '.target.user != "root"'),
                    script: expr('steps["' + assets + '"].outputs.script_body'),
                },
            },
        });
        previous = command;
    }
    if (guard) { for (const step of steps) step.guard = expr(guard); }
    return steps;
}
