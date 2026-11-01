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
    first.metadata("phase-assets", "create_assets", { intent: e(intent) });
    first.sshAction("phase-copy", "copy", {
        src: e(first.output("phase-assets") + ".directory"),
        dst: e('"/tmp/orbit-create-phase-" + run.id'),
        direction: "to",
        recursive: true,
    });
    first.sshAction("prepare", "exec", {
        okExitCodes: "any",
        argv: [
            "/usr/bin/timeout",
            "--kill-after=1s",
            e("string(" + intent + ".phase_plan.prepare.seconds) + 's'"),
            "/usr/bin/python3",
            "-B",
            e('"/tmp/orbit-create-phase-" + run.id + "/create/prepare.py"'),
            e(first.output("phase-assets") + ".intent_json"),
        ],
    });
    first.add("prepare-accepted", {
        type: "assert",
        expr: 'steps["create-prepare"].outputs.exitCode == 0',
        severity: "high",
        message: "Instance preparation refused.",
    });
    first.sshAction("source", "exec", {
        okExitCodes: "any",
        argv: [
            "/usr/bin/timeout",
            "--kill-after=1s",
            e("string(" + intent + ".phase_plan.source.seconds) + 's'"),
            "/usr/bin/python3",
            "-B",
            e('"/tmp/orbit-create-phase-" + run.id + "/create/source.py"'),
            e(first.output("phase-assets") + ".intent_json"),
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
    source.add("accepted", {
        type: "assert",
        expr: 'steps["source-result"].outputs.ready == true',
        severity: "high",
        message: "Instance source refused.",
    });
    const env = instanceActions("env", ssh, "source-accepted");
    env.metadata("capture", "capture", {
        template: e('steps["source-result"].outputs.template'),
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
    env.sshAction("write", "exec", {
        argv: [
            "/usr/bin/timeout",
            "--kill-after=1s",
            e("string(" + intent + ".phase_plan.environment.seconds) + 's'"),
            "/usr/bin/python3",
            "-B",
            e('"/tmp/orbit-create-phase-" + run.id + "/create/environment.py"'),
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
            "/usr/bin/timeout",
            "--kill-after=1s",
            e("string(" + intent + ".phase_plan.setup.seconds + 1) + 's'"),
            "/usr/bin/python3",
            "-B",
            e('"/tmp/orbit-create-phase-"+run.id+"/create/setup.py"'),
            e('steps["vp-result"].outputs.runtime == null ? "" : steps["vp-result"].outputs.runtime.store'),
        ],
        okExitCodes: "any",
        timeoutSec: e(intent + ".phase_plan.setup.seconds + 2"),
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
