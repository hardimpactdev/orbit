import { z } from "npm:zod@4.4.3";
import {
    CheckInput,
    classifyCi,
    deliverableResults,
    Local,
} from "../tasks/check.ts";
import {
    imported,
    pushed,
    pushTarget,
    type Run,
    run,
} from "../tasks/publish.ts";
import { lifecycleArgv } from "./lifecycle.ts";
import { programOutput, snapshot } from "./program.ts";

/*
 * Typed data for tasks.check and its scheduled CI observations. Every task VM
 * step is one official `@swamp/ssh exec` argv; this module builds those argv
 * lists on Node 209 and shapes their public outputs. It runs nothing.
 */

const ObjectValue = z.record(z.string(), z.unknown());
const Sha = /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/;
const Account = z.object({
    ssh_execution: z.object({
        target: z.object({ user: z.string().min(1) }).passthrough(),
    }).passthrough(),
}).passthrough();
type Input = z.infer<typeof CheckInput>;
type Steps = Record<string, unknown>;
type Context = {
    writeResource(
        spec: string,
        name: string,
        value: unknown,
        options?: { tags: Record<string, string> },
    ): Promise<unknown>;
};
const output = async (ctx: Context, value: unknown) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});

/** A repository-relative file: no leading `/` and no empty, `.` or `..` segment. */
const relativeFile = (path: string) =>
    path !== "" && !path.startsWith("/") &&
    path.split("/").every((part) => !["", ".", ".."].includes(part));
/** A working directory inside the checkout: relative, with no `..` segment. */
const relativeDirectory = (path: string) =>
    !path.startsWith("/") && !path.split("/").includes("..");
const within = (root: string, directory: string) =>
    directory === "." || directory === "" ? root : `${root}/${directory}`;

/**
 * The argv lists of every check step, from the admitted intent. `root` is the
 * run's scratch directory on the VM; it holds the snapshot index and the
 * start-commit trees. Invalid deliverable paths refuse before any command.
 */
export function prepare(raw: unknown, nodeId: number, root: string) {
    const input = CheckInput.parse(raw);
    if (
        input.node_id !== nodeId ||
        input.branch !== `task-${input.group_id}-handoff`
    ) throw new Error("Wrong check target");
    const user = Account.parse(raw).ssh_execution.target.user;
    const environment = input.ssr_port === null ? [] : [
        "INERTIA_SSR_PORT=" + input.ssr_port,
        "INERTIA_SSR_URL=http://127.0.0.1:" + input.ssr_port,
    ];
    const command = (directory: string, source: string) =>
        lifecycleArgv(directory, user, source, environment);
    const commands = input.deliverables.filter((item) =>
        item.type === "command"
    );
    const valid = commands.every((item) =>
        relativeDirectory(item.directory) && item.paths.every(relativeFile) &&
        (!item.fails_on_base || Sha.test(input.base))
    );
    const workspace = input.checkout_path;
    return {
        valid,
        error_code: valid ? "" : "tasks.deliverable_invalid",
        setup: input.setup.map((step) => command(workspace, step.command)),
        // Skipped by its guard when the Project has no check command.
        project: command(workspace, input.command ?? "true"),
        deliverables: commands.map((item) =>
            command(within(workspace, item.directory), item.command)
        ),
        // The start-commit run of a `fails_on_base` command: an extracted
        // archive of the base commit, the installed `vendor` and
        // `node_modules` directories, and only the declared test paths.
        bases: commands.map((item, index) => {
            const tree = `${root}/base-${index}`, archive = `${tree}.tar`;
            return {
                archive: [
                    "git",
                    "-C",
                    workspace,
                    "archive",
                    "--format=tar",
                    `--prefix=base-${index}/`,
                    `--output=${archive}`,
                    input.base,
                ],
                extract: ["tar", "-x", "-f", archive, "-C", root],
                dependencies: [
                    "env",
                    "-C",
                    workspace,
                    "find",
                    ".",
                    "-name",
                    ".git",
                    "-prune",
                    "-o",
                    "(",
                    "-name",
                    "vendor",
                    "-o",
                    "-name",
                    "node_modules",
                    ")",
                    "-type",
                    "d",
                    "-prune",
                    "-exec",
                    "cp",
                    "-a",
                    "--parents",
                    "-t",
                    tree,
                    "{}",
                    "+",
                ],
                overlay: item.paths.length
                    ? [
                        "env",
                        "-C",
                        workspace,
                        "cp",
                        "-p",
                        "--parents",
                        "-t",
                        tree,
                        "--",
                        ...item.paths,
                    ]
                    : ["true"],
                directory: ["test", "-d", within(tree, item.directory)],
                run: command(within(tree, item.directory), item.command),
            };
        }),
    };
}

