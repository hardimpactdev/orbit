type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
/** One native transfer graph. Recovery is explicit, and physical cutover survives retirement failure. */
export function transferWorkflow(): Fields {
    const steps: Fields[] = [];
    const failed = 'steps.exists(n, steps[n].status == "failed")';
    const forwardOk =
        '("forward" in steps) && steps["forward"].status == "succeeded" && steps.forward.outputs.result.ready';
    const restored =
        '("restore" in steps) && steps["restore"].status == "succeeded" && steps.restore.outputs.result.ready';
    const outputs = (name: string) => `steps["${name}"].outputs`;
    const common = {
        operation_id: expr("inputs.operation_id"),
        node_id: expr("inputs.node_id"),
        kind: expr("inputs.kind"),
    };
    function previous(): Fields[] {
        let index = steps.length - 1;
        while (index > 0 && "forEach" in steps[index]) index--;
        return steps.slice(Math.max(index, 0)).map((step) => ({
            step: step.name,
            condition: { type: "always" },
        }));
    }
    function add(
        name: string,
        task: Fields,
        guard = failed,
        extra: Fields = {},
    ) {
        steps.push({
            name,
            target: "orbit-node-209",
            dependsOn: previous(),
            ...(guard ? { guard: expr(guard) } : {}),
            ...extra,
            task,
        });
    }
    const identity = () => expr('"itr-" + run.id + "-' + steps.length + '"');
    function typed(
        name: string,
        method: string,
        inputs: Fields,
        guard = failed,
    ) {
        add(name, {
            type: "behavior",
            behaviorType: "@nckrtl/instance-source-command",
            behaviorName: identity(),
            methodName: method,
            inputs,
        }, guard);
    }
    const connections: Record<string, Fields> = {};
    function globals(side: string): Fields {
        if (connections[side]) return connections[side];
        const ssh = `inputs.intent.${side}.ssh_execution`;
        return connections[side] = {
            name: expr('"itr-' + side + '-" + run.id'),
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
    function ssh(
        name: string,
        side: string,
        method: string,
        input: Fields,
        guard = failed,
    ) {
        add(name, {
            type: "behavior",
            behaviorType: "@swamp/ssh",
            behaviorName: identity(),
            methodName: method,
            globalArgs: globals(side),
            inputs: { hosts: "name:target", ...input },
        }, guard);
    }
    function script(
        name: string,
        side: string,
        action: string,
        input: Fields | string,
        guard = failed,
    ) {
        typed(name + "-assets", "read", { action, input }, guard);
        ssh(name, side, "script", {
            interpreter: "python3",
            script: expr(`${outputs(name + "-assets")}.script_body`),
        }, guard);
    }
    function process(
        name: string,
        side: string,
        action: "create" | "stop" | "destroy",
        guard = failed,
    ) {
        const installing = action === "create";
        add(
            name,
            {
                type: "workflow",
                workflowIdOrName: "operation-process-" + action,
                inputs: {
                    ...common,
                    kind: "process." + action,
                    intent: expr(
                        installing
                            ? "self.member.intent"
                            : action === "stop"
                            ? "self.member.stop_intent"
                            : "self.member.control_intent",
                    ),
                    execution_secrets: installing
                        ? expr(
                            'self.member.secret_field in inputs.execution_secrets ? {"environment":inputs.execution_secrets[self.member.secret_field]} : {}',
                        )
                        : {},
                },
            },
            guard,
            {
                forEach: {
                    item: "member",
                    in: expr(`inputs.intent.processes.${side}`),
                },
            },
        );
    }
    const source = "inputs.intent.source.source";
    const dest = "inputs.intent.destination.destination";
    const sourceArchive = expr('"/tmp/orbit-transfer-" + run.id + ".tar"');
    const localArchive = expr(
        '"/tmp/orbit-transfer-" + run.id + ".download.tar"',
    );
    const destArchive = expr(
        '"/tmp/orbit-transfer-" + run.id + ".install.tar"',
    );
    script("destination-ready", "destination", "transfer_probe", {
        path: expr(dest),
        root: expr("inputs.intent.destination.root"),
        destination: true,
    });
    script("source-filesystem", "source", "transfer_probe", {
        path: expr(source),
        destination: false,
    });
    const git = [
        "/usr/bin/git",
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "-C",
        expr(source),
    ];
    for (
        const [name, argv] of Object.entries({
            top: ["rev-parse", "--show-toplevel"],
            "git-directory": ["rev-parse", "--absolute-git-dir"],
            common: ["rev-parse", "--path-format=absolute", "--git-common-dir"],
        })
    ) {
        ssh(name, "source", "exec", { argv: [...git, ...argv] });
    }
    script("origin", "source", "origin", { checkout: expr(source) });
    typed("source-proof", "transferSource", {
        identity: expr("inputs.intent.source.repository_identity"),
        path: expr(source),
        top: expr(outputs("top")),
        origin: expr(outputs("origin")),
        git_directory: expr(outputs("git-directory")),
        common: expr(outputs("common")),
        filesystem: expr(outputs("source-filesystem")),
    });
    process("pause", "source", "stop");
    script("archive", "source", "transfer_archive", {
        path: expr(source),
        common: expr(`${outputs("source-proof")}.common`),
        git_directory: expr(`${outputs("source-proof")}.git_directory`),
        archive: sourceArchive,
    });
    ssh("download", "source", "copy", {
        src: sourceArchive,
        dst: localArchive,
        direction: "from",
    });
    ssh("upload", "destination", "copy", {
        src: localArchive,
        dst: destArchive,
        direction: "to",
        mode: "0600",
        atomic: true,
    });
    script("install", "destination", "transfer_install", {
        path: expr(dest),
        archive: destArchive,
    });
    typed("destination-proof", "decode", {
        observation: expr(outputs("install")),
    });
    ssh("standalone", "destination", "exec", {
        argv: [
            "/usr/bin/git",
            "-C",
            expr(dest),
            "config",
            "core.bare",
            "false",
        ],
    });
    ssh("worktree-config", "destination", "exec", {
        argv: [
            "/usr/bin/git",
            "-C",
            expr(dest),
            "config",
            "--unset-all",
            "core.worktree",
        ],
        okExitCodes: [0, 5],
    });
    script("environment-permissions", "destination", "protect_environment", {
        application: expr(
            "inputs.intent.destination.application",
        ),
    });
    add(
        "workloads",
        {
            type: "workflow",
            workflowIdOrName: "instance-route-workload",
            inputs: {
                ...common,
                intent: expr("self.workload"),
                execution_secrets: {},
            },
        },
        failed,
        { forEach: { item: "workload", in: expr("inputs.intent.workloads") } },
    );
    process("activate", "destination", "create");
    for (const mode of ["forward", "restore"]) {
        add(
            mode,
            {
                type: "workflow",
                workflowIdOrName: "route-mutation-" + mode,
                inputs: {
                    ...common,
                    intent: expr("inputs.intent"),
                    execution_secrets: expr("inputs.execution_secrets"),
                    parent_run_id: expr("run.id"),
                },
            },
            mode === "forward"
                ? failed
                : `(${forwardOk}) || !("destination-proof" in steps) || steps["destination-proof"].status != "succeeded"`,
            { allowFailure: true },
        );
    }
    process("restore-source-runtime", "source", "create", forwardOk);
    process(
        "discard-destination-runtime",
        "destination",
        "destroy",
        `${forwardOk} || !("destination-proof" in steps) || steps["destination-proof"].status != "succeeded" || !(${restored}) || steps.exists(n,n.startsWith("restore-source-runtime-") && steps[n].status == "failed")`,
    );
    script(
        "discard",
        "destination",
        "transfer_remove",
        { proof: expr(outputs("destination-proof")) },
        `${forwardOk} || !("destination-proof" in steps) || steps["destination-proof"].status != "succeeded" || !(${restored}) || steps.exists(n, (n.startsWith("restore-source-runtime-") || n.startsWith("discard-destination-runtime-")) && steps[n].status == "failed")`,
    );
    typed("result", "transferResult", {
        cutover: expr(forwardOk),
        restored: expr(restored),
        transfer_id: expr("inputs.intent.transfer_id"),
        outcomes: expr("steps"),
    }, "");
    const retire =
        `!(${forwardOk}) || !("result" in steps) || steps.result.status != "succeeded"`;
    process("retire-runtime", "source", "destroy", retire);
    script(
        "retire-source",
        "source",
        "transfer_remove",
        {
            proof: expr(outputs("source-proof")),
        },
        `${retire} || steps.exists(n,n.startsWith("retire-runtime-") && steps[n].status == "failed")`,
    );
    ssh(
        "prune-worktree",
        "source",
        "exec",
        {
            argv: [
                "/usr/bin/git",
                "--git-dir",
                expr(`${outputs("source-proof")}.common`),
                "worktree",
                "prune",
            ],
        },
        `${retire} || ${outputs("source-proof")}.common == ${
            outputs("source-proof")
        }.git_directory`,
    );
    // Archive cleanup is a separate visible action on each participating host.
    ssh("remove-source-archive", "source", "exec", {
        argv: ["/usr/bin/rm", "-f", "--", sourceArchive],
    }, '! ("archive" in steps) || steps["archive"].status != "succeeded"');
    ssh("remove-destination-archive", "destination", "exec", {
        argv: ["/usr/bin/rm", "-f", "--", destArchive],
    }, '! ("upload" in steps) || steps["upload"].status != "succeeded"');
    add(
        "remove-local-archive",
        {
            type: "behavior",
            behaviorType: "command/shell",
            behaviorName: identity(),
            methodName: "execute",
            inputs: {
                run: 'rm -f -- "$ARCHIVE"',
                env: { ARCHIVE: localArchive },
            },
        },
        '! ("download" in steps) || steps["download"].status != "succeeded"',
    );
    typed("complete", "complete", {
        cutover: expr(forwardOk),
        outcomes: expr("steps"),
    }, "");
    return {
        id: "d58dd3f3-ed2f-44ad-bdce-128aa2399481",
        name: "operation-instance-transfer",
        description:
            "Move one captured development checkout through native209 SSH, Process and Route children.",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", enum: ["instance.transfer"] },
                intent: { type: "object" },
                execution_secrets: {
                    type: "object",
                    additionalProperties: {
                        type: "object",
                        properties: {
                            vault: {
                                type: "string",
                                enum: ["orbit-control-inputs"],
                            },
                            key: {
                                type: "string",
                                pattern: "^[A-Za-z0-9][A-Za-z0-9_.:-]{0,255}$",
                            },
                        },
                        required: ["vault", "key"],
                        additionalProperties: false,
                    },
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
        jobs: [{ name: "transfer", target: "orbit-node-209", steps }],
    };
}
