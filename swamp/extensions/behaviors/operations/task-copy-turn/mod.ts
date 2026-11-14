import { z } from "npm:zod@4.4.3";
import { redactText } from "../../_lib/public_output.ts";

/** The longest public transcript of one turn, as for a task VM turn. */
const TranscriptBytes = 262144;
const ErrorChars = 2000;
const StartBytes = 16777216;
const ReadBytes = 16777216;
const AskBytes = 1048576;
const Folder = z.string().regex(/^\/tmp\/orbit-ansible-[0-9a-f-]{36}$/);

// What `task-copy-turn.yml` writes, each file as soon as its command ended: `settle.json` with the earlier turns it found settled and
// those still open, for a continued turn `continue.json` with the exit code of
// `t3 thread list` (null when it printed no JSON) and how many T3 threads carry its title, for a reviewer turn `review.json` with whether
// the candidate is checked out, `detach.json` as soon as the reviewer's candidate is checked out, `launch.json` before the start, `start.json` with the exit code and stdout of `t3 thread start` or
// `t3 thread send` with `--wait --json`, for a run that waits for an answer `ask.json` with the exit code and stdout of `t3_copy_rpc.mjs
// read` and `interrupt.json` with those of `t3_copy_rpc.mjs interrupt`, `read.json` with what `t3 thread list` found and `t3 thread show`
// read, and for a reviewer turn `restore.json` with whether the checkout is clean on its branch at its tip again.
const Settle = z.object({ list_exit: z.number().int().nullable(), settled: z.array(z.number().int()), open: z.array(z.number().int()) }).strict();
const Resumed = z.object({ list_exit: z.number().int().nullable(), found: z.number().int() }).strict();
const Review = z.object({ ready: z.boolean(), error_code: z.enum(["tasks.copy_tree_dirty", "tasks.copy_turn_failed"]).nullable() }).strict();
const Restore = z.object({ restored: z.boolean() }).strict();
const Start = z.object({ start_exit: z.number().int(), start: z.string() }).strict();
const Ask = z.object({ ask_exit: z.number().int(), ask: z.string() }).strict();
const Interrupt = z.object({ interrupt_exit: z.number().int(), interrupt: z.string() }).strict();
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
// What `t3_copy_rpc.mjs read` prints: the runs that are not over, every pending request with its questions, and whether the phase may
// stop the run on them (questions only, stored whole).
const Asked = z.object({ runs: z.array(Run), requests: z.array(z.object({ request_id: z.string(), kind: z.string(), questions: z.array(Question) })),
    stoppable: z.boolean() });