/** One command step's exit code and redacted output, or nothing when it did not run. */
function commandResult(item: Run): { exit_code?: number; output?: string } {
    return typeof item.exitCode === "number"
        ? { exit_code: item.exitCode, output: programOutput(item) }
        : {};
}

/** `git diff-tree -r --no-renames --name-status -z` rows, or null when the diff did not run. */
export function diffRows(item: Run) {
    if (item.exitCode !== 0) return null;
    const fields = (item.stdout ?? "").split("\0");
    const rows: { status: string; path: string }[] = [];
    for (let index = 0; index + 1 < fields.length; index += 2) {
        rows.push({
            status: fields[index].slice(0, 1),
            path: fields[index + 1],
        });
    }
    return rows;
}

export function setupResult(input: Input, steps: Steps) {
    const observations = input.setup.map((item, index) => ({
        name: item.name,
        ...commandResult(run(steps, "setup-" + index)),
    }));
    const failed = observations.find((item) => item.exit_code !== 0);
    return {
        passed: !failed,
        exit_code: failed ? (failed.exit_code ?? 255) : 0,
        failed_step: failed?.name ?? null,
        output: observations.map((item) => item.output ?? "").join("").slice(
            -16384,
        ),
    };
}

const status = (steps: Steps, name: string) =>
    ObjectValue.safeParse(steps[name]).data?.status;

/**
 * The refusal the check steps record, if any: a start-commit overlay or
 * working directory that is missing, or any step that failed natively.
 */
function stepRefusal(input: Input, steps: Steps): string | null {
    const commands = input.deliverables.filter((item) =>
        item.type === "command"
    );
    for (const index of commands.keys()) {
        if (
            ["base_overlay", "base_directory"].some((name) => {
                const code = run(steps, `${name}-${index}`).exitCode;
                return typeof code === "number" && code !== 0;
            })
        ) return "tasks.deliverable_invalid";
    }
    return Object.keys(steps).some((name) => status(steps, name) === "failed")
        ? "tasks.repository_execution_unknown"
        : null;
}

export function localResult(
    input: Input,
    prepared: Record<string, unknown>,
    steps: Steps,
) {
    if (prepared.error_code) {
        return {
            error_code: String(prepared.error_code),
            check_id: input.check_id,
        };
    }
    const refusal = stepRefusal(input, steps);
    if (refusal) return { error_code: refusal, check_id: input.check_id };
    const before = snapshot(steps, "before"),
        after = snapshot(steps, "after"),
        setup = setupResult(input, steps);
    const project: { exit_code?: number; output?: string } =
        input.command && setup.passed
            ? commandResult(run(steps, "project"))
            : { exit_code: setup.exit_code, output: "" };
    const commandItems = input.deliverables.filter((item) =>
        item.type === "command"
    );
    const commands = Object.fromEntries(
        commandItems.flatMap((item, index) => {
            const current = commandResult(run(steps, "deliverable-" + index)),
                base = commandResult(run(steps, "base-" + index));
            if (current.exit_code === undefined) return [];
            return [[item.id, {
                ...current,
                ...(item.fails_on_base
                    ? {
                        base_started: base.exit_code !== undefined,
                        base_exit_code: base.exit_code,
                        base_output: base.output,
                    }
                    : {}),
            }]];
        }),
    );
    const changed = before.head !== after.head || before.tree !== after.tree;
    const local = Local.parse({
        outcome: changed
            ? "changed"
            : project.exit_code === 0
            ? "passed"
            : "failed",
        exit_code: project.exit_code,
        output: (setup.output + (project.output ?? "")).slice(-16384),
        head_before: before.head,
        tree_before: before.tree,
        head: after.head,
        tree: after.tree,
        changed_paths: (diffRows(run(steps, "changed")) ?? []).map((row) =>
            row.path
        ),
        failed_step: setup.failed_step,
        evidence: input.deliverables.length
            ? {
                start: input.base || null,
                diff: diffRows(run(steps, "diff")),
                commands,
            }
            : null,
        subtask_start_commit: (run(steps, "original").stdout ?? "").trim(),
    });
    const deliverables = deliverableResults(input, local);
    return {
        ...local,
        outcome: local.outcome === "passed" &&
                deliverables.some((item) => item.outcome === "failed")
            ? "failed"
            : local.outcome,
        check_id: input.check_id,
        group_id: input.group_id,
        subtask: input.subtask,
        phase: input.phase,
        deliverables,
        commit: null,
        ci: null,
    };
}

