import { z } from "npm:zod@4.4.3";
import { redactText } from "../../_lib/public_output.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/);
export const Snapshot = z.object({ head: Sha, tree: Sha }).strict();
export const Input = z.object({
    new_session: z.boolean(),
    group_id: Id,
    instance_id: Id,
    node_id: Id,
    subtask: Id,
    thread_id: Id,
    checkout_path: z.string().startsWith("/"),
    session_id: z.string().regex(/^[A-Za-z0-9_-]{1,128}$/),
    role: z.enum(["implementer", "reviewer", "consult"]),
    prompt: z.string().max(100000),
    model: z.string().startsWith("orbit-task-vm/"),
    effort: z.string().nullable(),
    pi_host: z.string().min(1),
    pi_port: z.number().int().min(1).max(65535),
    candidate: Sha.nullable(),
    deliverables: z.array(z.record(z.string(), z.unknown())),
});
export type TurnInput = z.infer<typeof Input>;
const Usage = z.object({
    input: z.number().nonnegative().optional(),
    output: z.number().nonnegative().optional(),
    cacheRead: z.number().nonnegative().optional(),
    cacheWrite: z.number().nonnegative().optional(),
    total: z.number().nonnegative().optional(),
    calls: z.number().nonnegative().optional(),
    peakContext: z.number().nonnegative().optional(),
    cost: z.number().nonnegative().nullable().optional(),
});
const State = z.object({
    state: z.enum(["idle", "working", "done", "failed"]),
    turnId: z.string().nullable(),
    usage: Usage.optional(),
    transcript: z.array(z.record(z.string(), z.unknown())).optional(),
});
/** The outcomes each role may end its turn with. */
const OUTCOMES = {
    implementer: ["ready_for_review", "blocked"],
    reviewer: ["approved", "changes_requested", "blocked"],
    consult: ["answered", "blocked"],
} as const;
const Text = z.string().max(16384);
const Receipt = z.object({
    thread: z.unknown().optional(),
    outcome: z.unknown().optional(),
    summary: Text.optional(),
    question: Text.optional(),
    deliverables: z.record(z.string(), Text).default({}),
    cause: Text.optional(),
    pull_request: z.object({
        summary: Text,
        changes: z.array(Text).max(100),
        breaking: z.array(Text).max(100),
    }).optional(),
});
type ReceiptValue = z.infer<typeof Receipt>;

/**
 * Checks the receipt the agent wrote against this turn. The agent writes the file itself, so
 * every rule lives here: a wrong shape, an outcome its role may not use, an empty summary, a
 * blocked receipt without a question, and another thread's receipt each give one fixed code.
 */
export function checkReceipt(
    value: unknown,
    input: Pick<TurnInput, "role" | "thread_id">,
): { receipt: ReceiptValue | null; error: string | null } {
    const parsed = Receipt.safeParse(value);
    const refuse = (error: string) => ({ receipt: null, error });
    if (!parsed.success) {
        return refuse("tasks.receipt_invalid");
    }
    const receipt = parsed.data;
    if (receipt.thread !== input.thread_id) {
        return refuse("tasks.receipt_thread_mismatch");
    }
    if (
        !(OUTCOMES[input.role] as readonly unknown[]).includes(receipt.outcome)
    ) {
        return refuse("tasks.receipt_outcome_invalid");
    }
    if (!receipt.summary?.trim()) {
        return refuse("tasks.receipt_summary_missing");
    }
    if (receipt.outcome === "blocked" && !receipt.question?.trim()) {
        return refuse("tasks.receipt_question_missing");
    }
    return { receipt, error: null };
}

/** The receipt instruction Orbit appends to every turn prompt. */
export function receiptInstruction(
    input: Pick<TurnInput, "role" | "thread_id">,
) {
    const outcomes = OUTCOMES[input.role];
    const example = {
        thread: input.thread_id,
        outcome: outcomes[0],
        summary:
            "The export command works and tests/Feature/ExportTest.php covers it.",
        deliverables: { "export-test": "tests/Feature/ExportTest.php" },
    };
    return [
        "End your turn by writing .git/orbit/receipt.json in the workspace with your file tools. Write one JSON object:",
        `{"thread": ${input.thread_id}, "outcome": "OUTCOME", "summary": "What you did", "question": "One specific question", "deliverables": {"DELIVERABLE_ID": "evidence"}}`,
        `- thread is ${input.thread_id}, the thread of this turn.`,
        `- outcome is one of: ${outcomes.join(", ")}.`,
        "- summary is required.",
        "- question is required when outcome is blocked. Leave it out otherwise.",
        "- deliverables confirms each required deliverable by its ID, with where or how it is met. Use {} when there are none.",
        "- Add cause or pull_request only when the task asks for them.",
        "Example: " + JSON.stringify(example),
    ].join("\n");
}

