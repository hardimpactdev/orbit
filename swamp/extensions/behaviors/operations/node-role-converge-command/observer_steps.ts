import { roleBaselineSteps } from "./steps.ts";

type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { labels: { role: "swamp" } };

/** Observer files and service only. Worker installation and retirement are separate families. */
export function roleObserverSteps(
    name: string,
    intent: string,
    fullGlobals: Fields,
    account: string,
    secret: string,
    binaryChanged: string,
    dependency?: string,
): Fields[] {
    const steps: Fields[] = [];
    let previous = dependency;
    const payload = intent + ".footprint.payloads.agent";
    const failed = () => {
        const names = [
            ...(dependency ? [dependency] : []),
            ...steps.map((step) => String(step.name)),
        ];
        return names.length
            ? `steps.exists(key, ${
                JSON.stringify(names)
            }.exists(prior, key == prior || key.startsWith(prior + "-")) && steps[key].status in ["failed", "cancelled"])`
            : "false";
    };
    function append(group: Fields[]) {
        group[0].dependsOn = [
            ...(dependency ? [dependency] : []),
            ...steps.map((step) => String(step.name)),
        ].map((step) => ({ step, condition: { type: "always" } }));
        for (const step of group) {
            step.guard = expr(failed());
            steps.push(step);
        }
        previous = String(group[group.length - 1].name);
    }
    append(
        roleBaselineSteps(
            name + "_identity",
            "observer_identity",
            {},
            fullGlobals,
            undefined,
            secret,
        ),
    );
    const files = name + "_files";
    append([{
        name: files,
        ...placement,
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: files,
            methodName: "observer_files",
            inputs: {
                configuration: expr(payload + ".configuration"),
                certificate: expr(payload + ".certificate"),
                unit: expr(payload + ".unit"),
                apps_root: expr(payload + ".apps_root"),
                home: expr(`steps[${JSON.stringify(account)}].outputs.home`),
            },
        },
    }]);
    for (
        const [suffix, path, field, marker] of [
            [
                "configuration",
                "/etc/orbit/agent/config.toml",
                "configuration",
                null,
            ],
            ["certificate", "/etc/orbit/agent/ca.pem", "certificate", null],
            [
                "unit",
                "/etc/systemd/system/orbit-agent.service",
                "unit",
                "# Managed by Orbit: agent",
            ],
        ]
    ) {
        append(roleBaselineSteps(name + "_" + suffix, "file", {
            path,
            mode: "0644",
            owner: "root:root",
            marker,
            content: expr(`steps[${JSON.stringify(files)}].outputs.${field}`),
        }, fullGlobals));
    }
    const checkoutName = name + "_checkout";
    const checkout = roleBaselineSteps(checkoutName, "observer_checkout", {
        checkout: expr("self.checkout"),
    }, fullGlobals);
    for (const step of checkout) {
        step.forEach = { item: "checkout", in: expr(payload + ".checkouts") };
    }
    const commandTask = checkout[1].task as Fields;
    const commandInput = commandTask.inputs as Fields;
    commandInput.script = expr(
        `steps[${
            JSON.stringify(checkoutName + "_assets-")
        } + self.checkout].outputs.script_body`,
    );
    const decodeTask = checkout[2].task as Fields;
    const decodeInput = decodeTask.inputs as Fields;
    decodeInput.stdout = expr(
        `steps[${
            JSON.stringify(checkoutName + "_command-")
        } + self.checkout].outputs.stdout`,
    );
    for (const step of checkout) {
        const task = step.task as Fields;
        const identity = expr(
            '"' + String(step.name) + '-" + run.id + "-" + string(self._index)',
        );
        task.behaviorName = identity;
        if (task.globalArgs) {
            task.globalArgs = { ...task.globalArgs as Fields, name: identity };
        }
    }
    append(checkout);
    function command(suffix: string, argv: string[], extraGuard?: string) {
        const step = name + "_" + suffix;
        const failure = failed();
        append([{
            name: step,
            ...placement,
            writes: suffix !== "verify",
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: fullGlobals.name,
                methodName: "exec",
                globalArgs: fullGlobals,
                inputs: { hosts: "name:target", sudo: true, argv },
            },
        }]);
        if (extraGuard) {
            steps[steps.length - 1].guard = expr(
                `(${failure}) || !(${extraGuard})`,
            );
        }
    }
    command("reload", ["/usr/bin/systemctl", "daemon-reload"]);
    command("enable", [
        "/usr/bin/systemctl",
        "enable",
        "--now",
        "orbit-agent.service",
    ]);
    const changed = ["configuration", "certificate", "unit"].map((suffix) =>
        `steps[${JSON.stringify(name + "_" + suffix)}].outputs.changed`
    ).join(" || ");
    command(
        "restart",
        ["/usr/bin/systemctl", "restart", "orbit-agent.service"],
        `${binaryChanged} || ${changed}`,
    );
    command("verify", [
        "/usr/bin/systemctl",
        "is-active",
        "--quiet",
        "orbit-agent.service",
    ]);
    return [...steps, {
        name,
        ...placement,
        dependsOn: [{ step: previous, condition: { type: "always" } }],
        task: {
            type: "assert",
            expr: `!(${failed()}) && steps[${
                JSON.stringify(name + "_verify")
            }].status == "succeeded"`,
            severity: "high",
            message:
                "The existing observer identity, files and service must be proven",
        },
    }];
}

/** Consume the canonical bootstrap observer asset without inventing a bootstrap command. */
export function roleObserverInstallSteps(
    name: string,
    artifact: string,
    fullGlobals: Fields,
    dependency?: string,
): Fields[] {
    const assets = name + "_assets", command = name + "_command";
    const after = (
        step: string,
    ) => [{ step, condition: { type: "succeeded" } }];
    return [{
        name: assets,
        ...placement,
        ...(dependency ? { dependsOn: after(dependency) } : {}),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-bootstrap-command",
            behaviorName: assets,
            methodName: "observer",
            inputs: {
                url: expr(artifact + ".url"),
                sha256: expr(artifact + ".sha256"),
            },
        },
    }, {
        name: command,
        ...placement,
        writes: true,
        dependsOn: after(assets),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: fullGlobals.name,
            methodName: "script",
            globalArgs: fullGlobals,
            inputs: {
                hosts: "name:target",
                sudo: true,
                interpreter: "python3",
                script: expr(
                    `steps[${JSON.stringify(assets)}].outputs.script_body`,
                ),
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
            methodName: "observer_delivery",
            inputs: {
                sha256: expr(artifact + ".sha256"),
                stdout: expr(
                    `steps[${JSON.stringify(command)}].outputs.stdout`,
                ),
            },
        },
    }];
}