/**
 * A passed handoff names the commit that `git commit` made, read back with
 * `git rev-parse HEAD HEAD^{tree} HEAD^`. Its tree and parent must be the
 * checked snapshot; otherwise the workspace moved and the check is `changed`.
 */
export function candidateResult(
    input: Input,
    local: Record<string, unknown>,
    read: Run,
) {
    if (
        typeof local.error_code === "string" || local.outcome !== "passed" ||
        input.phase !== "handoff"
    ) return local;
    if (read.exitCode !== 0) {
        return { ...local, error_code: "tasks.repository_execution_unknown" };
    }
    const [commit = "", tree, parent] = (read.stdout ?? "").trim().split("\n");
    if (!Sha.test(commit) || tree !== local.tree || parent !== local.head) {
        return { ...local, outcome: "changed" };
    }
    return { ...local, commit };
}
export function ciResult(
    pending: Record<string, unknown>,
    reading: Record<string, unknown>,
    observedAt: string,
) {
    const inputs = ObjectValue.parse(pending.inputs),
        input = CheckInput.parse(inputs.intent),
        local = ObjectValue.parse(pending.result);
    const commit = z.string().parse(local.commit);
    const read = reading.error_code
        ? { state: "unreadable", fault: "infrastructure", runs: [], suites: [] }
        : classifyCi(
            z.array(z.unknown()).parse(reading.runs),
            [],
            commit,
            input.check_name!,
        );
    const ci = {
        ...read,
        repository: input.repository,
        branch: input.branch,
        commit,
        check_name: input.check_name,
        pushed_at: pending.pushed_at,
        observed_at: observedAt,
    };
    const missingExpired = read.state === "missing" &&
        Date.parse(observedAt) - Date.parse(String(pending.pushed_at)) >=
            600000;
    const expired =
        Date.parse(observedAt) - Date.parse(String(pending.pushed_at)) >=
            55 * 60 * 1000;
    const terminal = read.state === "passed" || read.fault !== null ||
        missingExpired || expired;
    const error_code = reading.error_code ??
        (expired
            ? "tasks.handoff_ci_timeout"
            : missingExpired || (read.fault && read.fault !== "change")
            ? "tasks.handoff_ci_unavailable"
            : null);
    const result = {
        ...local,
        pending: !terminal,
        ci,
        outcome: read.fault === "change" ? "failed" : "passed",
        failed_step: read.fault === "change" ? "ci" : local.failed_step,
        ...(error_code ? { error_code } : {}),
    };
    return {
        ...pending,
        pending: !terminal,
        result,
        status: error_code ? "failed" : "succeeded",
    };
}

/** JSON with sorted keys, so two descriptor maps compare by content. */
function canonical(value: unknown): string {
    if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
    if (value !== null && typeof value === "object") {
        return `{${
            Object.keys(value).sort().map((key) =>
                `${JSON.stringify(key)}:${
                    canonical((value as Record<string, unknown>)[key])
                }`
            ).join(",")
        }}`;
    }
    return JSON.stringify(value);
}

/** The Gateway mint reply names exactly the admitted vault descriptors, never a token. */
export function minted(item: Run, expected: string) {
    if (typeof item.exitCode !== "number") {
        return { minted: false, error_code: "" };
    }
    let received: unknown;
    try {
        received = z.object({
            data: z.object({ execution_secrets: ObjectValue }).passthrough(),
        }).passthrough().parse(JSON.parse(item.stdout ?? "")).data
            .execution_secrets;
    } catch {
        received = undefined;
    }
    return item.exitCode === 0 && received !== undefined &&
            canonical(received) === canonical(JSON.parse(expected))
        ? { minted: true, error_code: "" }
        : { minted: false, error_code: "tasks.handoff_ci_unavailable" };
}

const CheckRun = z.object({
    id: z.number().int(),
    name: z.string(),
    head_sha: z.string(),
    status: z.string(),
    conclusion: z.string().nullable(),
    app: z.object({ slug: z.string() }).passthrough(),
}).passthrough();

