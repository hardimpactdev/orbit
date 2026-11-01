type Fields = Record<string, unknown>;
const ex = (source: string) => "${{ " + source + " }}";
const on = (step: string) => [{ step, condition: { type: "succeeded" } }];
const target = { target: "orbit-node-209", queueTimeout: 30 };
export function rolePhase(
    name: string,
    file: string,
    globals: Fields,
    budget: number,
    dependency: string,
    options: {
        guard?: string;
        stdin?: string;
        delivery?: boolean;
        account?: boolean;
    } = {},
): Fields {
    const argv: unknown[] = [
        "/usr/bin/timeout",
        String(budget),
        "/usr/bin/env",
        ex('"ORBIT_ROLE_INPUT="+steps.role_assets.outputs.input_json'),
    ];
    if (options.account !== false) {
        argv.push(ex('"ORBIT_ROLE_ACCOUNT="+steps.account.outputs.stdout'));
    }
    if (options.delivery) {
        argv.push(
            ex('"ORBIT_ROLE_DELIVERY="+steps.observer_install.outputs.stdout'),
        );
    }
    argv.push(
        "/usr/bin/python3",
        "-B",
        ex('__ROLE_STAGE__+"/' + file + '.py"'),
    );
    return {
        name,
        ...target,
        writes: true,
        dependsOn: on(dependency),
        ...(options.guard ? { guard: ex(options.guard) } : {}),
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globals.name,
            methodName: "exec",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                sudo: true,
                argv,
                ...(options.stdin ? { stdin: ex(options.stdin) } : {}),
            },
        },
    };
}
/** Public suppliers are each read once, before host effects and optional fan-out. */
export function roleBaselinePhases(
    globals: Fields,
    prepared: boolean,
): Fields[] {
    const source = (
        name: string,
        type: string,
        method: string,
        input: Fields,
    ): Fields => ({
        name,
        ...target,
        task: {
            type: "behavior",
            behaviorType: type,
            behaviorName: ex('"' + name + '-"+run.id'),
            methodName: method,
            inputs: input,
        },
    });
    const steps: Fields[] = [
        source("account_source", "@nckrtl/node-role-command", "read", {
            action: "account",
            input: { user: ex("inputs.intent.user") },
        }),
        source(
            "observer_source",
            "@nckrtl/node-bootstrap-command",
            "observer",
            {
                url: ex("inputs.intent.footprint.payloads.agent.url"),
                sha256: ex("inputs.intent.agent_sha256"),
            },
        ),
        source(
            "packages_source",
            "@nckrtl/node-bootstrap-command",
            "packages",
            {
                releases: ex("inputs.intent.prerequisites.releases"),
                packages: ex(
                    'inputs.intent.prerequisites.packages.filter(p,p != "caddy")',
                ),
            },
        ),
        {
            ...source(
                "role_assets",
                "@nckrtl/node-role-phase-assets",
                "bundle",
                {
                    intent: ex("inputs.intent"),
                    prepared_tools: prepared,
                    sources: {
                        account: ex("steps.account_source.outputs.script_body"),
                        observer_install: ex(
                            "steps.observer_source.outputs.script_body",
                        ),
                        packages: ex(
                            "steps.packages_source.outputs.script_body",
                        ),
                    },
                },
            ),
            dependsOn: ["account_source", "observer_source", "packages_source"]
                .flatMap(on),
        },
        {
            name: "prepare_assets",
            ...target,
            writes: true,
            dependsOn: on("role_assets"),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: globals.name,
                methodName: "exec",
                globalArgs: globals,
                inputs: {
                    hosts: "name:target",
                    argv: [
                        "/usr/bin/timeout",
                        "30",
                        "/usr/bin/mkdir",
                        "-p",
                        ex("__ROLE_STAGE__"),
                    ],
                },
            },
        },
        {
            name: "copy_assets",
            ...target,
            writes: true,
            dependsOn: on("prepare_assets"),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: globals.name,
                methodName: "copy",
                globalArgs: globals,
                inputs: {
                    hosts: "name:target",
                    src: ex('steps.role_assets.outputs.scripts_directory+"/."'),
                    dst: ex("__ROLE_STAGE__"),
                    direction: "to",
                    recursive: true,
                },
            },
        },
        rolePhase("account", "account", globals, 30, "copy_assets", {
            account: false,
        }),
        rolePhase(
            "observer_install",
            "observer_install",
            globals,
            90,
            "account",
        ),
        rolePhase(
            "packages",
            "phase_prerequisites",
            globals,
            600,
            "observer_install",
        ),
    ];
    return steps;
}
