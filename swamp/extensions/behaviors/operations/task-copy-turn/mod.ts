import { z } from "npm:zod@4.4.3";
import { redactText } from "../../_lib/public_output.ts";

/** The longest public transcript of one turn, as for a task VM turn. */
const TranscriptBytes = 262144;
const StartBytes = 16777216;
const ReadBytes = 16777216;
const AskBytes = 1048576;
const Folder = z.string().regex(/^\/tmp\/orbit-ansible-[0-9a-f-]{36}$/);

// What `task-copy-turn.yml` writes, each file as soon as its command ended: `settle.json` with the earlier turns it found settled,
// those still open and the runs it stopped for them, for a continued turn `continue.json` with the exit code of
// `t3 thread list` (null when it printed no JSON) and how many T3 threads carry its title, `answered.json` with the resolution helper's
// exit and reply before an answer is sent, for a reviewer turn `review.json` with whether
// the candidate is checked out, `detach.json` as soon as the reviewer's candidate is checked out, for a new thread `model.json` with the
// model, the slugs T3's catalog offers (null when it was not read), the check decision and any driver or probe failure,
// `launch.json` before the start,
// `start.json` with the exit code, stdout and a failed start's stderr tail of `t3 thread start` or `t3 thread send` with `--wait --json`, for a run that waits for an answer `ask.json` with the exit code and stdout of `t3_copy_rpc.mjs
// read` and `interrupt.json` with those of `t3_copy_rpc.mjs interrupt`, for a wait the bound cut `stop.json` with the exit code and
// stdout of `t3_copy_rpc.mjs stop`, `read.json` with what `t3 thread list` found and `t3 thread show`
// read, `usage.json` with the exit code and stdout of `t3_copy_rpc.mjs usage` for the thread it found, and for a reviewer turn `restore.json` with whether the checkout is clean on its branch at its tip again.
const Settle = z.object({ list_exit: z.number().int().nullable(), settled: z.array(z.number().int()), open: z.array(z.number().int()),
    stopped: z.array(z.object({ operation_id: z.number().int(), run: z.string() }).strict()) }).strict();
const Resumed = z.object({ list_exit: z.number().int().nullable(), found: z.number().int() }).strict();
const Answer = z.object({ answered_exit: z.number().int().nullable(), answered: z.string() }).strict();
const Answered = z.object({ resolved: z.boolean(), pending: z.array(z.string()), error: z.string().nullable() });
const Review = z.object({ ready: z.boolean(), error_code: z.enum(["tasks.copy_tree_dirty", "tasks.copy_turn_failed"]).nullable() }).strict();
const Restore = z.object({ restored: z.boolean() }).strict();
const Start = z.object({ start_exit: z.number().int(), start: z.string(), error: z.string().optional() }).strict();
const Model = z.object({ model: z.string(), offered: z.array(z.string()).nullable(), accepted: z.boolean(),
    driver_unresolved: z.literal(true).optional(), probe: z.enum(["confirmed", "rejected", "unavailable"]).optional() }).strict();
const Ask = z.object({ ask_exit: z.number().int(), ask: z.string() }).strict();
const Interrupt = z.object({ interrupt_exit: z.number().int(), interrupt: z.string() }).strict();
const Stop = z.object({ stop_exit: z.number().int(), stop: z.string() }).strict();
const UsageRead = z.object({ usage_exit: z.number().int(), usage: z.string() }).strict();
const Count = z.number().int().nonnegative();
// What `t3_copy_rpc.mjs usage` prints: the usage T3 keeps on each provider turn of the thread, null on a turn that has none.
const ProviderUsage = z.object({ turns: z.array(z.object({ usageStatus: z.string().nullish(), inputTokens: Count.nullish(),
    cachedInputTokens: Count.nullish(), outputTokens: Count.nullish() }).nullable()) });
// One question as `t3_copy_rpc.mjs read` prints it from T3's `user_input_request` item: the agent's own words and options, with what T3
// may leave out made explicit. The evidence file's bound is the only size bound, so a read the phase stopped on always parses.
const Question = z.object({
    id: z.string(),
    header: z.string(),
    question: z.string(),
    options: z.array(z.object({ label: z.string(), description: z.string(), value: z.string().optional() })),
    multiSelect: z.boolean().optional(),
    allowCustomAnswer: z.boolean().optional(),
    initialAnswer: z.string().optional(),
    required: z.boolean().optional(),
});
const Run = z.object({ id: z.string(), status: z.string() });
// What `t3_copy_rpc.mjs read` prints: the runs that are not over, every pending request with its owner run and its questions, and
// whether the phase may stop the run on them (questions only, stored whole).
const Asked = z.object({ runs: z.array(Run), requests: z.array(z.object({ request_id: z.string(), kind: z.string(), run_id: z.string().nullish(),
    questions: z.array(Question) })),
    stoppable: z.boolean() });
