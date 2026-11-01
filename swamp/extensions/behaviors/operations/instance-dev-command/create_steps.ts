import { sourceSteps } from "../instance-source-command/workflow.ts";
import {
    expression as e,
    instanceActions,
} from "../instance-vp-command/native_steps.ts";
type Fields = Record<string, unknown>;
/** Complete admitted development create, with one native action per source/environment/setup effect. */
export function createPreparationSteps(): Fields[] {
    const intent = "inputs.intent", ssh = intent + ".ssh_execution";
    const first = instanceActions("create", ssh);
    first.add("local-directory", {
        type: "behavior",
        behaviorType: "command/shell",
        behaviorName: e('"ic-local-" + run.id'),
        methodName: "execute",
        inputs: {
            run: 'umask 077; mkdir -p -- "$ORBIT_DIRECTORY"',
            env: { ORBIT_DIRECTORY: e('"/tmp/orbit-instance-" + run.id') },
        },
    });
    // Only this mint primitive crosses back to Gateway. Full execution input is already admitted.
    first.metadata(
        "mint-assets",
        "paths",
        { intent: e(intent) },
        "@nckrtl/instance-source-command",
    );
    first.add("mint", {
        type: "behavior",
        behaviorType: "command/shell",
        behaviorName: e('"ic-mint-" + run.id'),
        methodName: "execute",
        inputs: {
            run: 'python3 -B "$ORBIT_MINT_FILE"',
            env: {
                ORBIT_MINT_FILE: e(
                    'steps["create-mint-assets"].outputs.directory + "/mint.py"',
                ),
                ORBIT_OPERATION_ID: e("string(inputs.operation_id)"),
                ORBIT_RUN_ID: e("run.id"),
                ORBIT_GIT_REFERENCES: e(
                    "inputs.intent.execution_references_json",
                ),
            },
        },
    });
    const source = sourceSteps(
        "source",
        intent,
        "vault.get(inputs.execution_secrets.git_read.vault,inputs.execution_secrets.git_read.key)",
    );
    source[0].dependsOn = [{
        step: "create-mint",
        condition: { type: "succeeded" },
    }];
    const env = instanceActions("env", ssh, "source-result"), o = env.output;
    const remote = '"/tmp/orbit-instance-" + run.id + "/template"';
    env.script("template", "template", {
        application: e(intent + ".application"),
        destination: e(remote),
    });
    env.sshAction("copy", "copy", {
        src: e(remote),
        dst: e(remote),
        direction: "from",
    });
    env.metadata("capture", "capture", {
        path: e(remote),
        env: e(
            "vault.get(inputs.execution_secrets.environment.vault,inputs.execution_secrets.environment.key)",
        ),
        profile: e('steps["source-result"].outputs'),
        reference: e(intent + ".environment_output"),
        directory: e(intent + ".application"),
    }, "@nckrtl/instance-create-environment");
    env.metadata("write-assets", "read", {
        action: "write_environment",
        input: {},
    });
    env.sshAction("scripts-copy", "copy", {
        src: e(o("write-assets") + ".script_file"),
        dst: e('"/tmp/orbit-instance-" + run.id + "/write_environment.py"'),
        direction: "to",
    });
    env.sshAction("write", "exec", {
        argv: [
            "/usr/bin/python3",
            "-B",
            e('"/tmp/orbit-instance-" + run.id + "/write_environment.py"'),
        ],
        stdin: e(
            "vault.get(inputs.intent.environment_output.vault,inputs.intent.environment_output.key)",
        ),
    });
    return [...first.steps, ...source, ...env.steps];
}
export function createCompletionSteps(): Fields[] {
    const intent = "inputs.intent", ssh = intent + ".ssh_execution";
    const end = instanceActions("finish", ssh);
    end.add("prepared", {
        type: "assert",
        expr:
            '"vp-result" in steps && steps["vp-result"].status == "succeeded" && steps["vp-result"].outputs.ready == true',
        severity: "high",
        message: "Instance preparation did not finish.",
    });
    end.metadata("setup-assets", "read", {
        action: "run_project_step",
        input: {},
    }, "@nckrtl/instance-dev-command");
    end.sshAction("setup-copy", "copy", {
        src: e(end.output("setup-assets") + ".script_file"),
        dst: e('"/tmp/orbit-instance-" + run.id + "/run_project_step.py"'),
        direction: "to",
    });
    for (let index = 0; index < 32; index++) {
        end.sshAction("setup-" + index, "exec", {
            argv: [
                "/usr/bin/python3",
                "-B",
                e('"/tmp/orbit-instance-"+run.id+"/run_project_step.py"'),
            ],
            timeoutSec: e(
                "size(" + intent + ".setup_commands)>" + index + "?" + intent +
                    ".setup_commands[" + index + "].timeout_seconds:60",
            ),
            stdin: e(
                "size(" + intent + ".setup_commands)>" + index +
                    "?vault.get(inputs.execution_secrets[" + intent +
                    ".setup_commands[" + index +
                    "].secret_field].vault,inputs.execution_secrets[" + intent +
                    ".setup_commands[" + index + '].secret_field].key):""',
            ),
        }, {
            guard: e(
                "size(" + intent + ".setup_commands)<=" + index +
                    ' || steps.exists(key,steps[key].status=="failed")',
            ),
        });
        end.steps.at(-1)!.dependsOn = [{
            step: index ? "finish-setup-" + (index - 1) : "finish-setup-copy",
            condition: {
                type: "or",
                conditions: [{ type: "succeeded" }, { type: "skipped" }],
            },
        }];
    }
    end.metadata(
        "result",
        "create_result",
        { intent: e(intent), steps: e("steps") },
        "@nckrtl/instance-dev-command",
        {
            dependsOn: end.steps.map((s) => ({
                step: s.name,
                condition: { type: "always" },
            })),
        },
    );
    return end.steps;
}