/** One turn key per Pi message; a parent that runs several turns on one session names each of them. */
const TurnKey = z.string().regex(/^[A-Za-z0-9_.:-]{1,128}$/);
const PrepareArgs = z.object({
    intent: Input,
    operation_id: Id,
    node_id: Id,
    turn_key: z.union([TurnKey, z.literal("")]).optional(),
    prompt_append: z.string().max(60000).optional(),
});

/** Public data shaping only. No filesystem, transport, process or credential reads. */
export function prepare(raw: unknown) {
    const { intent, operation_id, node_id, turn_key, prompt_append } =
        PrepareArgs
            .parse(raw);
    if (intent.node_id !== node_id) throw new Error("tasks.node_mismatch");
    const input = { ...intent, operation_id };
    const key = turn_key ? turn_key : `operation-${operation_id}`;
    const prompt = input.prompt +
        (prompt_append ? "\n\n" + prompt_append : "") +
        "\n\n" + receiptInstruction(input) + "\n";
    const error_code = prompt.length > 100000 ? "tasks.prompt_too_large" : null;
    const host = input.pi_host.includes(":")
        ? `[${input.pi_host}]`
        : input.pi_host;
    return {
        error_code,
        turn_key: key,
        intent_json: JSON.stringify(input),
        pi_url: `http://${host}:${input.pi_port}`,
        create_json: JSON.stringify({
            id: input.session_id,
            cwd: input.checkout_path,
            model: input.model,
            thinkingLevel: input.effort ?? "high",
            appendSystemPrompt:
                `You are the ${input.role} for Orbit task ${input.group_id}, subtask ${input.subtask}. Required deliverables: ${
                    JSON.stringify(input.deliverables)
                }`,
        }),
        send_json: JSON.stringify({
            key,
            text: prompt,
        }),
    };
}

/** What the receipt reader emits: the redacted receipt, or a fixed code for an unreadable file. */
const ReceiptFile = z.object({
    receipt: z.unknown(),
    error: z.literal("tasks.receipt_invalid").nullable(),
}).strict();
const NO_RECEIPT = { receipt: null, error: null };
const PublicJson = z.string().max(1048576).default("");
const ResultArgs = z.object({
    input: Input,
    before: PublicJson,
    after: PublicJson,
    terminal: PublicJson,
    receipt: PublicJson,
    difference: PublicJson,
    restored: PublicJson,
    failure: z.boolean().default(false),
    failure_code: z.enum([
        "tasks.head_moved",
        "tasks.prompt_too_large",
    ]).nullable().default(null),
});
export function finish(raw: unknown) {
    const args = ResultArgs.parse(raw);
    const read = (value: string): unknown =>
        value.trim() ? JSON.parse(value) : null;
    const before = Snapshot.nullable().parse(read(args.before));
    const terminal = State.nullable().parse(read(args.terminal));
    const file = ReceiptFile.parse(read(args.receipt) ?? NO_RECEIPT);
    const checked = file.error !== null
        ? { receipt: null, error: file.error }
        : file.receipt === null
        ? { receipt: null, error: null }
        : checkReceipt(file.receipt, args.input);
    const failed = args.failure || terminal?.state !== "done";
    const usage = terminal?.usage;
    const result = {
        receipt: failed ? null : checked.receipt,
        receipt_error: checked.error,
        thread: args.input.thread_id,
        subtask: args.input.subtask,
        ...(terminal?.transcript ? { transcript: terminal.transcript } : {}),
        state: failed ? "failed" : "done",
        error: failed ? "Pi did not complete the task turn." : null,
        ...(failed
            ? { error_code: args.failure_code ?? "tasks.turn_failed" }
            : {}),
        before,
        after: Snapshot.nullable().parse(read(args.after)),
        after_reset: Snapshot.nullable().parse(read(args.restored)),
        difference: read(args.difference),
        ...(usage
            ? {
                metrics: {
                    ...(usage.total !== undefined
                        ? { tokens: usage.total, cost_usd: usage.cost ?? null }
                        : {}),
                    ...(usage.input !== undefined &&
                            usage.cacheWrite !== undefined
                        ? { input_tokens: usage.input + usage.cacheWrite }
                        : {}),
                    ...(usage.cacheRead !== undefined
                        ? { cached_input_tokens: usage.cacheRead }
                        : {}),
                    ...(usage.output !== undefined
                        ? { output_tokens: usage.output }
                        : {}),
                    ...(usage.calls !== undefined
                        ? { model_calls: usage.calls }
                        : {}),
                    ...(usage.peakContext !== undefined
                        ? { peak_context_tokens: usage.peakContext }
                        : {}),
                },
            }
            : {}),
    };
    return { result_json: JSON.stringify(result), successful: !failed };
}