// What `t3_copy_rpc.mjs interrupt` prints once the run ended or its wait for that did.
const Stopped = z.object({ interrupted: z.boolean(), error: z.string().nullable(), runs: z.array(Run), pending: z.number().int() });
// What `t3_copy_rpc.mjs stop` prints: whether it stopped the run, whether its last readback shows that exact run in a status that ends
// it, the run's id and that status.
const Halted = z.object({ stopped: z.boolean(), over: z.boolean(), run: z.string().nullable(), state: z.string().nullable() });
const Read = z.object({
    list_exit: z.number().int().nullable(),
    // The thread the turn sent to, which the read looked for by its ID; null when the turn made no thread and the read looked for its title.
    thread_id: z.string().nullable().optional(),
    found: z.number().int(),
    // The status `t3 thread list` gives the thread of this turn.
    status: z.string().nullable(),
    show_exit: z.number().int().nullable(),
    show: z.string(),
}).strict();
/** Thread states in which T3's run may still change the checkout. */
const Active = new Set(["preparing", "queued", "starting", "running", "waiting"]);
/** T3's run statuses that end a run. A run status in neither set is unknown: it names no end and is never echoed. */
const RunOver = new Set(["completed", "failed", "cancelled", "interrupted", "rolled_back"]);
/** The thread states in which T3 runs nothing. A thread state in neither this set nor Active is unknown and names no end. */
export const ThreadOver = new Set([...RunOver, "idle"]);
export type Evidence = {
    settle?: z.infer<typeof Settle> | null;
    resumed: z.infer<typeof Resumed> | null;
    answered?: z.infer<typeof Answer> | null;
    review: z.infer<typeof Review> | null;
    model: z.infer<typeof Model> | null;
    detached: boolean;
    launched: boolean;
    start: z.infer<typeof Start> | null;
    ask: z.infer<typeof Ask> | null;
    interrupt: z.infer<typeof Interrupt> | null;
    stop: z.infer<typeof Stop> | null;
    read: z.infer<typeof Read> | null;
    usage?: z.infer<typeof UsageRead> | null;
    restore: z.infer<typeof Restore> | null;
};
const Started = z.object({
    threadId: z.string().min(1),
    runId: z.string().nullish(),
    // `--wait` prints the run's status; without a run, T3 prints only the thread.
    status: z.string().optional(),
    error: z.string().nullish(),
});
const Shown = z.object({
    thread: z.object({ threadId: z.string().min(1), status: z.string(), providerInstanceId: z.string().nullish(), model: z.string().nullish() }).passthrough(),
    messages: z.array(z.object({ role: z.enum(["user", "assistant"]), runId: z.string().nullish(), text: z.string() })),
});
const Entry = z.object({ id: z.string(), kind: z.literal("message"), label: z.string(), text: z.string(), at: z.string() });
const AskedQuestion = z.object({
    request_id: z.string(),
    // The run that owns the request: only that run's question is cancelled when the answer comes. A question without one is never stored.
    run_id: z.string(),
    id: z.string(),
    header: z.string(),
    question: z.string(),
    options: z.array(z.object({ label: z.string(), description: z.string(), value: z.string().nullable() })),
    multi_select: z.boolean(),
    allow_custom_answer: z.boolean().nullable(),
    initial_answer: z.string().nullable(),
    required: z.boolean().nullable(),
});
export const Turn = z.object({
    state: z.enum(["done", "asking_for_input", "failed"]),
    error: z.string().nullable(),
    error_code: z.string().regex(/^tasks\.[a-z_]+$/).optional(),
    // Whether the copy is known to be free of this turn: T3's run is over and a reviewer's checkout is restored. A turn that is not
    // keeps its group from more work until task:cancel.
    ended: z.boolean(),
    t3_thread_id: z.string().nullable(),
    run_id: z.string().nullable(),
    // Whether the turn's message may have reached T3, and whether T3 confirmed the run it started from it. A turn sent but not launched
    // is possibly sent: it keeps the thread's stored questions (docs/reference/development-containers.md#stable-ids).
    sent: z.boolean(),
    launched: z.boolean(),
    // The thread's provider instance and model as T3 shows them; the Gateway records the ones the thread started with instead.
    provider_instance: z.string().nullable(),
    model: z.string().nullable(),
    // What the agent asked, in its own words and options: set when the turn ends at a question.
    questions: z.array(AskedQuestion),
    // The earlier turns whose run this turn's phase found over, with a reviewer's detach undone.
    settled: z.array(z.number().int()),
    // The stored request IDs that an earlier answer turn's confirmed run used. The CLI driver proves no earlier run, so it names none.
    spent: z.array(z.string()),
    transcript: z.array(Entry),
    // The thread's token use over all its provider turns, which replaces what the thread holds; null when unknown.
    usage: z.object({ tokens: Count, input_tokens: Count, cached_input_tokens: Count, output_tokens: Count, model_calls: Count }).strict().nullable(),
});
export type Turn = z.infer<typeof Turn>;

