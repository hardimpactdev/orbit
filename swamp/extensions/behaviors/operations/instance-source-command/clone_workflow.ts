import { sourceSteps } from "./workflow.ts";
type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
/** One development clone with read-only candidate facts and native target source actions. */
export function cloneWorkflow(): Fields {
    const descriptor = () => ({
        type: "object",
        properties: {
            vault: { type: "string", enum: ["orbit-control-inputs"] },
            key: {
                type: "string",
                pattern: "^[A-Za-z0-9][A-Za-z0-9_.:-]{0,255}$",
            },
        },
        required: ["vault", "key"],
        additionalProperties: false,
    });
    const steps: Fields[] = [];
    const id = () => expr('"icl-" + run.id + "-' + steps.length + '"');
    function add(name: string, task: Fields) {
        steps.push({
            name,
            target: "orbit-node-209",
            ...(steps.length
                ? {
                    dependsOn: [{
                        step: steps.at(-1)!.name,
                        condition: { type: "succeeded" },
                    }],
                }
                : {}),
            task,
        });
    }
    function typed(name: string, method: string, inputs: Fields) {
        add(name, {
            type: "behavior",
            behaviorType: "@nckrtl/instance-source-command",
            behaviorName: id(),
            methodName: method,
            inputs,
        });
    }
    function globals(side: "candidate" | "target"): Fields {
        const ssh = side === "candidate"
            ? "inputs.intent.candidate.ssh_execution"
            : "inputs.intent.ssh_execution";
        return {
            name: id(),
            transport: {
                kind: "ssh",
                user: expr(`${ssh}.target.user`),
                port: expr(`${ssh}.target.port`),
                identityFile: expr(`${ssh}.identity_file`),
            },
            hosts: [{
                name: "target",
                address: expr(`${ssh}.target.host`),
                transport: { hostKeys: expr(`${ssh}.target.host_keys`) },
            }],
        };
    }
    typed("paths", "paths", { intent: expr("inputs.intent") });
    add("mint", {
        type: "behavior",
        behaviorType: "command/shell",
        behaviorName: id(),
        methodName: "execute",
        inputs: {
            run: 'python3 -B "$ORBIT_MINT"',
            env: {
                ORBIT_MINT: expr('steps.paths.outputs.directory + "/mint.py"'),
                ORBIT_OPERATION_ID: expr("string(inputs.operation_id)"),
                ORBIT_RUN_ID: expr("run.id"),
                ORBIT_GIT_REFERENCES: expr(
                    "inputs.intent.execution_references_json",
                ),
            },
        },
    });
    for (
        const [name, argv] of Object.entries({
            branch: ["branch", "--show-current"],
            commit: ["rev-parse", "--verify", "HEAD^{commit}"],
            status: ["status", "--porcelain", "--untracked-files=all"],
            submodules: ["submodule", "status", "--recursive"],
        })
    ) {
        add("candidate-" + name, {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: id(),
            methodName: "exec",
            globalArgs: globals("candidate"),
            inputs: {
                hosts: "name:target",
                argv: [
                    "/usr/bin/git",
                    "-c",
                    "core.hooksPath=/dev/null",
                    "-c",
                    "core.fsmonitor=false",
                    "-C",
                    expr("inputs.intent.candidate.checkout"),
                    ...argv,
                ],
            },
        });
    }
    typed("candidate-origin-assets", "read", {
        action: "origin",
        input: expr("inputs.intent.candidate"),
    });
    add("candidate-origin", {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        behaviorName: id(),
        methodName: "script",
        globalArgs: globals("candidate"),
        inputs: {
            hosts: "name:target",
            interpreter: "python3",
            script: expr(
                'steps["candidate-origin-assets"].outputs.script_body',
            ),
        },
    });
    typed("candidate", "cloneCandidate", {
        intent: expr("inputs.intent.candidate"),
        ...Object.fromEntries(
            ["origin", "branch", "commit", "status", "submodules"].map((
                name,
            ) => [name, expr(`steps["candidate-${name}"].outputs`)]),
        ),
    });
    const source = sourceSteps(
        "source",
        "inputs.intent",
        "vault.get(inputs.execution_secrets.git_read.vault,inputs.execution_secrets.git_read.key)",
    );
    source[0].dependsOn = [{
        step: "candidate",
        condition: { type: "succeeded" },
    }];
    steps.push(...source);
    add("environment", {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        behaviorName: id(),
        methodName: "exec",
        globalArgs: globals("target"),
        inputs: {
            hosts: "name:target",
            argv: [
                "/usr/bin/python3",
                "-B",
                expr(
                    '"/tmp/orbit-source-" + run.id + "-source/scripts/write_environment.py"',
                ),
                expr("inputs.intent.environment_path"),
            ],
            stdin: expr(
                "vault.get(inputs.execution_secrets.environment.vault,inputs.execution_secrets.environment.key)",
            ),
        },
    });
    typed("result", "cloneResult", {
        intent: expr("inputs.intent"),
        source: expr('steps["source-result"].outputs'),
        candidate: expr("steps.candidate.outputs"),
        environment: expr("steps.environment.outputs"),
    });
    return {
        id: "6c413eaa-2e25-43c8-b146-f6c8e103c00d",
        name: "operation-instance-clone",
        description:
            "Clone committed development source through official209 SSH and one terminal projection.",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", enum: ["instance.clone"] },
                intent: { type: "object" },
                execution_secrets: {
                    type: "object",
                    properties: {
                        git_read: descriptor(),
                        environment: descriptor(),
                    },
                    required: ["git_read", "environment"],
                    additionalProperties: false,
                },
            },
            required: [
                "operation_id",
                "node_id",
                "kind",
                "intent",
                "execution_secrets",
            ],
            additionalProperties: false,
        },
        jobs: [{ name: "clone", target: "orbit-node-209", steps }],
    };
}
