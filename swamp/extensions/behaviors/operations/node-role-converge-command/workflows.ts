import {
    roleActionSteps,
    rolePackageSteps,
} from "../node-role-command/steps.ts";
import {
    roleObserverInstallSteps,
    roleObserverSteps,
} from "./observer_steps.ts";
import { stringify } from "jsr:@std/yaml@1.0.12";
type Fields = Record<string, unknown>;
const ex = (s: string) => "${{ " + s + " }}";
const on = (
    step: string,
    type = "succeeded",
) => [{ step, condition: { type } }];
const target = { target: "orbit-node-209" };
const references = {
    type: "object",
    additionalProperties: {
        type: "object",
        properties: {
            vault: { type: "string", enum: ["orbit-control-inputs"] },
            key: { type: "string", pattern: "^node-role-[0-9]+-observer$" },
        },
        required: ["vault", "key"],
        additionalProperties: false,
    },
};
export function databaseWorkflow() {
    const ssh = "inputs.intent.ssh";
    const globals = {
        name: ex('"role-converge-"+run.id'),
        transport: {
            kind: "ssh",
            user: ex(ssh + ".target.user"),
            port: ex(ssh + ".target.port"),
            identityFile: ex(ssh + ".identity_file"),
        },
        hosts: [{
            name: "target",
            address: ex(ssh + ".target.host"),
            transport: { hostKeys: ex(ssh + ".target.host_keys") },
        }],
    };
    const steps: Fields[] = [
        ...roleActionSteps(
            "account",
            "account",
            '{"user":inputs.intent.user}',
            globals,
        ),
        ...roleObserverInstallSteps(
            "observer_install",
            '({"url":inputs.intent.footprint.payloads.agent.url,"sha256":inputs.intent.agent_sha256})',
            globals,
            "account",
        ),
        ...rolePackageSteps(
            "packages",
            "inputs.intent.prerequisites",
            globals,
            "observer_install",
        ),
    ];
    function action(
        name: string,
        methodName: string,
        input: Fields,
        dependency: string,
        guard?: string,
    ) {
        steps.push({
            name: name + "_assets",
            ...target,
            dependsOn: on(dependency),
            ...(guard ? { guard: ex(guard) } : {}),
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/node-role-converge-command",
                behaviorName: name + "-assets",
                methodName,
                inputs: input,
            },
        }, {
            name: name + "_command",
            ...target,
            writes: true,
            dependsOn: on(name + "_assets"),
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: globals.name,
                methodName: "script",
                globalArgs: globals,
                inputs: {
                    hosts: "name:target",
                    sudo: true,
                    interpreter: "python3",
                    script: ex(
                        'steps["' + name + '_assets"].outputs.script_body',
                    ),
                },
            },
        }, {
            name,
            ...target,
            dependsOn: on(name + "_command"),
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/node-role-converge-command",
                behaviorName: name + "-result",
                methodName: methodName === "cli" ? "cli_result" : "dns_result",
                inputs: {
                    stdout: ex('steps["' + name + '_command"].outputs.stdout'),
                },
            },
        });
    }
    // DNS has a singleton native fan-out. Its only task data is self-bound, so an
    // absent network does not resolve missing fields before the skip guard.
    const start = steps.length;
    action("peer_dns", "dns", {
        dns_server: ex("self.dns.dns_server"),
        public_key: ex("self.dns.public_key"),
        domains: ex("self.dns.domains"),
    }, "packages");
    for (const step of steps.slice(start)) {
        step.forEach = {
            item: "dns",
            in: ex(
                "inputs.intent.footprint.network == null ? [] : [inputs.intent.footprint.network.peer_dns]",
            ),
        };
        const task = step.task as Fields;
        const identity = ex(
            '"' + String(step.name) + '-" + run.id + "-" + string(self._index)',
        );
        task.behaviorName = identity;
        if (task.globalArgs) {
            task.globalArgs = { ...task.globalArgs as Fields, name: identity };
        }
        step.task = JSON.parse(
            JSON.stringify(step.task).replaceAll(
                'peer_dns_assets\\"',
                'peer_dns_assets-0\\"',
            ).replaceAll('peer_dns_command\\"', 'peer_dns_command-0\\"'),
        );
    }
    steps.push(
        ...roleObserverSteps(
            "observer",
            "inputs.intent",
            globals,
            "account",
            "vault.get(inputs.execution_secrets.observer.vault,inputs.execution_secrets.observer.key)",
            "steps.observer_install.outputs.changed",
            "packages",
        ),
    );
    action(
        "cli",
        "cli",
        {
            user: ex("inputs.intent.cli.user"),
            release: ex("inputs.intent.cli.release"),
            profile: ex("inputs.intent.cli.profile"),
        },
        "packages",
        "inputs.intent.cli.skip_reason != null || inputs.intent.cli.plan_error != null",
    );
    for (const step of steps) {
        delete step.labels;
        step.allowFailure = true;
    }
    return {
        id: "21b08662-58e5-4485-b42c-540dd9bd6004",
        name: "node-database-role-converge",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                intent: { type: "object" },
                execution_secrets: references,
            },
            required: ["intent", "execution_secrets"],
            additionalProperties: false,
        },
        ...target,
        jobs: [{ name: "effects", steps }, {
            name: "result",
            dependsOn: [{ job: "effects", condition: { type: "always" } }],
            steps: [{
                name: "result",
                ...target,
                task: {
                    type: "behavior",
                    behaviorType: "@nckrtl/node-role-converge-result",
                    behaviorName: "database-result",
                    methodName: "assemble",
                    inputs: {
                        intent: ex("inputs.intent"),
                        steps: ex(
                            "steps",
                        ),
                    },
                },
            }],
        }],
    };
}
export function dispatcherWorkflow() {
    return {
        id: "463c1e47-f612-4296-a3f8-5e4b1c4bcbfa",
        name: "operation-node-role-converge-209",
        vaults: ["orbit-control-inputs"],
        ...target,
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", enum: ["node.role_converge"] },
                intent: { type: "object" },
                execution_secrets: references,
            },
            required: ["operation_id", "node_id", "kind"],
            additionalProperties: true,
        },
        jobs: [{
            name: "dispatch",
            steps: [{
                name: "native",
                ...target,
                forEach: {
                    item: "choice",
                    in: ex(
                        "has(inputs.intent) && inputs.intent != null ? [0] : []",
                    ),
                },
                task: {
                    type: "workflow",
                    workflowIdOrName: "node-database-role-converge",
                    inputs: {
                        intent: ex("has(inputs.intent) ? inputs.intent : null"),
                        execution_secrets: ex("inputs.execution_secrets"),
                    },
                },
            }, {
                name: "retained",
                ...target,
                forEach: {
                    item: "choice",
                    in: ex(
                        "has(inputs.intent) && inputs.intent != null ? [] : [0]",
                    ),
                },
                task: {
                    type: "workflow",
                    workflowIdOrName: "operation-node-role-converge-direct",
                    inputs: {
                        operation_id: ex("inputs.operation_id"),
                        node_id: ex("inputs.node_id"),
                        kind: ex("inputs.kind"),
                        parent_run_id: ex("run.id"),
                    },
                },
            }],
        }, {
            name: "result",
            dependsOn: [{ job: "dispatch", condition: { type: "always" } }],
            steps: [{
                name: "result",
                ...target,
                task: {
                    type: "behavior",
                    behaviorType: "@nckrtl/node-role-converge-result",
                    behaviorName: "converge-result",
                    methodName: "relay",
                    inputs: {
                        result: ex(
                            '("native-0" in steps) && ("outputs" in steps["native-0"]) && ("result" in steps["native-0"].outputs) ? steps["native-0"].outputs.result : {}',
                        ),
                    },
                },
            }, {
                name: "proven",
                ...target,
                dependsOn: on("result"),
                task: {
                    type: "assert",
                    expr:
                        "!has(inputs.intent) || inputs.intent == null || (has(steps.result.outputs.role_proven) && steps.result.outputs.role_proven)",
                    severity: "high",
                    message: "Required Database role actions must complete",
                },
            }],
        }],
    };
}
export function generatedRoleWorkflows() {
    return [databaseWorkflow(), dispatcherWorkflow()].map((workflow) => ({
        path: new URL(
            "../../../../workflows/workflow-" + workflow.name + ".yaml",
            import.meta.url,
        ),
        text:
            "# deno-fmt-ignore-file\n# Generated from node-role-converge-command/workflows.ts\n" +
            stringify(workflow, { flowLevel: 4, lineWidth: -1 }),
    }));
}
if (import.meta.main) {
    for (const { path, text } of generatedRoleWorkflows()) {
        await Deno.writeTextFile(path, text);
    }
}