const parse = <T>(schema: z.ZodType<T>, text: string): T | null => {
    try {
        const parsed = schema.safeParse(JSON.parse(text));
        return parsed.success ? parsed.data : null;
    } catch {
        return null;
    }
};

/**
 * The thread's token use in Orbit's columns, summed over every provider turn T3 holds for it, so a continued thread's use is its
 * total. T3's `inputTokens` holds the cache reads, which Orbit's input excludes. No provider turn, a turn without complete usage (still
 * running, partial or unavailable), or a read that failed, is unknown: null, never zeros.
 */
export function usage(read: z.infer<typeof UsageRead> | null | undefined): Turn["usage"] {
    const turns = read?.usage_exit === 0 ? parse(ProviderUsage, read.usage)?.turns ?? [] : [];
    let input = 0, cached = 0, output = 0;
    for (const turn of turns) {
        if (turn?.usageStatus !== "complete" || turn.inputTokens == null || turn.outputTokens == null) return null;
        const reads = turn.cachedInputTokens ?? 0;
        if (reads > turn.inputTokens) return null;
        input += turn.inputTokens - reads;
        cached += reads;
        output += turn.outputTokens;
    }
    return turns.length === 0 ? null
        : { tokens: input + cached + output, input_tokens: input, cached_input_tokens: cached, output_tokens: output, model_calls: turns.length };
}

/**
 * Message text only: T3's CLI gives no tool items. Newest entries first within the bound. A user message keeps only its
 * ID, author and time: redaction does not catch an ordinary private prompt, and the Gateway holds the prompt in the turn's input.
 */
function transcript(shown: z.infer<typeof Shown> | null): Turn["transcript"] {
    const entries: Turn["transcript"] = [];
    let size = 0;
    const messages = shown?.messages ?? [];
    for (let index = messages.length - 1; index >= 0; index--) {
        const message = messages[index];
        const entry = { id: `${message.runId ?? "run"}:${index}`, kind: "message" as const, label: message.role,
            text: message.role === "assistant" ? redactText(message.text) : "", at: "" };
        size += new TextEncoder().encode(JSON.stringify(entry)).length;
        if (size > TranscriptBytes) {
            entries.unshift({ id: "orbit-truncated", kind: "message", label: "system", text: "[Transcript truncated: older entries omitted from the recorded turn.]", at: "" });
            break;
        }
        entries.unshift(entry);
    }
    return entries;
}

/**
 * The turn as Orbit records it. A reviewer turn whose checkout was detached and is not restored, clean on its branch at its tip, fails
 * and holds its group, whatever its run did: the next turn or check would work on what the reviewer left.
 */
export function shapeTurn(evidence: Evidence): Turn {
    const turn = shapeRun(evidence);
    if (!evidence.detached || evidence.restore?.restored === true) return turn;
    const run = turn.state === "failed" ? ` The run: ${turn.error}` : "";
    return { ...turn, state: "failed", error: `The checkout was not restored after the reviewer's turn.${run}`, error_code: "tasks.copy_review_unrestored" };
}

/**
 * The run as T3 reported it. `t3 thread start --wait` and `t3 thread send --wait` end with the run's status: `completed` is done and
 * `waiting` asks for input. Any other status fails the turn. No JSON after `timeout` killed the wait (124, or 137 after the kill grace)
 * is the bound; the phase then stopped the cut run.
 */
