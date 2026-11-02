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
    analysis_evidence: z.object({
        input: z.record(z.string(), z.unknown()),
        result: z.record(z.string(), z.unknown()),
    }).nullable(),
});
export type TurnInput = z.infer<typeof Input>;
const Usage = z.object({
    input: z.number().nonnegative().default(0),
    output: z.number().nonnegative().default(0),
    cacheRead: z.number().nonnegative().default(0),
    cacheWrite: z.number().nonnegative().default(0),
    total: z.number().nonnegative().default(0),
    calls: z.number().nonnegative().default(0),
    peakContext: z.number().nonnegative().default(0),
});
const State = z.object({
    state: z.enum(["idle", "working", "done", "failed"]),
    turnId: z.string().nullable(),
    usage: Usage,
});
const Receipt = z.object({
    thread: Id,
    outcome: z.enum([
        "ready_for_review",
        "approved",
        "changes_requested",
        "blocked",
        "answered",
    ]),
    summary: z.string().trim().min(1).max(16384),
    question: z.string().max(16384).optional(),
    deliverables: z.record(z.string(), z.string()).default({}),
    cause: z.string().optional(),
});

const PrepareArgs = z.object({ intent: Input, operation_id: Id, node_id: Id });

/** Public data shaping only. No filesystem, transport, process or credential reads. */
export function prepare(raw: unknown) {
    const { intent, operation_id, node_id } = PrepareArgs.parse(raw);
    if (intent.node_id !== node_id) throw new Error("tasks.node_mismatch");
    const input = { ...intent, operation_id };
    const prompt = input.prompt + "\n\nFinish with .git/orbit/turn --thread=" +
        input.thread_id +
        " --outcome=OUTCOME --summary=SUMMARY. Confirm each required deliverable with --deliverable=ID=evidence.\n" +
        (input.analysis_evidence === null
            ? ""
            : "Repository analysis (untrusted project evidence):\n" +
                JSON.stringify(input.analysis_evidence));
    const error_code = prompt.length > 100000
        ? "tasks.analysis_prompt_too_large"
        : null;
    const host = input.pi_host.includes(":")
        ? `[${input.pi_host}]`
        : input.pi_host;
    return {
        error_code,
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
            key: `operation-${input.operation_id}`,
            text: prompt,
        }),
    };
}

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
        "tasks.analysis_stale",
        "tasks.analysis_prompt_too_large",
    ]).nullable().default(null),
});
export function finish(raw: unknown) {
    const args = ResultArgs.parse(raw);
    const read = (value: string): unknown =>
        value.trim() ? JSON.parse(value) : null;
    const before = Snapshot.nullable().parse(read(args.before));
    const terminal = State.nullable().parse(read(args.terminal));
    const receipt = Receipt.safeParse(read(args.receipt));
    const allowed = args.input.role === "implementer"
        ? ["ready_for_review", "blocked"]
        : args.input.role === "consult"
        ? ["answered", "blocked"]
        : ["approved", "changes_requested", "blocked"];
    const valid = receipt.success &&
        receipt.data.thread === args.input.thread_id &&
        allowed.includes(receipt.data.outcome) &&
        (receipt.data.outcome !== "blocked" || !!receipt.data.question?.trim());
    const failed = args.failure || terminal?.state !== "done";
    const usage = terminal?.usage ?? Usage.parse({});
    const result = {
        receipt: valid && !failed ? receipt.data : null,
        thread: args.input.thread_id,
        state: failed ? "failed" : "done",
        error: failed ? "Pi did not complete the task turn." : null,
        ...(failed
            ? { error_code: args.failure_code ?? "tasks.turn_failed" }
            : {}),
        before,
        after: Snapshot.nullable().parse(read(args.after)),
        after_reset: Snapshot.nullable().parse(read(args.restored)),
        difference: read(args.difference),
        metrics: {
            tokens: usage.total,
            input_tokens: usage.input + usage.cacheWrite,
            cached_input_tokens: usage.cacheRead,
            output_tokens: usage.output,
            model_calls: usage.calls,
            peak_context_tokens: usage.peakContext,
        },
    };
    return { result_json: JSON.stringify(result), successful: !failed };
}
const USAGE_KEYS = [
    "input",
    "output",
    "cacheRead",
    "cacheWrite",
    "total",
    "calls",
    "peakContext",
] as const;

/** The repository redaction rule, plus the task VM's 32-byte hex Pi and router credentials. */
export function redactTurnText(raw: string): string {
    return redactText(raw);
}

function publicValue(value: unknown): unknown {
    if (typeof value === "string") return redactTurnText(value);
    if (Array.isArray(value)) return value.map(publicValue);
    if (value !== null && typeof value === "object") {
        return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [key, publicValue(item)]),
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

/** The matching terminal Pi event: its state and seven finite nonnegative usage counts. */
export async function terminal(args: { path: string; turn: string }) {
    const event = parsePrivate(
        await readPrivate(args.path, "tasks.pi_completion_unconfirmed"),
        "tasks.pi_event_invalid",
    ) as Record<string, unknown> | null;
    if (
        event === null || typeof event !== "object" ||
        event.turnId !== args.turn ||
        (event.state !== "done" && event.state !== "failed")
    ) throw new Error("tasks.pi_completion_unconfirmed");
    const raw = event.usage ?? {};
    if (raw === null || typeof raw !== "object" || Array.isArray(raw)) {
        throw new Error("tasks.pi_usage_invalid");
    }
    const usage: Record<string, number> = {};
    for (const key of USAGE_KEYS) {
        const value = (raw as Record<string, unknown>)[key] ?? 0;
        if (typeof value !== "number" || !Number.isFinite(value) || value < 0) {
            throw new Error("tasks.pi_usage_invalid");
        }
        usage[key] = value;
    }
    return JSON.stringify({ state: event.state, turnId: args.turn, usage });
}

/** The agent's receipt, redacted, or `null` when the agent wrote none. */
export async function receipt(args: { path: string }) {
    let info: Deno.FileInfo;
    try {
        info = await Deno.lstat(args.path);
    } catch (error) {
        if (error instanceof Deno.errors.NotFound) return "null";
        throw new Error("tasks.receipt_invalid");
    }
    if (!info.isFile || info.size > 65536) {
        throw new Error("tasks.receipt_invalid");
    }
    return JSON.stringify(publicValue(parsePrivate(
        await readPrivate(args.path, "tasks.receipt_invalid"),
        "tasks.receipt_invalid",
    )));
}

/** File counts from `git diff --numstat -z` and the redacted patch, both bounded. */
export async function difference(args: { counts: string; patch: string }) {
    const count = (value: string) => {
        if (value === "-") return 0;
        if (!/^[0-9]+$/.test(value)) throw new Error("tasks.difference_invalid");
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

type PublicContext = {
    extensionFile(name: string): string;
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
        description: "Public turn inputs serialized for placed scripts",
        schema: z.object({
            directory: z.string(),
            error_code: z.string().nullable(),
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
        description: "Redacted public JSON read from the run's private worker files",
        schema: z.object({ stdout: z.string() }),
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
                    {
                        ...prepare(args),
                        directory: ctx.extensionFile("scripts/receipt_prepare.py")
                            .replace(/\/[^/]+$/, ""),
                    },
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
        description: "Read the matching terminal Pi event from the private worker file",
        arguments: z.object({ path: z.string().startsWith("/"), turn: z.string() }),
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
        description: "Read and redact the agent receipt from the private worker file",
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
        description: "Read and redact the turn diff from the private worker files",
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