/** One bounded GitHub Checks read: the latest runs, without private fields, or unavailable. */
export function checkRuns(item: Run) {
    const reply = z.object({
        total_count: z.number().int(),
        check_runs: z.array(CheckRun),
    }).passthrough().safeParse(
        (() => {
            try {
                return JSON.parse(item.stdout ?? "");
            } catch {
                return undefined;
            }
        })(),
    );
    if (
        item.exitCode !== 0 || !reply.success ||
        reply.data.total_count > 100 ||
        reply.data.check_runs.length !== reply.data.total_count
    ) return { error_code: "tasks.handoff_ci_unavailable" };
    return {
        runs: reply.data.check_runs.map((row) => ({
            id: row.id,
            name: row.name.slice(0, 256),
            head_sha: row.head_sha,
            status: row.status,
            conclusion: row.conclusion,
            app: { slug: row.app.slug },
        })),
    };
}

/**
 * A settled CI observation still names the VM candidate only while HEAD and
 * the whole working tree equal it; the snapshot runs as steps
 * `snapshot-<operation>-index|add|tree|head`.
 */
export function observed(
    value: Record<string, unknown>,
    steps: Steps,
    operation: string,
) {
    const result = ObjectValue.parse(value.result);
    if (value.pending || value.status !== "succeeded") return value;
    let current;
    try {
        current = snapshot(steps, operation);
    } catch {
        return {
            ...value,
            status: "failed",
            result: { ...result, error_code: "tasks.check_execution_unknown" },
        };
    }
    return current.head === result.commit && current.tree === result.tree
        ? value
        : { ...value, result: { ...result, outcome: "changed" } };
}

export function finish(args: Record<string, unknown>) {
    const input = CheckInput.parse(args.intent),
        local = ObjectValue.parse(args.local);
    const steps = ObjectValue.parse(args.steps);
    if (
        typeof local.error_code === "string" || local.outcome !== "passed" ||
        input.phase !== "handoff" || input.check_name === null
    ) {
        return {
            result: local,
            pending: false,
            minted: false,
            result_json: JSON.stringify(local),
        };
    }
    const mint = minted(
            run(steps, "mint_github"),
            String(ObjectValue.parse(args.intent).execution_references_json),
        ),
        push = pushed(run(steps, "push")),
        bundle = imported(
            run(steps, "import"),
            String(local.commit),
            String(local.tree),
        );
    let error_code = mint.error_code || push.error_code || bundle.error_code ||
        undefined;
    // The import already compared the bundled HEAD and tree to the candidate.
    if (!error_code && typeof local.commit !== "string") {
        error_code = "tasks.head_moved";
    }
    if (!error_code && push.pushed !== true) error_code = "tasks.push_failed";
    const pushed_at = new Date().toISOString();
    const result = {
        ...local,
        ...(error_code ? { error_code } : {}),
        ci: {
            repository: input.repository,
            state: "pending",
            fault: null,
            branch: input.branch,
            commit: local.commit,
            check_name: input.check_name,
            pushed_at,
        },
    };
    return {
        result,
        pending: !error_code,
        pushed_at,
        minted: mint.minted,
        result_json: JSON.stringify({ ...result, pending: !error_code }),
    };
}