function shapeRun(evidence: Evidence): Turn {
    const started = evidence.start === null ? null : parse(Started, evidence.start.start);
    const shown = evidence.read?.show_exit === 0 ? parse(Shown, evidence.read.show) : null;
    // The stop of a cut run read that exact run in a status that ends it.
    const halted = evidence.stop?.stop_exit === 0 ? parse(Halted, evidence.stop.stop) : null;
    const cutEnded = halted?.over === true;
    const listed = evidence.read?.list_exit === 0 ? evidence.read : null;
    const base = {
        ended: over(evidence, started?.status, listed, cutEnded),
        // The thread the turn targeted stays named when the last read failed: a later turn settles that thread by its ID.
        t3_thread_id: started?.threadId ?? shown?.thread.threadId ?? evidence.read?.thread_id ?? null,
        run_id: started?.runId ?? null,
        // The launch mark precedes the send, so it means possibly sent; only a run that T3 reported for the send confirms the launch.
        sent: evidence.launched,
        launched: evidence.launched && (started?.runId ?? null) !== null,
        provider_instance: shown?.thread.providerInstanceId ?? null,
        model: shown?.thread.model ?? null,
        questions: [],
        settled: evidence.settle?.settled ?? [],
        spent: [],
        transcript: transcript(shown),
        usage: usage(evidence.usage),
    };
    if (evidence.settle && evidence.settle.open.length > 0) {
        return { ...base, state: "failed", error: `An earlier turn may still run in the copy (Operation ${evidence.settle.open.join(", ")}); nothing was launched.`,
            error_code: "tasks.copy_turn_unended" };
    }
    if (started?.status === "completed") return { ...base, state: "done", error: null };
    if (started?.status === "waiting") return asked(base, evidence);
    if (started !== null) {
        // T3's run status is named only from its known vocabulary; its error text stays in the run folder.
        const status = started.status === undefined ? "T3 started no run" : RunOver.has(started.status) || Active.has(started.status)
            ? `T3 run ${started.status}` : "T3 reported a run status Orbit does not know";
        return { ...base, state: "failed", error: status, error_code: "tasks.turn_failed" };
    }
    if (evidence.start !== null && [124, 137].includes(evidence.start.start_exit)) {
        const stop = halted?.stopped ? "Orbit stopped its run." : base.ended ? "Its run has ended." : "Orbit did not stop its run; the turn holds until its run is over.";
        return { ...base, state: "failed", error: `The turn ran longer than its bound. ${stop}`, error_code: "tasks.turn_timeout" };
    }
    if (evidence.model !== null && !evidence.model.accepted) {
        if (evidence.model.driver_unresolved) {
            return { ...base, state: "failed", error: "The copy's T3 provider instance could not be resolved for the thread's model.",
                error_code: "tasks.copy_turn_model_unverified" };
        }
        if (evidence.model.probe === "rejected") {
            return { ...base, state: "failed", error: "The copy's Codex provider rejected the thread's model.", error_code: "tasks.copy_turn_model_unoffered" };
        }
        if (evidence.model.probe === "unavailable") {
            return { ...base, state: "failed", error: "The copy's Codex provider could not verify the thread's model.", error_code: "tasks.copy_turn_model_unverified" };
        }
        // The catalog's entries stay in the run folder's evidence: the error names none of them.
        return { ...base, state: "failed", error: "The copy's T3 does not offer the thread's model.", error_code: "tasks.copy_turn_model_unoffered" };
    }
    if (evidence.resumed !== null && evidence.resumed.list_exit !== 0) {
        const exit = evidence.resumed.list_exit === null ? "no JSON" : `exit ${evidence.resumed.list_exit}`;
        return { ...base, state: "failed", error: `T3 could not list its threads to find the one to continue (${exit}).`, error_code: "tasks.copy_turn_failed" };
    }
    if (evidence.resumed !== null && evidence.resumed.found !== 1) {
        return { ...base, state: "failed", error: `T3 holds ${evidence.resumed.found} threads of this title to continue, not one.`, error_code: "tasks.copy_thread_missing" };
    }
    if (evidence.answered) {
        const answer = evidence.answered.answered_exit === 0 ? parse(Answered, evidence.answered.answered) : null;
        if (answer?.resolved !== true || answer.pending.length > 0) {
            return { ...base, state: "failed", error: "Orbit could not confirm that the stored answered questions are resolved in T3; nothing was launched.",
                error_code: "tasks.copy_question_unresolved" };
        }
    }
    if (evidence.review !== null && !evidence.review.ready) {
        const dirty = evidence.review.error_code === "tasks.copy_tree_dirty";
        return {
            ...base,
            state: "failed",
            error: dirty ? "The checkout had changes or no branch, so the reviewer could not be put on the candidate." : "The candidate could not be checked out for the reviewer.",
            error_code: evidence.review.error_code ?? "tasks.copy_turn_failed",
        };
    }
    const exit = evidence.start === null ? "no exit" : `exit ${evidence.start.start_exit}`;
    // T3's stderr stays in the run folder.
    return { ...base, state: "failed", error: `T3 did not report the turn's end (${exit}).`, error_code: "tasks.copy_turn_failed" };
}

