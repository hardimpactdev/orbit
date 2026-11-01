type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
/** Compose one-action native source steps; callers supply public intent and private Git stdin. */
export function sourceSteps(
    name: string,
    intent: string,
    gitRead: string,
): Fields[] {
    const steps: Fields[] = [];
    const outputs = (step: string) => `steps["${name}-${step}"].outputs`;
    const seeded =
        `${intent}.source.seed_repository != null && ${intent}.resolve.seed_commit != null`;
    const retained = `${intent}.retained_source != null`;
    const created = `${outputs("prepared")}.created`;
    const ssh = `${intent}.ssh_execution`;
    const globals = {
        name: expr(`"${name}-" + run.id`),
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
    function add(suffix: string, task: Fields, guard?: string) {
        steps.push({
            name: `${name}-${suffix}`,
            target: "orbit-node-209",
            ...(steps.length
                ? {
                    dependsOn: [{
                        step: steps.at(-1)!.name,
                        condition: {
                            type: "or",
                            conditions: [{ type: "succeeded" }, {
                                type: "skipped",
                            }],
                        },
                    }],
                }
                : {}),
            guard: expr(
                `steps.exists(key, steps[key].status == "failed")${
                    guard ? " || (" + guard + ")" : ""
                }`,
            ),
            task,
        });
    }
    function typed(suffix: string, method: string, inputs: Fields) {
        add(suffix, {
            type: "behavior",
            behaviorType: "@nckrtl/instance-source-command",
            behaviorName: expr(`"src-${steps.length}-" + run.id`),
            methodName: method,
            inputs,
        });
    }
    function sshAction(
        suffix: string,
        argv: unknown[],
        guard?: string,
        extra: Fields = {},
    ) {
        add(suffix, {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expr(`"src-${steps.length}-" + run.id`),
            methodName: "exec",
            globalArgs: globals,
            inputs: { hosts: "name:target", argv, ...extra },
        }, guard);
    }
    function script(
        suffix: string,
        action: string,
        input: unknown = expr(intent),
    ) {
        typed(suffix + "-assets", "read", { action, input });
        add(suffix + "-read", {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: expr(`"src-${steps.length}-" + run.id`),
            methodName: "script",
            globalArgs: globals,
            inputs: {
                hosts: "name:target",
                interpreter: "python3",
                script: expr(`${outputs(suffix + "-assets")}.script_body`),
            },
        });
    }
    const git = [
        "/usr/bin/git",
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "-C",
        expr(`${intent}.source.checkout`),
    ];
    sshAction("worktrees", [
        "/usr/bin/git",
        "-c",
        "core.hooksPath=/dev/null",
        "-C",
        expr(
            `${intent}.source.seed_repository == null ? ${intent}.source.checkout : ${intent}.source.seed_repository`,
        ),
        "worktree",
        "list",
        "--porcelain",
    ], `!(${seeded}) || (${retained})`);
    typed("branch-available", "branchAvailable", {
        resolve: expr(`${intent}.resolve`),
        checkout: expr(`${intent}.source.checkout`),
        worktrees: expr(
            `${seeded} && !(${retained}) ? ${
                outputs("worktrees")
            } : {"stdout":""}`,
        ),
    });
    add("branch-accepted", {
        type: "assert",
        expr: `${outputs("branch-available")}.available`,
        severity: "high",
        message: "instance.branch_in_use",
    });
    script("prepare", "prepare_directory");
    typed("prepared", "decode", { observation: expr(outputs("prepare-read")) });
    sshAction(
        "init",
        [...git, "init", "--quiet"],
        `!${created} || (${seeded})`,
    );
    sshAction("worktree", [
        "/usr/bin/git",
        "-c",
        "core.hooksPath=/dev/null",
        "-C",
        expr(
            `${intent}.source.seed_repository == null ? ${intent}.source.checkout : ${intent}.source.seed_repository`,
        ),
        "worktree",
        "add",
        "--no-checkout",
        "--detach",
        "--",
        expr(`${intent}.source.checkout`),
        expr(
            `${intent}.resolve.seed_commit == null ? "HEAD" : ${intent}.resolve.seed_commit`,
        ),
    ], `!${created} || !(${seeded})`);
    // Existing checkout identity is checked before fetching or selecting a branch.
    script("origin", "origin", {
        checkout: expr(`${intent}.source.checkout`),
        allow_empty: expr(`${outputs("prepared")}.owned`),
    });
    typed("origin-proof", "origin", {
        expected: expr(`${intent}.source.repository`),
        observation: expr(outputs("origin-read")),
        allow_empty: expr(`${outputs("prepared")}.owned`),
    });
    sshAction("origin-configure", [
        ...git,
        "config",
        "remote.origin.url",
        expr(`${intent}.source.repository`),
    ], `${outputs("origin-read")}.stdout != ""`);
    sshAction("git-directory", [...git, "rev-parse", "--absolute-git-dir"]);
    script("marker", "mark_source", {
        checkout: expr(`${intent}.source.checkout`),
        prepare_id: expr(`${intent}.source.prepare_id`),
        git_directory: expr(`${outputs("git-directory")}.stdout`),
    });
    typed("paths", "paths", { intent: expr(intent) });
    const temporary = expr(`"/tmp/orbit-source-" + run.id + "-${name}"`);
    sshAction("scripts-directory", ["/usr/bin/mkdir", "-p", temporary]);
    add("scripts-copy", {
        type: "behavior",
        behaviorType: "@swamp/ssh",
        behaviorName: expr(`"${name}-copy-" + run.id`),
        methodName: "copy",
        globalArgs: globals,
        inputs: {
            hosts: "name:target",
            src: expr(`${outputs("paths")}.directory`),
            dst: expr(`"/tmp/orbit-source-" + run.id + "-${name}/"`),
            recursive: true,
            direction: "to",
        },
    });
    sshAction(
        "fetch",
        [
            "/usr/bin/python3",
            "-B",
            expr(`"/tmp/orbit-source-" + run.id + "-${name}/scripts/fetch.py"`),
            expr(`${intent}.source.checkout`),
            expr(`${intent}.source.repository`),
        ],
        `!${intent}.resolve.network || (${retained})`,
        {
            stdin: expr(gitRead),
        },
    );
    sshAction("refs", [
        ...git,
        "for-each-ref",
        "--format=%(refname)",
        "refs/heads",
        "refs/remotes/origin",
    ]);
    typed("selected", "select", {
        resolve: expr(`${intent}.resolve`),
        refs: expr(outputs("refs")),
    });
    sshAction("checkout", [
        ...git,
        "checkout",
        "--quiet",
        "--no-track",
        "-B",
        expr(`${outputs("selected")}.branch`),
        expr(`${outputs("selected")}.reference`),
    ], retained);
    sshAction("commit", [...git, "rev-parse", "--verify", "HEAD^{commit}"]);
    script("profile", "profile");
    typed("result", "provisioningResult", {
        intent: expr(intent),
        profile: expr(outputs("profile-read")),
        branch: expr(`${outputs("selected")}.branch`),
        commit: expr(outputs("commit")),
    });
    return steps;
}