/** The repository redaction rule, plus the task VM's 32-byte hex Pi and router credentials. */
export function redactTurnText(raw: string): string {
    return redactText(raw);
}

function publicValue(value: unknown): unknown {
    if (typeof value === "string") return redactTurnText(value);
    if (Array.isArray(value)) return value.map(publicValue);
    if (value !== null && typeof value === "object") {
        return Object.fromEntries(
            Object.entries(value).map((
                [key, item],
            ) => [key, publicValue(item)]),
        );
    }
    return value;
}

/*
 * The three readers below run on the Swamp worker against files that only
 * official SSH copy or the observe step wrote into the run's private local
 * directory. Raw text never leaves them: parser and file errors become one
 * fixed code, because their messages can quote the private input.
 */
async function readPrivate(path: string, code: string): Promise<string> {
    try {
        return await Deno.readTextFile(path);
    } catch {
        throw new Error(code);
    }
}
function parsePrivate(raw: string, code: string): unknown {
    try {
        return JSON.parse(raw);
    } catch {
        throw new Error(code);
    }
}

/** Retain available transcript evidence even when the stream ends without a terminal event. */
export async function terminal(
    args: { path: string; turn: string; partial?: boolean },
) {
    const raw = await readPrivate(args.path, "tasks.pi_completion_unconfirmed");
    const events: Record<string, unknown>[] = [];
    for (const line of raw.trim().split("\n")) {
        try {
            const value = parsePrivate(line, "tasks.pi_event_invalid");
            if (
                value !== null && typeof value === "object" &&
                !Array.isArray(value)
            ) {
                events.push(value as Record<string, unknown>);
            } else if (!args.partial) {
                throw new Error("tasks.pi_event_invalid");
            }
        } catch {
            if (!args.partial) throw new Error("tasks.pi_event_invalid");
        }
    }
    const event = events.at(-1);
    const complete = event?.turnId === args.turn &&
        (event.state === "done" || event.state === "failed");
    if (!complete && !args.partial) {
        throw new Error("tasks.pi_completion_unconfirmed");
    }
    const parsed = Usage.optional().safeParse(
        complete ? event?.usage : undefined,
    );
    if (!parsed.success && !args.partial) {
        throw new Error("tasks.pi_usage_invalid");
    }
    const entries = new Map<string, Record<string, unknown>>();
    for (const item of events) {
        const values = item.kind === "snapshot" && Array.isArray(item.entries)
            ? item.entries
            : item.kind === "entry"
            ? [item.entry]
            : [];
        for (const value of values) {
            if (
                value && typeof value === "object" &&
                typeof value.id === "string"
            ) {
                entries.set(
                    value.id,
                    publicValue(value) as Record<string, unknown>,
                );
            }
        }
    }
    // Keep the newest complete entries within the public result budget.
    const transcript: Record<string, unknown>[] = [];
    let size = 0;
    let truncated = events.some((item) => item.kind === "truncated");
    for (const entry of [...entries.values()].reverse()) {
        size += new TextEncoder().encode(JSON.stringify(entry)).length;
        if (size > 262144) {
            truncated = true;
            break;
        }
        transcript.unshift(entry);
    }
    if (truncated) {
        transcript.unshift({
            id: "orbit-truncated",
            timestamp: "",
            message: {
                role: "system",
                content:
                    "[Transcript truncated: older entries omitted from the recorded turn.]",
            },
        });
    }
    return JSON.stringify({
        state: complete && parsed.success ? event?.state : "failed",
        turnId: args.turn,
        ...(parsed.success && parsed.data ? { usage: parsed.data } : {}),
        ...(!args.partial || transcript.length > 0 ? { transcript } : {}),
    });
}

