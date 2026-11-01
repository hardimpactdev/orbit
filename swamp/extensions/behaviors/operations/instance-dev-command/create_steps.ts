import {
    expression as e,
    instanceActions,
} from "../instance-vp-command/native_steps.ts";
type Fields = Record<string, unknown>;
/** Legacy action groups; private environment capture stays an explicit file/vault boundary. */
export function createPreparationSteps(input = "inputs"): Fields[] {
    const intent = input + ".intent", ssh = intent + ".ssh_execution";
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
                ORBIT_OPERATION_ID: e("string(" + input + ".operation_id)"),
                ORBIT_RUN_ID: e("run.id"),
                ORBIT_GIT_REFERENCES: e(
                    intent + ".execution_references_json",
                ),
            },
        },
    });
    first.metadata("phase-assets", "create_assets", {});
    first.sshAction("prepare", "exec", {
        argv: [
            "/usr/bin/timeout",
            "--kill-after=1s",
            e("string(" + intent + ".phase_plan.prepare.seconds) + 's'"),
            "/usr/bin/python3",
            "-B",
            "-c",
            e(first.output("phase-assets") + ".prepare"),
            e("toJSON(" + intent + ")"),
        ],
    });
    first.sshAction("source", "exec", {
        argv: [
            "/usr/bin/timeout",
            "--kill-after=1s",
            e("string(" + intent + ".phase_plan.source.seconds) + 's'"),
            "/usr/bin/python3",
            "-B",
            "-c",
            e(first.output("phase-assets") + ".source"),
            e("toJSON(" + intent + ")"),
        ],
        stdin: e(
            "vault.get(" + input + ".execution_secrets.git_read.vault," +
                input + ".execution_secrets.git_read.key)",
        ),
    });
    const source = instanceActions("source", ssh, "create-source");
    source.metadata("result", "source_result", {
        intent: e(intent),
        observation: e(first.output("source")),
    });
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
        env: {
            ORBIT_ENVIRONMENT: e(
                "vault.get(" + input + ".execution_secrets.environment.vault," +
                    input + ".execution_secrets.environment.key)",
            ),
        },
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
            "vault.get(" + intent + ".environment_output.vault," + intent +
                ".environment_output.key)",
        ),
    });
    for (const step of [...first.steps, ...source.steps, ...env.steps]) {
        step.queueTimeout = 15;
    }
    return [...first.steps, ...source.steps, ...env.steps];
}
export function createCompletionSteps(input = "inputs"): Fields[] {
    const intent = input + ".intent", ssh = intent + ".ssh_execution";
    const end = instanceActions("finish", ssh);
    end.add("prepared", {
        type: "assert",
        expr:
            '"vp-result" in steps && steps["vp-result"].status == "succeeded" && steps["vp-result"].outputs.ready == true',
        severity: "high",
        message: "Instance preparation did not finish.",
    });
    end.metadata("setup-assets", "create_assets", {});
    end.sshAction("setup-copy", "copy", {
        src: e(end.output("setup-assets") + ".directory"),
        dst: e('"/tmp/orbit-create-phase-" + run.id'),
        direction: "to",
        recursive: true,
    });
    end.metadata(
        "environment-library",
        "environment_script",
        {},
        "@nckrtl/task-repository-assets",
    );
    end.sshAction("environment-library-copy", "copy", {
        src: e(end.output("environment-library") + ".script_file"),
        dst: e(
            '"/tmp/orbit-create-phase-" + run.id + "/scripts/project_environment.py"',
        ),
        direction: "to",
    });
    end.sshAction("setup", "exec", {
        argv: [
            "/usr/bin/python3",
            "-B",
            e('"/tmp/orbit-create-phase-"+run.id+"/create/setup.py"'),
        ],
        okExitCodes: "any",
        timeoutSec: e(intent + ".phase_plan.setup.seconds + 2"),
        env: {
            VP_HOME: e(
                'steps["vp-result"].outputs.runtime == null ? "" : steps["vp-result"].outputs.runtime.store',
            ),
        },
        stdin: e(
            "vault.get(" + input + ".execution_secrets.setup.vault," + input +
                ".execution_secrets.setup.key)",
        ),
    });
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
    for (const step of end.steps) step.queueTimeout = 15;
    return end.steps;
}
