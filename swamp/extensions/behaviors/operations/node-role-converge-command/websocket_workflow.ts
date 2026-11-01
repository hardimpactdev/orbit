import { stringify } from "jsr:@std/yaml@1.0.12";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
const on = (step: string, type = "succeeded") => ({
    step,
    condition: { type },
});
const status = (name: string) =>
    `("${name}" in steps ? steps["${name}"].status : "skipped")`;
export function websocketWorkflow() {
    const globals = {
        name: ex('"websocket-"+run.id'),
        transport: {
            kind: "ssh",
            user: ex("inputs.ssh.target.user"),
            port: ex("inputs.ssh.target.port"),
            identityFile: ex("inputs.ssh.identity_file"),
        },
        hosts: [{
            name: "target",
            address: ex("inputs.ssh.target.host"),
            transport: { hostKeys: ex("inputs.ssh.target.host_keys") },
        }],
    };
    const staging = ex('"/tmp/orbit-websocket-"+run.id');
    const steps: Fields[] = [{
        name: "assets",
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-runtime-assets",
            behaviorName: ex('"websocket-assets-"+run.id'),
            methodName: "bundle",
            inputs: { runtime: ex("inputs.runtime"), run_id: ex("run.id") },
        },
    }, {
        name: "prepare_assets",
        dependsOn: [on("assets")],
        writes: true,
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
                    staging,
                ],
            },
        },
    }, {
        name: "copy_assets",
        dependsOn: [on("prepare_assets")],
        writes: true,
        task: {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: globals.name,
            methodName: "copy",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                src: ex('steps.assets.outputs.scripts_directory+"/."'),
                dst: staging,
                direction: "to",
                recursive: true,
            },
        },
    }];
    const budgets: Record<string, number> = {
        account: 30,
        checkout: 120,
        dependencies: 300,
        environment: 60,
        unit: 30,
        activation: 45,
        verify: 15,
        restore: 60,
        cleanup: 30,
    };
    const forward = [
        "account",
        "checkout",
        "dependencies",
        "environment",
        "unit",
        "activation",
        "verify",
    ];
    function phase(name: string, dependencies: Fields[], guard?: string) {
        const argv: unknown[] = [
            "/usr/bin/timeout",
            String(budgets[name]),
            "/usr/bin/env",
            ex('"ORBIT_ROLE_INPUT="+steps.assets.outputs.input_json'),
        ];
        if (name !== "account") {
            argv.push(ex('"ORBIT_ROLE_ACCOUNT="+steps.account.outputs.stdout'));
        }
        if (name === "cleanup") {
            argv.push(
                ex(
                    '"ORBIT_ROLE_RETAIN_CANDIDATE="+string(' +
                        status("activation") +
                        ' in ["succeeded","failed","cancelled"])',
                ),
            );
        }
        argv.push(
            "/usr/bin/python3",
            "-B",
            ex('"/tmp/orbit-websocket-"+run.id+"/phase_' + name + '.py"'),
        );
        steps.push({
            name,
            writes: true,
            dependsOn: dependencies,
            ...(guard ? { guard: ex(guard) } : {}),
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
                    ...(name === "environment"
                        ? {
                            stdin: ex(
                                "vault.get(inputs.material.vault,inputs.material.key)",
                            ),
                        }
                        : {}),
                },
            },
        });
    }
    for (const [index, name] of forward.entries()) {
        phase(name, [on(index ? forward[index - 1] : "copy_assets")]);
    }
    phase(
        "restore",
        [on("activation", "always"), on("verify", "always")],
        status("account") + ' != "succeeded" || !(' + status("activation") +
            ' in ["failed","cancelled"] || ' + status("verify") +
            ' in ["failed","cancelled"])',
    );
    phase("cleanup", [
        ...forward.map((s) => on(s, "always")),
        on("restore", "always"),
    ], status("account") + ' != "succeeded"');
    steps.push({
        name: "result",
        dependsOn: steps.map((s) => on(String(s.name), "always")),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-runtime-assets",
            behaviorName: ex('"websocket-result-"+run.id'),
            methodName: "complete",
            inputs: {
                node_id: ex("inputs.runtime.node_id"),
                assignment_id: ex("inputs.runtime.assignment_id"),
                statuses: Object.fromEntries(
                    [...forward, "restore", "cleanup"].map(
                        (name) => [name, ex(status(name))],
                    ),
                ),
            },
        },
    });
    steps.push({
        name: "proven",
        dependsOn: [on("result")],
        task: {
            type: "assert",
            expr: "steps.result.outputs.completed",
            severity: "high",
            message: "Every required WebSocket phase must complete",
        },
    });
    for (const step of steps) {
        step.target = "orbit-node-209";
        step.queueTimeout = 30;
        if (step.name !== "proven") step.allowFailure = true;
    }
    return {
        id: "b5f10569-2322-44e2-8c42-312374dc2b1d",
        name: "node-role-websocket",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                runtime: { type: "object" },
                ssh: { type: "object" },
                material: {
                    type: "object",
                    properties: {
                        vault: {
                            type: "string",
                            enum: ["orbit-control-inputs"],
                        },
                        key: { type: "string" },
                    },
                    required: ["vault", "key"],
                    additionalProperties: false,
                },
            },
            required: ["runtime", "ssh", "material"],
            additionalProperties: false,
        },
        jobs: [{ name: "runtime", steps }],
    };
}
if (import.meta.main) {
    await Deno.writeTextFile(
        new URL(
            "../../../../workflows/workflow-node-role-websocket.yaml",
            import.meta.url,
        ),
        stringify(websocketWorkflow(), { flowLevel: 4, lineWidth: -1 }),
    );
}
