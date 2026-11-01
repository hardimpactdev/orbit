import {
    expression as e,
    instanceActions,
} from "../instance-vp-command/native_steps.ts";
import { routeMutationSteps } from "../routes/mutation-command/steps.ts";
type Fields = Record<string, unknown>;
export function destroySteps(): Fields[] {
    const intent = "inputs.intent", ssh = intent + ".ssh_execution";
    const a = instanceActions("destroy", ssh), o = a.output;
    a.sshAction("directory", "exec", {
        argv: ["/usr/bin/mkdir", "-p", e('"/tmp/orbit-instance-" + run.id')],
    });
    a.script("observe", "inspect_source", {
        member: e("self.member"),
        force: e(intent + ".force"),
        repository: e(intent + ".repository"),
    }, { forEach: { item: "member", in: e(intent + ".members") } });
    // Asset selection is itself per member, so observations remain associated with the admitted ID.
    a.steps.find((s) => s.name === "destroy-observe-asset")!.forEach = {
        item: "member",
        in: e(intent + ".members"),
    };
    const observe = a.steps.find((s) => s.name === "destroy-observe")!;
    (observe.task as Fields).inputs = {
        hosts: "name:target",
        interpreter: "python3",
        script: e(
            'steps["destroy-observe-asset-" + string(self._index)].outputs.script_body',
        ),
    };
    observe.dependsOn = [{
        step: "destroy-observe-asset",
        condition: { type: "succeeded" },
    }];
    a.metadata(
        "observed",
        "decode",
        {
            observation: e(
                'steps["destroy-observe-"+string(self._index)].outputs',
            ),
        },
        "@nckrtl/instance-dev-command",
        { forEach: { item: "member", in: e(intent + ".members") } },
    );
    a.metadata(
        "origin-assets",
        "read",
        {
            action: "origin",
            input: {
                checkout: e("self.member.checkout_path"),
                allow_empty: e("self.member.failed_creation"),
            },
        },
        "@nckrtl/instance-source-command",
        { forEach: { item: "member", in: e(intent + ".members") } },
    );
    a.sshAction("origin", "script", {
        interpreter: "python3",
        script: e(
            'steps["destroy-origin-assets-"+string(self._index)].outputs.script_body',
        ),
    }, {
        forEach: { item: "member", in: e(intent + ".members") },
        guard: e(
            '!steps["destroy-observed-"+string(self._index)].outputs.origin_required',
        ),
    });
    a.metadata(
        "inspect",
        "inspect",
        { input: e(intent), steps: e("steps") },
        "@nckrtl/instance-dev-command",
        {
            dependsOn: [{
                step: "destroy-origin",
                condition: { type: "always" },
            }, { step: "destroy-observed", condition: { type: "succeeded" } }],
        },
    );
    a.metadata(
        "source-assets",
        "paths",
        { intent: e(intent) },
        "@nckrtl/instance-source-command",
    );
    a.add("mint", {
        type: "behavior",
        behaviorType: "command/shell",
        behaviorName: e('"id-mint-" + run.id'),
        methodName: "execute",
        inputs: {
            run: 'python3 -B "$ORBIT_MINT_FILE"',
            env: {
                ORBIT_MINT_FILE: e(
                    o("source-assets") + '.directory + "/mint.py"',
                ),
                ORBIT_OPERATION_ID: e("string(inputs.operation_id)"),
                ORBIT_RUN_ID: e("run.id"),
                ORBIT_GIT_REFERENCES: e(
                    "inputs.intent.execution_references_json",
                ),
            },
        },
    });
    a.sshAction("fetch-files", "copy", {
        src: e(o("source-assets") + '.directory + "/."'),
        dst: e('"/tmp/orbit-instance-" + run.id + "/"'),
        direction: "to",
        recursive: true,
    });
    a.sshAction("fetch", "exec", {
        argv: [
            "/usr/bin/python3",
            "-B",
            e('"/tmp/orbit-instance-" + run.id + "/fetch.py"'),
            e("self.member.checkout_path"),
            e(intent + ".repository"),
        ],
        stdin: e(
            "vault.get(inputs.execution_secrets.git_read.vault,inputs.execution_secrets.git_read.key)",
        ),
    }, {
        forEach: { item: "member", in: e(intent + ".members") },
        guard: e(intent + ".force || self.member.failed_creation"),
    });
    a.script("verify", "verify_source", {
        member: e("self.member"),
        capture: e(
            o("inspect") +
                ".members.filter(row,row.instance_id == self.member.instance_id)[0]",
        ),
        force: e(intent + ".force"),
    }, {
        forEach: { item: "member", in: e(intent + ".members") },
        dependsOn: [{
            step: "destroy-verify-asset",
            condition: { type: "succeeded" },
        }],
    });
    a.steps.find((s) => s.name === "destroy-verify-asset")!.dependsOn = [{
        step: "destroy-inspect",
        condition: { type: "succeeded" },
    }, {
        step: "destroy-fetch",
        condition: {
            type: "or",
            conditions: [{ type: "succeeded" }, { type: "skipped" }],
        },
    }];
    a.steps.find((s) => s.name === "destroy-verify-asset")!.forEach = {
        item: "member",
        in: e(intent + ".members"),
    };
    (a.steps.find((s) => s.name === "destroy-verify")!.task as Fields).inputs =
        {
            hosts: "name:target",
            interpreter: "python3",
            script: e(
                'steps["destroy-verify-asset-" + string(self._index)].outputs.script_body',
            ),
        };
    a.metadata("verified", "require_actions", {
        names: ["destroy-verify"],
        counts: [e("size(" + intent + ".members)")],
        steps: e("steps"),
    });
    a.steps.at(-1)!.dependsOn = [{
        step: "destroy-verify",
        condition: { type: "always" },
    }, { step: "destroy-inspect", condition: { type: "succeeded" } }];
    const common = {
        operation_id: e("inputs.operation_id"),
        node_id: e("inputs.node_id"),
    };
    a.add("schedules", {
        type: "workflow",
        workflowIdOrName: "operation-schedule-destroy",
        inputs: {
            ...common,
            kind: "schedule.destroy",
            intent: {
                schedule: { id: e("self.schedule.id") },
                cascade: true,
                ssh_execution: e(ssh),
            },
            execution_secrets: {},
        },
    }, {
        forEach: {
            item: "schedule",
            in: e(intent + ".direct_destroy.runtime.schedules"),
        },
    });
    a.add("processes", {
        type: "workflow",
        workflowIdOrName: "operation-process-destroy",
        inputs: {
            ...common,
            kind: "process.destroy",
            intent: { control: e("self.process"), ssh_execution: e(ssh) },
            execution_secrets: {},
        },
    }, {
        forEach: {
            item: "process",
            in: e(intent + ".native_runtime.processes"),
        },
    });
    a.steps.at(-1)!.dependsOn = [{
        step: "destroy-schedules",
        condition: {
            type: "or",
            conditions: [{ type: "succeeded" }, { type: "skipped" }],
        },
    }, { step: "destroy-verified", condition: { type: "succeeded" } }];
    a.metadata("runtime-ready", "require_actions", {
        names: ["destroy-schedules", "destroy-processes"],
        counts: [
            e("size(" + intent + ".direct_destroy.runtime.schedules)"),
            e("size(" + intent + ".native_runtime.processes)"),
        ],
        steps: e("steps"),
    }, "@nckrtl/instance-dev-command");
    a.steps.at(-1)!.dependsOn = [
        { step: "destroy-schedules", condition: { type: "always" } },
        { step: "destroy-processes", condition: { type: "always" } },
        { step: "destroy-verified", condition: { type: "succeeded" } },
    ];
    a.metadata("step-assets", "read", {
        action: "run_project_step",
        input: {},
    });
    a.sshAction("step-copy", "copy", {
        src: e(o("step-assets") + ".script_file"),
        dst: e('"/tmp/orbit-instance-" + run.id + "/run_project_step.py"'),
        direction: "to",
    });
    for (let index = 0; index < 32; index++) {
        a.add("teardown-" + index, {
            type: "workflow",
            workflowIdOrName: "instance-project-command",
            inputs: {
                operation_id: e("inputs.operation_id"),
                node_id: e("inputs.node_id"),
                kind: e("inputs.kind"),
                intent: {
                    ssh_execution: e(ssh),
                    script: e(
                        '"/tmp/orbit-instance-"+run.id+"/run_project_step.py"',
                    ),
                    timeout_seconds: e(
                        "size(" + intent + ".teardown_commands)>" + index +
                            "?" + intent + ".teardown_commands[" + index +
                            "].timeout_seconds:60",
                    ),
                    reference: e(
                        "size(" + intent + ".teardown_commands)>" + index +
                            "?inputs.execution_secrets[" + intent +
                            ".teardown_commands[" + index +
                            '].secret_field]:inputs.execution_secrets.git_read',
                    ),
                },
            },
        }, {
            guard: e(
                "size(" + intent + ".teardown_commands)<=" + index +
                    ' || steps.exists(key,steps[key].status=="failed")',
            ),
        });
        a.steps.at(-1)!.dependsOn = [{
            step: index
                ? "destroy-teardown-" + (index - 1)
                : "destroy-step-copy",
            condition: {
                type: "or",
                conditions: [{ type: "succeeded" }, { type: "skipped" }],
            },
        }];
    }
    a.metadata("teardown-ready", "require_actions", {
        names: e(
            intent +
                '.teardown_commands.map(command,"destroy-teardown-"+string(command.index))',
        ),
        counts: e(intent + ".teardown_commands.map(command,1)"),
        steps: e("steps"),
    });
    a.steps.at(-1)!.dependsOn = [
        ...a.steps.filter((step) =>
            /^destroy-teardown-[0-9]+$/.test(String(step.name))
        ).map((step) => ({ step: step.name, condition: { type: "always" } })),
        { step: "destroy-runtime-ready", condition: { type: "succeeded" } },
    ];
    a.add("sqlite", {
        type: "workflow",
        workflowIdOrName: "database-owned-sqlite-remove",
        inputs: {
            ...common,
            kind: "database.destroy_owned",
            intent: {
                connection_id: e("self.database.connection_id"),
                node_id: e("self.database.node_id"),
                checkout: e("self.database.checkout"),
                path: e("self.database.path"),
                ssh: {
                    host: e(ssh + ".target.host"),
                    user: e(ssh + ".target.user"),
                    host_keys: e(ssh + ".target.host_keys"),
                },
            },
        },
    }, {
        forEach: {
            item: "database",
            in: e(intent + ".direct_destroy.database.sqlite"),
        },
    });
    a.metadata("database-ready", "require_actions", {
        names: ["destroy-sqlite"],
        counts: [e("size(" + intent + ".direct_destroy.database.sqlite)")],
        steps: e("steps"),
    });
    a.steps.at(-1)!.dependsOn = [{
        step: "destroy-sqlite",
        condition: { type: "always" },
    }, { step: "destroy-teardown-ready", condition: { type: "succeeded" } }];
    const route = routeMutationSteps(
        "withdraw",
        intent + ".route_mutations",
        "forward",
        undefined,
        "run.id",
        true,
    );
    for (const step of route) {
        step.dependsOn = [...(step.dependsOn as Fields[] ?? []), {
            step: "destroy-database-ready",
            condition: { type: "succeeded" },
        }];
    }
    const end = instanceActions("remove", ssh, "withdraw");
    end.script("source", "finalize_source", {
        member: e("self.member"),
        capture: e(
            o("inspect") +
                ".members.filter(row,row.instance_id == self.member.instance_id)[0]",
        ),
        removal_id: e(intent + ".removal_id"),
    }, {
        forEach: { item: "member", in: e(intent + ".members") },
        dependsOn: [{
            step: "remove-source-asset",
            condition: { type: "succeeded" },
        }],
    });
    end.steps[0].forEach = { item: "member", in: e(intent + ".members") };
    (end.steps[1].task as Fields).inputs = {
        hosts: "name:target",
        interpreter: "python3",
        script: e(
            'steps["remove-source-asset-" + string(self._index)].outputs.script_body',
        ),
    };
    end.metadata(
        "result",
        "destroy_result",
        { intent: e(intent), steps: e("steps") },
        "@nckrtl/instance-dev-command",
        {
            dependsOn: [...a.steps, ...route, ...end.steps].map((s) => ({
                step: s.name,
                condition: { type: "always" },
            })),
        },
    );
    const steps = [...a.steps, ...route, ...end.steps];
    for (const step of steps) {
        const task = step.task as Fields;
        if (
            step.forEach && task.type === "behavior" &&
            typeof task.behaviorName === "string"
        ) {
            task.behaviorName = task.behaviorName.replace(
                /\s*}}$/,
                ' + "-" + string(self._index) }}',
            );
        }
    }
    return steps;
}
