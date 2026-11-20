import { z } from "npm:zod@4.4.3";
import {
    CheckInput,
    checkParts,
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
import { readPublicTail } from "../../_lib/public_output.ts";

/*
 * Typed data for tasks.check and its scheduled CI observations. Instance
 * workspace phases run the commands and download their output. `local` reads,
 * redacts and deletes each file, so raw command output never enters native history.
 */

const ObjectValue = z.record(z.string(), z.unknown());
const OUTPUT_BYTES = 16384;
const Sha = /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/;
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

/**
 * `git write-tree` and `git rev-parse HEAD` of one snapshot, from its
 * `snapshot-<phase>-tree` and `snapshot-<phase>-head` steps.
 */
function snapshot(steps: Steps, phase: string): { head: string; tree: string } {
    const value = (name: string) => {
        const item = run(steps, `snapshot-${phase}-${name}`);
        const oid = (item.stdout ?? "").trim();
        if (item.exitCode !== 0 || !Sha.test(oid)) {
            throw new Error(`Snapshot ${phase} ${name} is unavailable`);
        }
        return oid;
    };
    return { head: value("head"), tree: value("tree") };
}

/** A repository-relative file: no leading `/` and no empty, `.` or `..` segment. */
const relativeFile = (path: string) =>
    path !== "" && !path.startsWith("/") &&
    path.split("/").every((part) => !["", ".", ".."].includes(part));
/** A working directory inside the checkout: relative, with no `..` segment. */
const relativeDirectory = (path: string) =>
    !path.startsWith("/") && !path.split("/").includes("..");
/**
 * Validate the admitted check target and deliverable paths before the Instance phase runs any command.
 */
export function prepare(raw: unknown, nodeId: number) {
    const input = CheckInput.parse(raw);
    if (
        input.node_id !== nodeId ||
        input.branch !== `task-${input.group_id}-handoff`
    ) throw new Error("Wrong check target");
    const commands = input.deliverables.filter((item) =>
        item.type === "command"
    );
    const valid = commands.every((item) =>
        relativeDirectory(item.directory) && item.paths.every(relativeFile) &&
        (!item.fails_on_base || Sha.test(input.base))
    );
    return {
        valid,
        error_code: valid ? "" : "tasks.deliverable_invalid",
    };
}

type Command = { exit_code?: number; output?: string };

/**
 * One command step's exit code and the redacted tail of its downloaded output
 * file in `local`, or nothing when it did not run. A ran command whose output
 * file is missing refuses the check.
 */
async function commandResult(
    steps: Steps,
    name: string,
    local: string,
): Promise<Command> {
    const item = run(steps, name);
    if (typeof item.exitCode !== "number") return {};
    const output = await readPublicTail(`${local}/${name}`, OUTPUT_BYTES);
    if (output === null) throw new MissingOutput(name);
    return { exit_code: item.exitCode, output: output.output };
}

class MissingOutput extends Error {}

const encoder = new TextEncoder();
const bytes = (text: string) => encoder.encode(text).length;
/** The last `limit` bytes of `text`, cut at a character boundary. */
function tailBytes(text: string, limit: number) {
    const encoded = encoder.encode(text);
    if (encoded.length <= limit) return text;
    let start = encoded.length - Math.max(limit, 0);
    while (start < encoded.length && (encoded[start] & 0xc0) === 0x80) start++;
    return new TextDecoder().decode(encoded.subarray(start));
}

/** The line that ends every check output; it states the check's outcome. */
export function outcomeLine(outcome: string, cause = "") {
    return outcome === "passed"
        ? "Task check PASSED"
        : outcome === "changed"
        ? "Task check CHANGED"
        : "Task check FAILED" + (cause ? `: ${cause}` : "");
}

/**
 * The check's stored output (docs/reference/tasks.md#stored-result): the
 * redacted setup and Project output, then a `Deliverables` block with each
 * deliverable's outcome, its command's exit code, its failures and, for a
 * failed command, the last lines of its redacted output, then the outcome
 * line. The older Project output gives way so the whole stays within
 * OUTPUT_BYTES; a block too large to fit drops the output tails.
 */
export function checkOutput(
    project: string,
    deliverables: { id: string; outcome: string; failures: string[] }[],
    commands: Record<string, Command>,
    line: string,
    tails = true,
): string {
    const block = deliverables.length ? ["Deliverables"] : [];
    for (const item of deliverables) {
        const run = commands[item.id];
        block.push(
            `  ${item.id}: ${item.outcome}` +
                (run?.exit_code === undefined ? "" : `, exit ${run.exit_code}`),
            ...item.failures.map((failure) => `    ${failure}`),
        );
        const tail = tailBytes((run?.output ?? "").trimEnd(), 1024)
            .split("\n").filter((row, index) => index > 0 || row !== "");
        if (
            tails && item.outcome === "failed" && run?.exit_code && tail.length
        ) {
            block.push(
                `    Last lines of ${item.id}:`,
                ...tail.map((row) => `      ${row}`),
            );
        }
    }
    const end = [...block, line].join("\n");
    const head = project && !project.endsWith("\n") ? project + "\n" : project;
    // Too many failed commands to fit drop their output tails, then the oldest block lines.
    if (bytes(end) >= OUTPUT_BYTES) {
        return tails
            ? checkOutput(project, deliverables, commands, line, false)
            : tailBytes(end, OUTPUT_BYTES);
    }
    return tailBytes(head, OUTPUT_BYTES - bytes(end)) + end;
}

/** `result` with `outcome`, the last line of its output restated to agree. */
function restated<T extends Record<string, unknown>>(
    result: T,
    outcome: string,
    cause = "",
): T & { outcome: string } {
    return {
        ...result,
        outcome,
        ...(typeof result.output === "string"
            ? {
                output: tailBytes(
                    result.output.replace(
                        /[^\n]*$/,
                        outcomeLine(outcome, cause),
                    ),
                    OUTPUT_BYTES,
                ),
            }
            : {}),
    };
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

/** Whether ordered setup passed, from exit codes only; output is read once in `local`. */
export function setupResult(input: Input, steps: Steps) {
    const failed = input.setup.map((item, index) => ({
        name: item.name,
        exit_code: run(steps, "setup-" + index).exitCode,
    })).find((item) => item.exit_code !== 0);
    return {
        passed: !failed,
        exit_code: failed ? (failed.exit_code ?? 255) : 0,
        failed_step: failed?.name ?? null,
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

/**
 * The part table of the delivery receipt that the Project check wrote, as the guest validated it field by field and removed the file:
 * each part's redacted name, its result and its seconds; null without a usable table.
 */
export function receiptParts(text: string | undefined) {
    try {
        return checkParts(JSON.parse(text ?? ""));
    } catch {
        return null;
    }
}

/**
 * The local check evidence. `outputs` is the private worker directory that
 * holds the downloaded command output files; each is deleted after it is read.
 */
export async function localResult(
    input: Input,
    prepared: Record<string, unknown>,
    steps: Steps,
    outputs: string,
) {
    // A part table the Project check left is kept whatever fails after it.
    const parts = receiptParts(run(steps, "parts").stdout) ?? checkParts(prepared.parts);
    if (prepared.error_code) {
        return {
            error_code: String(prepared.error_code),
            check_id: input.check_id,
            parts,
        };
    }
    const refusal = stepRefusal(input, steps);
    if (refusal) return { error_code: refusal, check_id: input.check_id, parts };
    const before = snapshot(steps, "before"),
        after = snapshot(steps, "after"),
        setup = setupResult(input, steps);
    const read = (name: string) => commandResult(steps, name, outputs);
    const commandItems = input.deliverables.filter((item) =>
        item.type === "command"
    );
    let setupOutput = "", project: Command;
    const pairs: [typeof commandItems[number], Command, Command][] = [];
    try {
        for (const index of input.setup.keys()) {
            setupOutput += (await read("setup-" + index)).output ?? "";
        }
        project = input.command && setup.passed
            ? await read("project")
            : { exit_code: setup.exit_code, output: "" };
        for (const [index, item] of commandItems.entries()) {
            pairs.push([
                item,
                await read("deliverable-" + index),
                await read("base-" + index),
            ]);
        }
    } catch (error) {
        if (!(error instanceof MissingOutput)) throw error;
        return {
            error_code: "tasks.repository_execution_unknown",
            check_id: input.check_id,
            parts,
        };
    }
    const commands = Object.fromEntries(
        pairs.flatMap(([item, current, base]) => {
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
    // The work as it stood when the phase started, before any command. At the delivery gate a setup or Project command that moved it
    // moved the workspace too, so the check never pins a tree other than the one handed off (docs/reference/tasks.md#the-delivery-gate).
    // A baseline or an ungated check keeps the post-Project snapshot: its formatters' edits belong to the checked tree.
    const started = {
        head: (run(steps, "original").stdout ?? "").trim(),
        tree: (run(steps, "handed-tree").stdout ?? "").trim(),
    };
    const moved = input.handoff_gate &&
        ((started.head !== "" && started.head !== before.head) || (started.tree !== "" && started.tree !== before.tree));
    const changed = before.head !== after.head || before.tree !== after.tree || moved;
    const local = Local.parse({
        outcome: changed
            ? "changed"
            : project.exit_code === 0
            ? "passed"
            : "failed",
        exit_code: project.exit_code,
        output: "",
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
        subtask_start_commit: input.base ||
            (run(steps, "original").stdout ?? "").trim(),
        parts,
        handed_off_head: started.head || undefined,
        handed_off_tree: started.tree || undefined,
    });
    const deliverables = deliverableResults(input, local);
    const failed = local.outcome === "passed"
        ? deliverables.filter((item) =>
            ["failed", "not_run"].includes(item.outcome)
        ).map((item) => item.id)
        : [];
    const outcome = failed.length ? "failed" : local.outcome;
    return {
        ...local,
        outcome,
        output: checkOutput(
            setupOutput + (project.output ?? ""),
            deliverables,
            commands,
            outcomeLine(
                outcome,
                failed.length
                    ? `deliverable${failed.length > 1 ? "s" : ""} ${
                        failed.join(", ")
                    } did not pass`
                    : "",
            ),
        ),
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
 * A copy check makes no commit for a clean head that an
 * earlier check recorded, and reads no parent: that head, with the checked
 * tree, is its own candidate.
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
    const recorded = commit === local.head;
    if (
        !Sha.test(commit) || tree !== local.tree ||
        (!recorded && parent !== local.head)
    ) {
        return restated(local, "changed");
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
        ...restated(
            local,
            read.fault === "change" ? "failed" : "passed",
            "CI failed",
        ),
        pending: !terminal,
        ci,
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
        : { ...value, result: restated(result, "changed") };
}

/**
 * The base of a copy's handoff check (docs/reference/tasks.md#the-handoff-check-merges-main-first): the default head it judged
 * against and the head it merged, or `unavailable` with the reason when the base phase failed. `requested` is false for a baseline,
 * which states no base.
 */
export function baseFields(
    base: Record<string, unknown>,
): Record<string, unknown> {
    if (base.requested !== true) return {};
    if (base.status !== "succeeded") {
        const read = ObjectValue.parse(base.evidence ?? {});
        const evidence = ObjectValue.parse(read.evidence ?? {});
        return {
            base: "unavailable",
            base_reason: typeof evidence.error_code === "string"
                ? evidence.error_code
                : "tasks.workspace_phase_failed",
            default_commit: null,
            merged_default: null,
        };
    }
    const merge = ObjectValue.parse(base.merge ?? {});
    const fields = {
        // HEAD and the tree of the work the implementer handed off, before the base-merge (docs/reference/tasks.md#handoffs).
        ...(typeof merge.handed_head === "string" ? { handed_off_head: merge.handed_head } : {}),
        ...(typeof merge.handed_tree === "string" ? { handed_off_tree: merge.handed_tree } : {}),
        base: "current",
        default_commit: typeof merge.default_commit === "string"
            ? merge.default_commit
            : null,
        merged_default: typeof merge.merged_default === "string"
            ? merge.merged_default
            : null,
    };
    return merge.error_code === "tasks.check_conflict"
        ? {
            ...fields,
            conflicting_paths: merge.conflicting_paths,
            conflicting_more: merge.conflicting_more,
        }
        : fields;
}

export function finish(args: Record<string, unknown>) {
    const input = CheckInput.parse(args.intent),
        local: Record<string, unknown> = {
            ...ObjectValue.parse(args.local),
            ...baseFields(ObjectValue.parse(args.base ?? {})),
        };
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
    version: "2026.11.16.1",
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
                return output(ctx, { stdout: JSON.stringify({ checks }) });
            },
        },
        prepare: {
            description:
                "Validate public check intent and deliverable paths",
            arguments: z.object({
                intent: ObjectValue,
                node_id: z.number().int().positive(),
            }),
            execute: (
                args: { intent: unknown; node_id: number },
                ctx: Context,
            ) => output(ctx, prepare(args.intent, args.node_id)),
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
                        },
                ),
        },
        local: {
            description:
                "Read, redact and delete the downloaded command output and shape local check and deliverable evidence",
            arguments: ObjectValue,
            execute: async (args: Record<string, unknown>, ctx: Context) => {
                const result = await localResult(
                    CheckInput.parse(args.intent),
                    ObjectValue.parse(args.prepared),
                    ObjectValue.parse(args.steps),
                    z.string().startsWith("/").parse(args.outputs),
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
