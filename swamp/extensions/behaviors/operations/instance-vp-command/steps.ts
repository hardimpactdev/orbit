import { expression as e, instanceActions } from "./native_steps.ts";
type Fields = Record<string, unknown>;
/** Manifest files the requirement selection reads; each is one read-only official SSH exec. */
export const VpManifests = {
    package: "package.json",
    pnpm_lock: "pnpm-lock.yaml",
    npm_lock: "package-lock.json",
    node_version: ".node-version",
    nvmrc: ".nvmrc",
} as const;
/** One read: a missing regular file prints nothing and still exits 0, so absent is a result, not a failure. */
export function manifestRead(directory: string, file: string) {
    return '["find", ' + directory +
        ', "-maxdepth", "1", "-type", "f", "-name", "' + file +
        '", "-exec", "cat", "--", "{}", "+"]';
}
/** Reads the manifests and the VP store on the Node; selection stays on 209. */
export function vpCaptureSteps(
    name: string,
    intent: string,
): Fields[] {
    const a = instanceActions(name, intent + ".ssh_execution");
    for (const [key, file] of Object.entries(VpManifests)) {
        a.sshAction("read-" + key, "exec", {
            argv: e(manifestRead(intent + ".application", file)),
        });
    }
    a.sshAction("home", "exec", { argv: ["realpath", "-s", "--", "."] });
    // Descend only along the three candidate store paths and prune everything else, so find never
    // reads another directory, such as a root-only one under /opt, and exits 0 when it finds none.
    const stores = [
        "/opt/orbit/vite-plus/bin/vp",
        "./.vite-plus/bin/vp",
        "./.local/share/vite-plus/bin/vp",
    ];
    const ancestors = [
        ...new Set(stores.flatMap((path) => {
            const parts = path.split("/");
            return parts.slice(1, -1).map((_, index) =>
                parts.slice(0, index + 2).join("/") || "/"
            );
        })),
    ].filter((path) => path !== "/");
    const any = (paths: string[]) =>
        paths.flatMap((
            path,
            index,
        ) => [...(index ? ["-o"] : []), "-path", path]);
    a.sshAction("store", "exec", {
        argv: [
            "find",
            "/opt",
            ".",
            "-type",
            "f",
            "(",
            ...any(stores),
            ")",
            "-exec",
            "realpath",
            "-s",
            "--",
            "{}",
            "+",
            "-o",
            "(",
            ...any([".", ...ancestors]),
            ")",
            "-o",
            "-prune",
        ],
    });
    a.metadata(
        "links",
        "links",
        {
            application: e(intent + ".application"),
            package: e(a.output("read-package") + ".stdout"),
            pnpm_lock: e(a.output("read-pnpm_lock") + ".stdout"),
        },
        "@nckrtl/instance-vp-requirements",
    );
    return a.steps;
}
/** Linked workspace manifests fan out from the capture job's output, then the selection runs. */
export function vpWorkspaceSteps(name: string, intent: string): Fields[] {
    const a = instanceActions(name, intent + ".ssh_execution");
    const capture = (suffix: string) =>
        'steps["' + name + "-" + suffix + '"].outputs.stdout';
    a.sshAction("read-workspace", "exec", {
        argv: e(manifestRead("self.link.directory", "package.json")),
    }, {
        forEach: {
            item: "link",
            in: e('steps["' + name + '-links"].outputs.links'),
        },
    });
    a.metadata(
        "requirements",
        "capture",
        {
            ...Object.fromEntries(
                Object.keys(VpManifests).map((
                    key,
                ) => [key, e(capture("read-" + key))]),
            ),
            home: e(capture("home")),
            store: e(capture("store")),
            links: e('steps["' + name + '-links"].outputs.links'),
            workspaces: e(
                'steps.filter(k, k.startsWith("' + name +
                    '-read-workspace-")).map(k, {"step": k, "stdout": steps[k].outputs.stdout})',
            ),
        },
        "@nckrtl/instance-vp-requirements",
        // An empty fan-out skips the read step; a failed read leaves its link unresolved and the selection refuses.
        {
            dependsOn: [{
                step: name + "-read-workspace",
                condition: { type: "always" },
            }],
        },
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
    a.add("manager", {
        type: "workflow",
        workflowIdOrName: "operation-tool-manager-setup",
        inputs: {
            operation_id: e("inputs.operation_id"),
            node_id: e("inputs.node_id"),
            ssh: e(intent + ".ssh_execution"),
            intent: {
                manager: "vp",
                user: e(intent + ".vp_setup.input.user"),
                platform: "linux",
                architecture: "x86_64",
                setup: e(intent + ".vp_setup"),
            },
        },
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

/** Three native jobs because e459 expands fan-outs from prior-job outputs. */
export function vpPreparationJobs(
    name: string,
    intent: string,
    dependencyJob: string,
): Fields[] {
    return [{
        name: name + "-capture",
        labels: { role: "swamp" },
        dependsOn: [{ job: dependencyJob, condition: { type: "succeeded" } }],
        steps: vpCaptureSteps(name, intent),
    }, {
        name: name + "-workspaces",
        labels: { role: "swamp" },
        dependsOn: [{
            job: name + "-capture",
            condition: { type: "succeeded" },
        }],
        steps: vpWorkspaceSteps(name, intent),
    }, {
        name: name + "-install",
        labels: { role: "swamp" },
        dependsOn: [{
            job: name + "-workspaces",
            condition: { type: "succeeded" },
        }],
        steps: vpInstallSteps(name, intent),
    }];
}