// What `t3_copy_rpc.mjs interrupt` prints once the run ended or its wait for that did.
const Stopped = z.object({ interrupted: z.boolean(), error: z.string().nullable(), runs: z.array(Run), pending: z.number().int() });
const Read = z.object({
    list_exit: z.number().int().nullable(),
    found: z.number().int(),
    // The status `t3 thread list` gives the thread of this turn's title.
    status: z.string().nullable(),
    show_exit: z.number().int().nullable(),
    show: z.string(),
}).strict();
/** Thread states in which T3's run may still change the checkout. */
const Active = new Set(["preparing", "queued", "starting", "running", "waiting"]);
export type Evidence = {
    settle?: z.infer<typeof Settle> | null;
    resumed: z.infer<typeof Resumed> | null;
    review: z.infer<typeof Review> | null;
    detached: boolean;
    launched: boolean;
    start: z.infer<typeof Start> | null;
    ask: z.infer<typeof Ask> | null;
    interrupt: z.infer<typeof Interrupt> | null;
    read: z.infer<typeof Read> | null;
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
    // Whether the phase launched T3 on the thread: a turn that launched nothing leaves the thread as it was.
    launched: z.boolean(),
    // The thread's provider instance and model as T3 shows them; the Gateway adds the effort the instance carries.
    provider_instance: z.string().nullable(),
    model: z.string().nullable(),
    // What the agent asked, in its own words and options: set when the turn ends at a question.
    questions: z.array(AskedQuestion),
    // The earlier turns whose run this turn's phase found over, with a reviewer's detach undone.
    settled: z.array(z.number().int()),
    transcript: z.array(Entry),
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

/** Message text only: T3's CLI gives no tool items and no token use. Newest entries first within the bound. */
function transcript(shown: z.infer<typeof Shown> | null): Turn["transcript"] {
    const entries: Turn["transcript"] = [];
    let size = 0;
    const messages = shown?.messages ?? [];
    for (let index = messages.length - 1; index >= 0; index--) {
        const message = messages[index];
        const entry = { id: `${message.runId ?? "run"}:${index}`, kind: "message" as const, label: message.role, text: redactText(message.text), at: "" };
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
    return { ...turn, state: "failed", ended: false, error: `The checkout was not restored after the reviewer's turn.${run}`, error_code: "tasks.copy_review_unrestored" };
}

/**
 * The run as T3 reported it. `t3 thread start --wait` and `t3 thread send --wait` end with the run's status: `completed` is done and
 * `waiting` asks for input. Any other status fails the turn. No JSON after `timeout` killed the wait (124, or 137 after the kill grace)
 * is the bound.
 */
function shapeRun(evidence: Evidence): Turn {
    const started = evidence.start === null ? null : parse(Started, evidence.start.start);
    const shown = evidence.read?.show_exit === 0 ? parse(Shown, evidence.read.show) : null;
    // Over when nothing was launched, when the start printed an end that is not a wait for an answer, or when T3 holds no thread
    // of this title or holds it in no active state. Anything else may still write in the checkout.
    const listed = evidence.read?.list_exit === 0 ? evidence.read : null;
    const ended = !evidence.launched || (started !== null && started.status !== "waiting")
        || (listed !== null && (listed.found === 0 || (listed.found === 1 && listed.status !== null && !Active.has(listed.status))));
    const base = {
        ended,
        t3_thread_id: started?.threadId ?? shown?.thread.threadId ?? null,
        run_id: started?.runId ?? null,
        launched: evidence.launched,
        provider_instance: shown?.thread.providerInstanceId ?? null,
        model: shown?.thread.model ?? null,
        questions: [],
        settled: evidence.settle?.settled ?? [],
        transcript: transcript(shown),
    };
    if (evidence.settle && evidence.settle.open.length > 0) {
        return { ...base, state: "failed", error: `An earlier turn may still run in the copy (Operation ${evidence.settle.open.join(", ")}); nothing was launched.`,
            error_code: "tasks.copy_turn_unended" };
    }
    if (started?.status === "completed") return { ...base, state: "done", error: null };
    if (started?.status === "waiting") return asked(base, evidence);
    if (started !== null) {
        const detail = redactText(started.error ?? "").slice(0, ErrorChars);
        const status = started.status === undefined ? "T3 started no run" : `T3 run ${started.status}`;
        return { ...base, state: "failed", error: `${status}${detail === "" ? "" : `: ${detail}`}`, error_code: "tasks.turn_failed" };
    }
    if (evidence.start !== null && [124, 137].includes(evidence.start.start_exit)) {
        return { ...base, state: "failed", error: "The turn ran longer than its bound; T3 may still run it until the copy is destroyed.", error_code: "tasks.turn_timeout" };
    }
    if (evidence.resumed !== null && evidence.resumed.list_exit !== 0) {
        const exit = evidence.resumed.list_exit === null ? "no JSON" : `exit ${evidence.resumed.list_exit}`;
        return { ...base, state: "failed", error: `T3 could not list its threads to find the one to continue (${exit}).`, error_code: "tasks.copy_turn_failed" };
    }
    if (evidence.resumed !== null && evidence.resumed.found !== 1) {
        return { ...base, state: "failed", error: `T3 holds ${evidence.resumed.found} threads of this title to continue, not one.`, error_code: "tasks.copy_thread_missing" };
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
    return { ...base, state: "failed", error: `T3 did not report the turn's end (${exit}).`, error_code: "tasks.copy_turn_failed" };
}

/**
 * A run that waits for an answer: the phase stored its question, then stopped it. The turn asks for input with the question in its
 * result, and is over once T3 lists the run stopped. A question Orbit could not read was not stopped; a stop that failed says so.
 */
function asked(base: Omit<Turn, "state" | "error" | "error_code">, evidence: Evidence): Turn {
    const read = evidence.ask?.ask_exit === 0 ? parse(Asked, evidence.ask.ask) : null;
    const questions = (read?.requests ?? []).flatMap((request) => request.questions.map((question) => ({
        request_id: request.request_id,
        id: question.id,
        header: redactText(question.header),
        question: redactText(question.question),
        options: question.options.map((option) => ({ label: redactText(option.label), description: redactText(option.description), value: option.value ?? null })),
        multi_select: question.multiSelect ?? false,
        allow_custom_answer: question.allowCustomAnswer ?? null,
        initial_answer: question.initialAnswer === undefined ? null : redactText(question.initialAnswer),
        required: question.required ?? null,
    })));
    const turn = { ...base, questions, state: "asking_for_input" as const };
    if (read !== null && !read.stoppable) {
        return { ...turn, error: "The run waits for something Orbit cannot store whole (an approval, a request without a question, or too much text), "
            + "so Orbit did not stop the run.", error_code: "tasks.copy_question_unread" };
    }
    if (read === null) {
        const exit = evidence.ask === null ? "no read" : `exit ${evidence.ask.ask_exit}`;
        return { ...turn, error: `Orbit could not read the agent's question (${exit}), so it did not stop the run.`, error_code: "tasks.copy_question_unread" };
    }
    const stopped = evidence.interrupt?.interrupt_exit === 0 ? parse(Stopped, evidence.interrupt.interrupt) : null;
    if (stopped?.interrupted !== true) {
        const why = stopped?.error ?? (evidence.interrupt === null ? "no stop ran" : `exit ${evidence.interrupt.interrupt_exit}`);
        return { ...turn, error: `Orbit could not stop the run that asks: ${redactText(why).slice(0, ErrorChars)}`, error_code: "tasks.copy_question_unstopped" };
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
    version: "2026.11.14.6",
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
                    review: await evidence(args.folder + "/review.json", 1024, Review),
                    detached: await marked("detach.json"),
                    launched: await marked("launch.json"),
                    start: await evidence(args.folder + "/start.json", StartBytes, Start),
                    ask: await evidence(args.folder + "/ask.json", AskBytes, Ask),
                    interrupt: await evidence(args.folder + "/interrupt.json", AskBytes, Interrupt),
                    read: await evidence(args.folder + "/read.json", ReadBytes, Read),
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