/**
 * The one rule for whether the copy is free of this turn, judged on this turn's own run, never on another run of its thread
 * (docs/reference/development-containers.md#turn-evidence). In order, the first row that applies decides:
 *
 * | # | Evidence | ended |
 * | 1 | A reviewer's checkout detached and not restored | false |
 * | 2 | Nothing launched | true |
 * | 3 | The run asks, and Orbit stored its question with its owner run and confirmed the interrupt of that run | true |
 * | 4 | The run asks, and Orbit could not read its question, or a request is not this run's own, or the interrupt is not confirmed | false |
 * | 5 | The start printed a status that ends a run | true |
 * | 6 | The start printed any other status: one of a run still going, such as `running` or `queued`, or one Orbit does not know | false |
 * | 7 | The stop of the run its bound cut proved its end | true |
 * | 8 | With no status of its run: T3 lists the thread the turn sent to, by its ID, in a state that runs nothing, or, for a turn that made no thread, holds none of its title | true |
 * | 9 | Anything else: a thread listed active or in an unknown state, a thread of the turn's ID that T3 does not list, or no list | false |
 */
export function over(evidence: Evidence, status: string | undefined, listed: z.infer<typeof Read> | null, cutEnded: boolean): boolean {
    if (evidence.detached && evidence.restore?.restored !== true) return false;
    if (!evidence.launched) return true;
    if (status === "waiting") return question(evidence) === "stopped";
    if (status !== undefined && RunOver.has(status)) return true;
    // A status Orbit does not know, or one of a run still going: only the run's own end ends it, never its thread's state.
    if (status !== undefined) return false;
    if (cutEnded) return true;
    // A thread T3 does not list proves nothing about a run sent to it; only a turn that made no thread is looked for by title.
    return (listed?.found === 0 && (listed.thread_id ?? null) === null) || (listed?.found === 1 && listed.status !== null && ThreadOver.has(listed.status));
}

/** How far a run that asks got: its question unread (or without its owner run), stored but its run not stopped, or stored and stopped. */
function question(evidence: Evidence): "unread" | "unstopped" | "stopped" {
    const read = evidence.ask?.ask_exit === 0 ? parse(Asked, evidence.ask.ask) : null;
    if (read === null || !read.stoppable || ownerless(evidence)) return "unread";
    const stopped = evidence.interrupt?.interrupt_exit === 0 ? parse(Stopped, evidence.interrupt.interrupt) : null;
    return stopped?.interrupted === true ? "stopped" : "unstopped";
}

/** Whether the question read names a request that this turn's run does not own: one without an owner, or another run's. */
function ownerless(evidence: Evidence): boolean {
    const read = evidence.ask?.ask_exit === 0 ? parse(Asked, evidence.ask.ask) : null;
    const own = evidence.start === null ? null : parse(Started, evidence.start.start)?.runId ?? null;
    return read !== null && read.requests.some((request) => !request.run_id || request.run_id !== own);
}

/**
 * A run that waits for an answer: the phase stored its question, then stopped it. The turn asks for input with the question in its
 * result, and is over once T3 lists the run stopped. A question Orbit could not read was not stopped; a stop that failed says so.
 */