/**
 * The agent's receipt, redacted. No file gives `{receipt: null, error: null}`. A link, a large
 * file or text that is not JSON gives `tasks.receipt_invalid` as evidence, not a failed step,
 * because the agent writes the file itself.
 */
export async function receipt(args: { path: string }) {
    let info: Deno.FileInfo;
    try {
        info = await Deno.lstat(args.path);
    } catch (error) {
        if (error instanceof Deno.errors.NotFound) {
            return JSON.stringify(NO_RECEIPT);
        }
        throw new Error("tasks.receipt_invalid");
    }
    const invalid = JSON.stringify({
        receipt: null,
        error: "tasks.receipt_invalid",
    });
    if (!info.isFile || info.size > 65536) return invalid;
    const raw = await readPrivate(args.path, "tasks.receipt_invalid");
    let value: unknown;
    try {
        value = JSON.parse(raw);
    } catch {
        return invalid;
    }
    return JSON.stringify({ receipt: publicValue(value), error: null });
}

/** File counts from `git diff --numstat -z` and the redacted patch, both bounded. */
export async function difference(args: { counts: string; patch: string }) {
    const count = (value: string) => {
        if (value === "-") return 0;
        if (!/^[0-9]+$/.test(value)) {
            throw new Error("tasks.difference_invalid");
        }
        return Number(value);
    };
    const files = (await readPrivate(args.counts, "tasks.difference_invalid"))
        .split("\0").filter((row) => row.length > 0).map((row) => {
            const [added, removed, ...name] = row.split("\t");
            if (removed === undefined || name.length === 0) {
                throw new Error("tasks.difference_invalid");
            }
            return {
                path: name.join("\t"),
                insertions: count(added),
                deletions: count(removed),
            };
        });
    const diff = redactTurnText(
        await readPrivate(args.patch, "tasks.difference_invalid"),
    );
    return JSON.stringify(publicValue({
        files: files.slice(0, 500),
        diff: diff.slice(0, 65536),
        files_complete: files.length <= 500,
        diff_available: diff.length <= 65536,
        summary: {
            files: files.length,
            insertions: files.reduce((sum, file) => sum + file.insertions, 0),
            deletions: files.reduce((sum, file) => sum + file.deletions, 0),
        },
    }));
}

/** The outcomes a parent run reads from a finished turn: the receipt outcome and summary, and the commit the turn left. */
const OutcomeResult = z.object({
    state: z.string(),
    receipt: z.object({
        outcome: z.string().optional(),
        summary: z.string().optional(),
    }).passthrough().nullable(),
    before: Snapshot.nullable(),
    after: Snapshot.nullable(),
}).passthrough();
export function outcome(raw: { result_json: string }) {
    const result = OutcomeResult.parse(JSON.parse(raw.result_json));
    const { before, after } = result;
    return {
        outcome: result.receipt?.outcome ?? "",
        approved: result.state === "done" &&
            result.receipt?.outcome === "approved",
        summary: result.receipt?.summary ?? "",
        moved: before !== null && after !== null && before.head !== after.head,
        head: after?.head ?? "",
        tree: after?.tree ?? "",
    };
}

/**
 * The state of the review loop of a prep run after one review or one fix. A review sets whether the spec is approved
 * and what the findings are; a fix sets the commit that the next review restores. A fix that left HEAD where it was
 * ends the run, because the next review would restore a commit that holds none of the fix.
 */
export function settle(
    raw: {
        approved: boolean;
        head: string;
        tree: string;
        review: string;
        fix: string;
        plan_exit?: number | null;
        plan_output?: string;
    },
) {
    let { approved, head, tree } = raw;
    let findings = "";
    if (raw.review !== "") {
        const reviewed = outcome({ result_json: raw.review });
        approved = reviewed.approved;
        findings = reviewed.summary;
    }
    if (raw.fix !== "") {
        const fixed = outcome({ result_json: raw.fix });
        if (!fixed.moved) throw new Error("tasks.prep_not_committed");
        head = fixed.head;
        tree = fixed.tree;
    }
    // The runner plan of the cases file comes first in the findings, and no review approves past it.
    if (
        raw.plan_exit !== undefined && raw.plan_exit !== null &&
        raw.plan_exit !== 0
    ) {
        approved = false;
        const refusal = redactTurnText(raw.plan_output ?? "").trim().slice(
            0,
            1500,
        );
        findings = "The runner plan of the cases file was refused (exit " +
            raw.plan_exit +
            "). The cases file uses something the runner does not have: list that case under `uncovered` and do not change the runner.\n" +
            refusal + (findings === "" ? "" : "\n\n" + findings);
    }
    return { approved, head, tree, findings };
}