/** Public check data on Node 209; commands, Git and HTTP are placed steps. */
export const behavior = {
    type: "@nckrtl/task-check-data",
    version: "2026.11.02.2",
    resources: {
        public: {
            description: "Public check observations",
            schema: ObjectValue,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        pending: {
            description:
                "Admitted CI check state for native scheduled observations",
            schema: ObjectValue,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        collect: {
            description: "Collect this tick's public terminal results",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => {
                const checks = Object.entries(ObjectValue.parse(args.steps))
                    .filter(([name]) => name.startsWith("observed-")).map((
                        [, step],
                    ) => ObjectValue.parse(ObjectValue.parse(step).outputs))
                    .map((item) => {
                        const inputs = ObjectValue.parse(item.inputs);
                        return {
                            operation_id: inputs.operation_id,
                            node_id: inputs.node_id,
                            kind: inputs.kind,
                            root_run_id: item.root_run_id,
                            status: item.status,
                            result: item.result,
                        };
                    });
                return output(ctx, { result_json: JSON.stringify({ checks }) });
            },
        },
        prepare: {
            description:
                "Validate public check intent and build every check step's SSH exec argv",
            arguments: z.object({
                intent: ObjectValue,
                node_id: z.number().int().positive(),
                root: z.string().startsWith("/"),
            }),
            execute: (
                args: { intent: unknown; node_id: number; root: string },
                ctx: Context,
            ) => output(ctx, prepare(args.intent, args.node_id, args.root)),
        },
        snapshot: {
            description: "Read one workspace snapshot's HEAD and tree",
            arguments: z.object({ phase: z.string(), steps: ObjectValue }),
            execute: (args: { phase: string; steps: Steps }, ctx: Context) =>
                output(ctx, snapshot(args.steps, args.phase)),
        },
        imported: {
            description: "Compare the imported bundle to the checked candidate",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => {
                const local = ObjectValue.parse(args.local);
                return output(
                    ctx,
                    imported(
                        run(ObjectValue.parse(args.steps), "import"),
                        String(local.commit),
                        String(local.tree),
                    ),
                );
            },
        },
        minted: {
            description:
                "Compare the Gateway mint reply to the admitted descriptors",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    minted(
                        run(ObjectValue.parse(args.steps), "mint_github"),
                        String(args.expected),
                    ),
                ),
        },
        setup: {
            description: "Summarize ordered setup observations",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    ObjectValue.parse(args.prepared).valid === true
                        ? setupResult(
                            CheckInput.parse(args.intent),
                            ObjectValue.parse(args.steps),
                        )
                        : {
                            passed: false,
                            exit_code: 255,
                            failed_step: "validation",
                            output: "",
                        },
                ),
        },
        local: {
            description: "Shape local check and deliverable evidence",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => {
                const result = localResult(
                    CheckInput.parse(args.intent),
                    ObjectValue.parse(args.prepared),
                    ObjectValue.parse(args.steps),
                );
                return output(ctx, {
                    result,
                    result_json: JSON.stringify(result),
                });
            },
        },
        candidate: {
            description:
                "Attach the exact committed candidate to the checked evidence",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => {
                const input = CheckInput.parse(args.intent);
                const result = candidateResult(
                    input,
                    ObjectValue.parse(args.local),
                    run(ObjectValue.parse(args.steps), "commit_read"),
                );
                return output(ctx, {
                    result,
                    result_json: JSON.stringify(result),
                    ...(typeof result.commit === "string" &&
                            input.repository !== null
                        ? pushTarget(
                            input.repository,
                            result.commit,
                            input.branch,
                        )
                        : { remote: "", refspec: "", object_format: "" }),
                });
            },
        },
        finish: {
            description: "Shape local completion or pending CI admission",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(ctx, finish(args)),
        },
        pending: {
            description:
                "Record a public pending check in native data for the scheduled workflow",
            arguments: ObjectValue,
            execute: async (args: Record<string, unknown>, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource("pending", "pending", {
                        ...args,
                        input_json: JSON.stringify(args),
                        inputs_json: JSON.stringify(
                            ObjectValue.parse(args.inputs).intent,
                        ),
                    }, { tags: { "task_check": "pending" } }),
                ],
            }),
        },
        observed: {
            description: "Compare terminal CI against the current VM candidate",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    observed(
                        ObjectValue.parse(args.value),
                        ObjectValue.parse(args.steps),
                        String(args.operation_id),
                    ),
                ),
        },
        ci: {
            description: "Classify one bounded exact-SHA CI observation",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                output(
                    ctx,
                    ciResult(
                        ObjectValue.parse(args.pending),
                        checkRuns(
                            run({ read: { outputs: args.read } }, "read"),
                        ),
                        new Date().toISOString(),
                    ),
                ),
        },
        settle: {
            description: "Retain terminal check evidence in native data",
            arguments: ObjectValue,
            execute: async (args: Record<string, unknown>, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "pending",
                        "pending",
                        (() => {
                            const value = ObjectValue.parse(args.value);
                            const { input_json: _, ...publicValue } = value;
                            return {
                                ...publicValue,
                                input_json: JSON.stringify(publicValue),
                            };
                        })(),
                        {
                            tags: {
                                "task_check":
                                    ObjectValue.parse(args.value).pending
                                        ? "pending"
                                        : "settled",
                            },
                        },
                    ),
                ],
            }),
        },
    },
};