function asked(base: Omit<Turn, "state" | "error" | "error_code">, evidence: Evidence): Turn {
    const read = evidence.ask?.ask_exit === 0 ? parse(Asked, evidence.ask.ask) : null;
    const questions = (read?.requests ?? []).flatMap((request) => request.questions.map((question) => ({
        request_id: request.request_id,
        run_id: request.run_id ?? "",
        id: question.id,
        header: redactText(question.header),
        question: redactText(question.question),
        options: question.options.map((option) => ({ label: redactText(option.label), description: redactText(option.description), value: option.value ?? null })),
        multi_select: question.multiSelect ?? false,
        allow_custom_answer: question.allowCustomAnswer ?? null,
        initial_answer: question.initialAnswer === undefined ? null : redactText(question.initialAnswer),
        required: question.required ?? null,
    })));
    // A request whose owner run Orbit could not read stores no question: the answer turn could not cancel it exactly.
    const owned = !ownerless(evidence);
    const turn = { ...base, questions: owned ? questions : [], state: "asking_for_input" as const };
    if (read !== null && !read.stoppable) {
        return { ...turn, error: "The run waits for something Orbit cannot store whole (an approval, a request without a question, or too much text), "
            + "so Orbit did not stop the run.", error_code: "tasks.copy_question_unread" };
    }
    if (read === null) {
        const exit = evidence.ask === null ? "no read" : `exit ${evidence.ask.ask_exit}`;
        return { ...turn, error: `Orbit could not read the agent's question (${exit}), so it did not stop the run.`, error_code: "tasks.copy_question_unread" };
    }
    if (!owned) {
        return { ...turn, error: "Orbit could not read which run owns the agent's question, so it did not stop the run.", error_code: "tasks.copy_question_unread" };
    }
    const stopped = evidence.interrupt?.interrupt_exit === 0 ? parse(Stopped, evidence.interrupt.interrupt) : null;
    if (stopped?.interrupted !== true) {
        // The helper's own error stays in the run folder: only how the stop ended is named.
        const why = stopped !== null ? "T3 refused or did not end the stop" : evidence.interrupt === null ? "no stop ran" : `exit ${evidence.interrupt.interrupt_exit}`;
        return { ...turn, error: `Orbit could not stop the run that asks: ${why}`, error_code: "tasks.copy_question_unstopped" };
    }
    return { ...turn, error: null };
}

/** One evidence file, bounded and typed; a missing, oversized or malformed file reads as absent. */
async function evidence<T>(path: string, limit: number, schema: z.ZodType<T>): Promise<T | null> {
    try {
        const info = await Deno.stat(path);
        if (info.size > limit) return null;
        return parse(schema, new TextDecoder("utf-8", { fatal: true }).decode(await Deno.readFile(path)));
    } catch {
        return null;
    }
}

type Context = { writeResource(spec: string, name: string, value: unknown): Promise<unknown> };
const Result = z.object({ successful: z.boolean(), turn: Turn });

export const behavior = {
    type: "@nckrtl/task-copy-turn",
    version: "2026.11.20.1",
    resources: { result: { schema: Result, lifetime: "1d", garbageCollection: 10 } },
    methods: {
        result: {
            description: "Read, bound and redact the evidence of one turn in a task copy",
            arguments: z.object({ folder: Folder }),
            async execute(args: { folder: string }, ctx: Context) {
                const marked = (name: string) => Deno.stat(`${args.folder}/${name}`).then(() => true, () => false);
                const turn = Turn.parse(shapeTurn({
                    settle: await evidence(args.folder + "/settle.json", 65536, Settle),
                    resumed: await evidence(args.folder + "/continue.json", 1024, Resumed),
                    answered: await evidence(args.folder + "/answered.json", AskBytes, Answer),
                    review: await evidence(args.folder + "/review.json", 1024, Review),
                    model: await evidence(args.folder + "/model.json", 65536, Model),
                    detached: await marked("detach.json"),
                    launched: await marked("launch.json"),
                    start: await evidence(args.folder + "/start.json", StartBytes, Start),
                    ask: await evidence(args.folder + "/ask.json", AskBytes, Ask),
                    interrupt: await evidence(args.folder + "/interrupt.json", AskBytes, Interrupt),
                    stop: await evidence(args.folder + "/stop.json", 65536, Stop),
                    read: await evidence(args.folder + "/read.json", ReadBytes, Read),
                    usage: await evidence(args.folder + "/usage.json", AskBytes, UsageRead),
                    restore: await evidence(args.folder + "/restore.json", 1024, Restore),
                }));
                // A done turn, or one that asked and whose run Orbit stopped, succeeds.
                const result = { successful: turn.error === null && (turn.state === "done" || (turn.state === "asking_for_input" && turn.ended)), turn };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
