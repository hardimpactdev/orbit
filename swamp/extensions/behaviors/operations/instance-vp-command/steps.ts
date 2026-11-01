import { expression as e, instanceActions } from "./native_steps.ts";
type Fields = Record<string, unknown>;
/** The stock CLI owns runtime download, registry configuration and package-manager store locks. */
export function vpCaptureSteps(
    name: string,
    intent: string,
): Fields[] {
    const a = instanceActions(
            name,
            intent + ".ssh_execution",
            undefined,
            "@nckrtl/instance-vp-assets",
        ),
        o = a.output;
    const file = '"/tmp/orbit-instance-" + run.id + "/project.json"';
    a.add("local-directory", {
        type: "behavior",
        behaviorType: "command/shell",
        behaviorName: e('"vp-local-"+run.id'),
        methodName: "execute",
        inputs: {
            run: 'umask 077; mkdir -p -- "$ORBIT_DIRECTORY"',
            env: { ORBIT_DIRECTORY: e('"/tmp/orbit-instance-"+run.id') },
        },
    });
    a.script("project", "project_material", {
        application: e(intent + ".application"),
        destination: e(file),
    });
    a.sshAction("private-copy", "copy", {
        src: e(file),
        dst: e(file),
        direction: "from",
    });
    a.metadata(
        "requirements",
        "capture",
        { path: e(file) },
        "@nckrtl/instance-vp-requirements",
    );
    return a.steps;
}
export function vpInstallSteps(name: string, intent: string): Fields[] {
    const a = instanceActions(name, intent + ".ssh_execution"), o = a.output;
    const runtime = "self.runtime";
    const rows = o("requirements") + ".runtime == null ? [] : [" +
        o("requirements") + ".runtime]";
    const selected = { forEach: { item: "runtime", in: e(rows) } };
    const setup = {
        forEach: {
            item: "runtime",
            in: e(
                o("requirements") + ".manager_required ? (" + rows + ") : []",
            ),
        },
    };
    a.metadata(
        "manager-assets",
        "read",
        { setup: e(intent + ".vp_setup") },
        "@nckrtl/node-tool-setup-assets",
        setup,
    );
    a.sshAction("manager", "script", {
        interpreter: "python3",
        script: e(
            'steps["' + name + '-manager-assets-0"].outputs.manager_body',
        ),
        sudo: true,
    }, setup);
    a.add("manager-ready", {
        type: "assert",
        expr: "!(" + o("requirements") + ".manager_required && " +
            o("requirements") + '.runtime != null) || ("' + name +
            '-manager-0" in steps && steps["' + name +
            '-manager-0"].status == "succeeded")',
        severity: "high",
        message: "The admitted VP manager was not prepared.",
    }, {
        dependsOn: a.steps.map((step) => ({
            step: step.name,
            condition: { type: "always" },
        })),
    });
    const env = { VP_HOME: e(runtime + ".store") },
        binary = e(runtime + '.store+"/bin/vp"');
    a.sshAction("cli", "exec", { argv: [binary, "--version"], env }, selected);
    a.metadata(
        "cli-ready",
        "cli",
        {
            runtime: e(runtime),
            observation: e('steps["' + name + '-cli-0"].outputs'),
        },
        "@nckrtl/instance-vp-requirements",
        selected,
    );
    const selector = runtime + '.package_manager+"@"+' + runtime +
        ".package_manager_version+(" + runtime +
        '.package_manager_hash==null?"":"+"+' + runtime +
        ".package_manager_hash)";
    a.sshAction("install", "exec", {
        argv: [
            binary,
            "-C",
            e(intent + ".application"),
            "env",
            "install",
            e('"node@"+' + runtime + ".node_version"),
            e(selector),
        ],
        env,
    }, selected);
    a.sshAction("inventory", "exec", {
        argv: [
            binary,
            "-C",
            e(intent + ".application"),
            "env",
            "list",
            "--json",
        ],
        env,
    }, selected);
    a.metadata(
        "verify",
        "verify",
        {
            runtime: e(runtime),
            inventory: e('steps["' + name + '-inventory-0"].outputs'),
        },
        "@nckrtl/instance-vp-requirements",
        selected,
    );
    a.metadata(
        "result",
        "result",
        { name, steps: e("steps") },
        "@nckrtl/instance-vp-requirements",
        {
            dependsOn: a.steps.map((step) => ({
                step: step.name,
                condition: { type: "always" },
            })),
        },
    );
    return a.steps;
}

/** Two native jobs because e459 expands fan-outs from prior-job outputs. */
export function vpPreparationJobs(
    name: string,
    intent: string,
    dependencyJob: string,
): Fields[] {
    return [{
        name: name + "-capture",
        target: "orbit-node-209",
        dependsOn: [{ job: dependencyJob, condition: { type: "succeeded" } }],
        steps: vpCaptureSteps(name, intent),
    }, {
        name: name + "-install",
        target: "orbit-node-209",
        dependsOn: [{
            job: name + "-capture",
            condition: { type: "succeeded" },
        }],
        steps: vpInstallSteps(name, intent),
    }];
}
