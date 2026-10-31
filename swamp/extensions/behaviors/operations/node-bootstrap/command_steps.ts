type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { target: "orbit-node-209" };
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

/** Collect public trust once; only the accepted full pin can authenticate or mutate. */
export function bootstrapCommandSteps(
    name: string,
    intent: string,
    identityFile: string,
    dependency?: string,
    guard?: string,
    toolsDependency?: string,
    observerHashExpr = '"null"',
): Fields[] {
    const pin = 'steps["' + name + '_pin"].outputs';
    const model = expr('"enrollment-bootstrap-" + run.id');
    const metadata = (methodName: string, inputs: Fields): Fields => ({
        type: "behavior",
        behaviorType: "@nckrtl/node-bootstrap-command",
        behaviorName: expr('"enrollment-bootstrap-assets-" + run.id'),
        methodName,
        inputs,
    });
    const globals = (managed = false, collection = false): Fields => ({
        name: model,
        transport: {
            kind: "ssh",
            user: expr(intent + (managed ? ".managed_user" : ".target.user")),
            port: expr(intent + ".target.port"),
            identityFile: expr(identityFile),
        },
        hosts: [{
            name: "target",
            address: expr(intent + ".target.host"),
            transport: collection
                ? { hostKeyPolicy: "tofu" }
                : { hostKeys: expr(pin + ".host_keys") },
        }],
    });
    const ssh = (
        step: string,
        method: string,
        inputs: Fields,
        dependency: string,
        managed = false,
    ): Fields => ({
        name: step,
        ...placement,
        dependsOn: after(dependency),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: model,
            methodName: method,
            globalArgs: globals(managed),
            inputs: { hosts: "name:target", ...inputs },
        },
    });
    const steps: Fields[] = [
        {
            name: name + "_host_key",
            ...placement,
            ...(dependency ? { dependsOn: after(dependency) } : {}),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: model,
                methodName: "collect-host-public-key",
                globalArgs: globals(false, true),
                inputs: { hosts: "name:target" },
            },
        },
        {
            name: name + "_pin",
            ...placement,
            dependsOn: after(name + "_host_key"),
            task: metadata("pin", {
                expected_fingerprint: expr(intent + ".target.fingerprint"),
                host_key: expr('steps["' + name + '_host_key"].outputs'),
            }),
        },
        ssh(
            name + "_platform",
            "exec",
            { argv: ["uname", "-sm"] },
            name + "_pin",
        ),
        {
            name: name + "_observed",
            ...placement,
            dependsOn: after(name + "_platform"),
            task: metadata("observe", {
                bootstrap: expr(intent),
                observation: expr('steps["' + name + '_platform"].outputs'),
                host_key: expr('steps["' + name + '_host_key"].outputs'),
            }),
        },
        ssh(
            name + "_os_release",
            "exec",
            { argv: ["cat", "/etc/os-release"] },
            name + "_observed",
        ),
        {
            name: name + "_operating_system",
            ...placement,
            dependsOn: after(name + "_os_release"),
            task: metadata("operating_system", {
                stdout: expr('steps["' + name + '_os_release"].outputs.stdout'),
            }),
        },
    ];
    let previous = name + "_operating_system";
    for (
        const action of [
            "account",
            "authorized_key",
            "sudo",
            "observer",
            "verify",
        ]
    ) {
        if (action === "verify") {
            const assets = name + "_identity_assets",
                command = name + "_identity_command";
            steps.push({
                name: assets,
                ...placement,
                dependsOn: after(previous),
                task: metadata("observer_identity", {}),
            });
            steps.push(
                ssh(command, "exec", {
                    sudo: expr(intent + '.target.user != "root"'),
                    argv: [
                        "/usr/bin/python3",
                        "-c",
                        expr('steps["' + assets + '"].outputs.script_body'),
                    ],
                    stdin: expr(observerHashExpr),
                }, assets),
            );
            steps[steps.length - 1].writes = true;
            steps.push({
                name: name + "_identity",
                ...placement,
                dependsOn: after(command),
                task: metadata("observer_identity_result", {
                    stdout: expr('steps["' + command + '"].outputs.stdout'),
                }),
            });
            previous = name + "_identity";
        }
        const asset = name + "_" + action + "_assets",
            command = name + "_" + action;
        steps.push({
            name: asset,
            ...placement,
            dependsOn: after(
                action === "sudo" && toolsDependency
                    ? toolsDependency
                    : previous,
            ),
            task: metadata("read", {
                action,
                user: expr(intent + ".managed_user"),
                command: expr(intent + ".command"),
                artifact: expr(
                    intent + ".artifacts[" + intent +
                        '.platform == "linux" ? "linux-x86_64" : (steps["' +
                        name +
                        '_observed"].outputs.architecture == "arm64" ? "macos-aarch64" : "macos-x86_64")]',
                ),
            }),
        });
        steps.push(
            ssh(
                command,
                "script",
                {
                    interpreter: "python3",
                    sudo: action === "verify"
                        ? false
                        : expr(intent + '.target.user != "root"'),
                    script: expr('steps["' + asset + '"].outputs.script_body'),
                },
                asset,
                action === "verify",
            ),
        );
        if (action !== "verify") steps[steps.length - 1].writes = true;
        previous = command;
    }
    for (const step of steps.slice(4)) {
        step.guard = expr(intent + '.platform != "linux"');
    }
    const observed = 'steps["' + name + '_observed"].outputs';
    const mac: Fields[] = [];
    let prior = name + "_observed";
    for (
        const [suffix, argv] of [["user", ["/usr/bin/id", "-un"]], ["home", [
            "/usr/bin/printenv",
            "HOME",
        ]], ["addresses", ["/sbin/ifconfig"]]] as [string, string[]][]
    ) {
        const current = name + "_mac_" + suffix;
        mac.push(ssh(current, "exec", { argv }, prior, true));
        prior = current;
    }
    mac.push({
        name: name + "_mac_observer_assets",
        ...placement,
        dependsOn: after(prior),
        task: metadata("macos_observer", {}),
    });
    const artifact = intent + ".artifacts[" + observed +
        '.architecture == "arm64" ? "macos-aarch64" : "macos-x86_64"]';
    mac.push(
        ssh(
            name + "_mac_observer",
            "script",
            {
                interpreter: "sh",
                sudo: false,
                script: expr(
                    'steps["' + name +
                        '_mac_observer_assets"].outputs.script_body',
                ),
                env: {
                    OBSERVER_URL: expr(artifact + ".url"),
                    OBSERVER_SHA256: expr(artifact + ".sha256"),
                },
            },
            name + "_mac_observer_assets",
            true,
        ),
    );
    mac.push(
        ssh(
            name + "_mac_digest",
            "exec",
            {
                argv: [
                    "/usr/bin/shasum",
                    "-a",
                    "256",
                    expr(
                        'steps["' + name +
                            '_mac_home"].outputs.stdout.trim() + "/.orbit/bin/orbit-agent"',
                    ),
                ],
            },
            name + "_mac_observer",
            true,
        ),
    );
    for (const step of mac) step.guard = expr(intent + '.platform != "macos"');
    steps.push(...mac);
    steps.push({
        name: name + "_ready",
        ...placement,
        dependsOn: steps.map((step) => ({
            step: String(step.name),
            condition: { type: "always" },
        })),
        task: metadata("complete", {
            bootstrap: expr(intent),
            observation: expr(observed),
            linux: expr(
                intent + '.platform == "linux" ? steps["' + name +
                    '_verify"].outputs.stdout : null',
            ),
            macos: {
                user: expr(
                    intent + '.platform == "macos" ? steps["' + name +
                        '_mac_user"].outputs.stdout : ""',
                ),
                addresses: expr(
                    intent + '.platform == "macos" ? steps["' + name +
                        '_mac_addresses"].outputs.stdout : ""',
                ),
                digest: expr(
                    intent + '.platform == "macos" ? steps["' + name +
                        '_mac_digest"].outputs.stdout : ""',
                ),
            },
        }),
    });
    if (guard) {
        for (const step of steps) {
            step.guard = expr(
                guard +
                    (step.guard
                        ? " || (" + String(step.guard).slice(4, -3) + ")"
                        : ""),
            );
        }
    }
    return steps;
}
