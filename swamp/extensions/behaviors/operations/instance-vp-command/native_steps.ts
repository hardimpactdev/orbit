type Fields = Record<string, unknown>;
export const expression = (value: string) => "${{ " + value + " }}";
/** Pure native graph construction. Each effect is a separately placed official action. */
export function instanceActions(
    name: string,
    ssh: string,
    dependency?: string,
    assetsType = "@nckrtl/instance-dev-command",
) {
    const steps: Fields[] = [];
    const globals = {
        name: expression('"id-' + name.slice(0, 10) + '-" + run.id'),
        transport: {
            kind: "ssh",
            user: expression(ssh + ".target.user"),
            port: expression(ssh + ".target.port"),
            identityFile: expression(ssh + ".identity_file"),
        },
        hosts: [{
            name: "target",
            address: expression(ssh + ".target.host"),
            transport: { hostKeys: expression(ssh + ".target.host_keys") },
        }],
    };
    function add(suffix: string, task: Fields, extra: Fields = {}) {
        const prior = steps.at(-1)?.name ?? dependency;
        const step: Fields = {
            name: name + "-" + suffix,
            target: "orbit-node-209",
            ...(prior
                ? {
                    dependsOn: [{
                        step: prior,
                        condition: { type: "succeeded" },
                    }],
                }
                : {}),
            task,
            ...extra,
        };
        if (extra.forEach) {
            task.behaviorName = String(task.behaviorName).replace(
                / \}\}$/,
                ' + "-" + string(self._index) }}',
            );
        }
        steps.push(step);
        return String(step.name);
    }
    function metadata(
        suffix: string,
        method: string,
        inputs: Fields,
        behaviorType = "@nckrtl/instance-dev-command",
        extra: Fields = {},
    ) {
        return add(suffix, {
            type: "behavior",
            behaviorType,
            behaviorName: expression(
                '"idm-' + name.slice(0, 6) + "-" + steps.length + '-" + run.id',
            ),
            methodName: method,
            inputs,
        }, extra);
    }
    function sshAction(
        suffix: string,
        methodName: string,
        inputs: Fields,
        extra: Fields = {},
    ) {
        return add(suffix, {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expression(
                '"ids-' + name.slice(0, 6) + "-" + steps.length + '-" + run.id',
            ),
            methodName,
            globalArgs: globals,
            inputs: { hosts: "name:target", ...inputs },
        }, extra);
    }
    function script(
        suffix: string,
        action: string,
        input: unknown,
        extra: Fields = {},
    ) {
        metadata(suffix + "-asset", "read", { action, input }, assetsType);
        return sshAction(suffix, "script", {
            interpreter: "python3",
            script: expression(
                'steps["' + name + "-" + suffix +
                    '-asset"].outputs.script_body',
            ),
        }, extra);
    }
    return {
        steps,
        globals,
        add,
        metadata,
        sshAction,
        script,
        output: (suffix: string) =>
            'steps["' + name + "-" + suffix + '"].outputs',
    };
}
