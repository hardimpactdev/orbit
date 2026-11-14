import { z } from "npm:zod@4.4.3";
import { redactText } from "../../_lib/public_output.ts";

/** The longest public transcript of one turn, as for a task VM turn. */
const TranscriptBytes = 262144;
const ErrorChars = 2000;
const StartBytes = 16777216;
const ReadBytes = 16777216;
const Folder = z.string().regex(/^\/tmp\/orbit-ansible-[0-9a-f-]{36}$/);

// What `task-copy-turn.yml` writes, each file as soon as its command ended: `launch.json` before the start, `start.json` with the
// exit code and stdout of `t3 thread start --wait --json`, and `read.json` with what `t3 thread list` found and `t3 thread show` read.
const Start = z.object({ start_exit: z.number().int(), start: z.string() }).strict();
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
export type Evidence = { launched: boolean; start: z.infer<typeof Start> | null; read: z.infer<typeof Read> | null };
const Started = z.object({
    threadId: z.string().min(1),
    runId: z.string().nullish(),
    // `--wait` prints the run's status; without a run, T3 prints only the thread.
    status: z.string().optional(),
    error: z.string().nullish(),
});
const Shown = z.object({
    thread: z.object({ threadId: z.string().min(1), status: z.string() }).passthrough(),
    messages: z.array(z.object({ role: z.enum(["user", "assistant"]), runId: z.string().nullish(), text: z.string() })),
});
const Entry = z.object({ id: z.string(), kind: z.literal("message"), label: z.string(), text: z.string(), at: z.string() });
export const Turn = z.object({
    state: z.enum(["done", "asking_for_input", "failed"]),
    error: z.string().nullable(),
    error_code: z.string().regex(/^tasks\.[a-z_]+$/).optional(),
    // Whether T3's run is known to be over. A run that may still go on in the copy keeps its group from more work until task:cancel.
    ended: z.boolean(),
    t3_thread_id: z.string().nullable(),
    run_id: z.string().nullable(),
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
 * The turn as Orbit records it. `t3 thread start --wait` ends with the run's status: `completed` is done and `waiting` asks for input.
 * Any other status fails the turn. No JSON after `timeout` killed the wait (124, or 137 after the kill grace) is the bound.
 */
export function shapeTurn(evidence: Evidence): Turn {
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
        transcript: transcript(shown),
    };
    if (started?.status === "completed") return { ...base, state: "done", error: null };
    if (started?.status === "waiting") return { ...base, state: "asking_for_input", error: null };
    if (started !== null) {
        const detail = redactText(started.error ?? "").slice(0, ErrorChars);
        const status = started.status === undefined ? "T3 started no run" : `T3 run ${started.status}`;
        return { ...base, state: "failed", error: `${status}${detail === "" ? "" : `: ${detail}`}`, error_code: "tasks.turn_failed" };
    }
    if (evidence.start !== null && [124, 137].includes(evidence.start.start_exit)) {
        return { ...base, state: "failed", error: "The turn ran longer than its bound; T3 may still run it until the copy is destroyed.", error_code: "tasks.turn_timeout" };
    }
    const exit = evidence.start === null ? "no exit" : `exit ${evidence.start.start_exit}`;
    return { ...base, state: "failed", error: `T3 did not report the turn's end (${exit}).`, error_code: "tasks.copy_turn_failed" };
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
    version: "2026.11.14.1",
    resources: { result: { schema: Result, lifetime: "1d", garbageCollection: 10 } },
    methods: {
        result: {
            description: "Read, bound and redact the evidence of one turn in a task copy",
            arguments: z.object({ folder: Folder }),
            async execute(args: { folder: string }, ctx: Context) {
                const launched = await Deno.stat(args.folder + "/launch.json").then(() => true, () => false);
                const turn = Turn.parse(shapeTurn({
                    launched,
                    start: await evidence(args.folder + "/start.json", StartBytes, Start),
                    read: await evidence(args.folder + "/read.json", ReadBytes, Read),
                }));
                const result = { successful: turn.state === "done", turn };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