/**
 * The verdict of one review round, from the reviewer turn that judged the head: `pass` for the outcome `approved`,
 * `findings` for `changes_requested`, and `failed` for any other end, such as a turn that left no result. The body is the comment of the App on the pull request.
 */
export function round(
    raw: { result_json: string; round: number; head: string },
) {
    const result = raw.result_json === ""
        ? undefined
        : OutcomeResult.parse(JSON.parse(raw.result_json));
    const ended = result?.state === "done"
        ? result.receipt?.outcome
        : undefined;
    const verdict = ended === "approved"
        ? "pass"
        : ended === "changes_requested"
        ? "findings"
        : "failed";
    const summary = redactTurnText(result?.receipt?.summary ?? "").slice(
        0,
        8000,
    );
    const label = verdict === "failed" ? "no verdict" : verdict;
    return {
        verdict,
        findings: verdict === "findings" ? summary : "",
        body:
            `**Review round ${raw.round}** of \`${
                raw.head.slice(0, 7)
            }\`: **${label}**` +
            (summary === "" ? "" : "\n\n" + summary),
    };
}

/** The candidate commit that the local check of a fix made: it passed, and it names the commit and the tree to push. */
export function checked(raw: { result_json: string }) {
    const result = raw.result_json === "" ? {} : z.object({
        outcome: z.string().optional(),
        error_code: z.string().optional(),
        commit: z.string().nullable().optional(),
        tree: z.string().optional(),
    }).passthrough().parse(JSON.parse(raw.result_json));
    const commit = Sha.safeParse(result.commit).success
        ? String(result.commit)
        : "";
    const passed = result.outcome === "passed" &&
        result.error_code === undefined &&
        commit !== "" && Sha.safeParse(result.tree).success;
    return {
        passed,
        outcome: result.outcome ?? "",
        commit: passed ? commit : "",
        tree: passed ? String(result.tree) : "",
        result_json: raw.result_json,
    };
}

