import { z } from "npm:zod@4.4.3";

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
    if (prompt.length > 100000) {
        throw new Error("tasks.analysis_prompt_too_large");
    }
    const host = input.pi_host.includes(":")
        ? `[${input.pi_host}]`
        : input.pi_host;
    return {
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
        ...(failed ? { error_code: "tasks.turn_failed" } : {}),
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
    return { result_json: JSON.stringify(result) };
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
        description: "Public turn inputs serialized for placed scripts",
        schema: z.object({
            intent_json: z.string(),
            pi_url: z.string(),
            create_json: z.string(),
            send_json: z.string(),
        }),
    },
    turnResult: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "Public terminal turn result",
        schema: z.object({ result_json: z.string() }),
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