type PublicContext = {
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<unknown>;
};
export const turnDataResources = {
    turnPrepared: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "Public turn inputs serialized for the native steps",
        schema: z.object({
            error_code: z.string().nullable(),
            turn_key: z.string(),
            intent_json: z.string(),
            pi_url: z.string(),
            create_json: z.string(),
            send_json: z.string(),
        }),
    },
    snapshot: {
        lifetime: "infinite",
        garbageCollection: 10,
        schema: z.object({ stdout: z.string(), head: Sha, tree: Sha }),
    },
    publicOutput: {
        lifetime: "infinite",
        garbageCollection: 10,
        description:
            "Redacted public JSON read from the run's private worker files",
        schema: z.object({ stdout: z.string() }),
    },
    turnOutcome: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "What a parent run reads from one finished turn",
        schema: z.object({
            outcome: z.string(),
            approved: z.boolean(),
            summary: z.string(),
            moved: z.boolean(),
            head: z.union([Sha, z.literal("")]),
            tree: z.union([Sha, z.literal("")]),
        }),
    },
    reviewState: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "Where the review loop of a prep run stands",
        schema: z.object({
            approved: z.boolean(),
            head: z.union([Sha, z.literal("")]),
            tree: z.union([Sha, z.literal("")]),
            findings: z.string(),
        }),
    },
    roundVerdict: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "The verdict and the comment of one review round",
        schema: z.object({
            verdict: z.string(),
            findings: z.string(),
            body: z.string(),
        }),
    },
    checkedFix: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "Whether the local check of a fix made a candidate commit",
        schema: z.object({
            passed: z.boolean(),
            outcome: z.string(),
            commit: z.union([Sha, z.literal("")]),
            tree: z.union([Sha, z.literal("")]),
            result_json: z.string(),
        }),
    },
    turnResult: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "Public terminal turn result",
        schema: z.object({ result_json: z.string(), successful: z.boolean() }),
    },
};
export const turnDataMethods = {
    prepare: {
        description: "Shape the complete admitted public turn intent",
        arguments: PrepareArgs,
        execute: async (args: unknown, ctx: PublicContext) => ({
            dataHandles: [
                await ctx.writeResource(
                    "turnPrepared",
                    "prepared",
                    prepare(args),
                ),
            ],
        }),
    },
    snapshot: {
        description:
            "Shape immutable Git object identities from native SSH commands",
        arguments: z.object({ head: z.string(), tree: z.string() }),
        execute: async (
            args: { head: string; tree: string },
            ctx: PublicContext,
        ) => ({
            dataHandles: [
                await ctx.writeResource("snapshot", "snapshot", {
                    head: Sha.parse(args.head.trim()),
                    tree: Sha.parse(args.tree.trim()),
                    stdout: JSON.stringify(
                        Snapshot.parse({
                            head: args.head.trim(),
                            tree: args.tree.trim(),
                        }),
                    ),
                }),
            ],
        }),
    },
    terminal: {
        description:
            "Read the matching terminal Pi event from the private worker file",
        arguments: z.object({
            path: z.string().startsWith("/"),
            turn: z.string(),
        }),
        execute: async (
            args: { path: string; turn: string },
            ctx: PublicContext,
        ) => ({
            dataHandles: [
                await ctx.writeResource("publicOutput", "terminal", {
                    stdout: await terminal(args),
                }),
            ],
        }),
    },
    receipt: {
        description:
            "Read and redact the agent receipt from the private worker file",
        arguments: z.object({ path: z.string().startsWith("/") }),
        execute: async (args: { path: string }, ctx: PublicContext) => ({
            dataHandles: [
                await ctx.writeResource("publicOutput", "receipt", {
                    stdout: await receipt(args),
                }),
            ],
        }),
    },
    difference: {
        description:
            "Read and redact the turn diff from the private worker files",
        arguments: z.object({
            counts: z.string().startsWith("/"),
            patch: z.string().startsWith("/"),
        }),
        execute: async (
            args: { counts: string; patch: string },
            ctx: PublicContext,
        ) => ({
            dataHandles: [
                await ctx.writeResource("publicOutput", "difference", {
                    stdout: await difference(args),
                }),
            ],
        }),
    },
    outcome: {
        description:
            "Read the receipt outcome and the commit a finished turn left",
        arguments: z.object({ result_json: z.string() }),
        execute: async (args: { result_json: string }, ctx: PublicContext) => ({
            dataHandles: [
                await ctx.writeResource(
                    "turnOutcome",
                    "outcome",
                    outcome(args),
                ),
            ],
        }),
    },
    settle: {
        description:
            "Carry the review loop of a prep run through one review or fix",
        arguments: z.object({
            approved: z.boolean(),
            head: z.string(),
            tree: z.string(),
            review: z.string(),
            fix: z.string(),
            plan_exit: z.number().int().nullable().default(null),
            plan_output: z.string().default(""),
        }),
        execute: async (
            args: {
                approved: boolean;
                head: string;
                tree: string;
                review: string;
                fix: string;
                plan_exit?: number | null;
                plan_output?: string;
            },
            ctx: PublicContext,
        ) => ({
            dataHandles: [
                await ctx.writeResource("reviewState", "state", settle(args)),
            ],
        }),
    },
    round: {
        description: "Judge one review round from the finished reviewer turn",
        arguments: z.object({
            result_json: z.string(),
            round: z.number().int().positive(),
            head: z.string(),
        }),
        execute: async (
            args: { result_json: string; round: number; head: string },
            ctx: PublicContext,
        ) => ({
            dataHandles: [
                await ctx.writeResource("roundVerdict", "verdict", round(args)),
            ],
        }),
    },
    checked: {
        description:
            "Read the candidate commit that the local check of a fix made",
        arguments: z.object({ result_json: z.string() }),
        execute: async (args: { result_json: string }, ctx: PublicContext) => ({
            dataHandles: [
                await ctx.writeResource("checkedFix", "fix", checked(args)),
            ],
        }),
    },
    result: {
        description: "Shape public turn step outputs for terminal projection",
        arguments: ResultArgs,
        execute: async (args: unknown, ctx: PublicContext) => ({
            dataHandles: [
                await ctx.writeResource("turnResult", "result", finish(args)),
            ],
        }),
    },
};
