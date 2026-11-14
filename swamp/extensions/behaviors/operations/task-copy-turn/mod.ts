import { z } from "npm:zod@4.4.3";
import { redactText } from "../../_lib/public_output.ts";

/** The longest public transcript of one turn, as for a task VM turn. */
const TranscriptBytes = 262144;
const ErrorChars = 2000;
const Folder = z.string().regex(/^\/tmp\/orbit-ansible-[0-9a-f-]{36}$/);

// What `task-copy-turn.yml` writes: the exit code and stdout of `t3 thread start --wait --json`, and of `t3 thread show --json`.
const Evidence = z.object({
    start_exit: z.number().int(),
    start: z.string(),
    show_exit: z.number().int().nullable(),
    show: z.string(),
}).strict();
const Started = z.object({
    threadId: z.string().min(1),
    runId: z.string().nullable().optional(),
    status: z.string(),
    reply: z.string().nullable().optional(),
    error: z.string().nullable().optional(),
});
const Shown = z.object({
    thread: z.object({ threadId: z.string().min(1), status: z.string() }).passthrough(),
    messages: z.array(z.object({ role: z.enum(["user", "assistant"]), runId: z.string().nullable(), text: z.string() })),
});
const Entry = z.object({ id: z.string(), kind: z.literal("message"), label: z.string(), text: z.string(), at: z.string() });
export const Turn = z.object({
    state: z.enum(["done", "asking_for_input", "failed"]),
    error: z.string().nullable(),
    error_code: z.string().regex(/^tasks\.[a-z_]+$/).optional(),
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
export function shapeTurn(evidence: z.infer<typeof Evidence>): Turn {
    const started = parse(Started, evidence.start);
    const shown = evidence.show_exit === 0 ? parse(Shown, evidence.show) : null;
    const base = {
        t3_thread_id: started?.threadId ?? shown?.thread.threadId ?? null,
        run_id: started?.runId ?? null,
        transcript: transcript(shown),
    };
    if (started?.status === "completed") return { ...base, state: "done", error: null };
    if (started?.status === "waiting") return { ...base, state: "asking_for_input", error: null };
    if (started !== null) {
        const detail = redactText(started.error ?? "").slice(0, ErrorChars);
        return { ...base, state: "failed", error: `T3 run ${started.status}${detail === "" ? "" : `: ${detail}`}`, error_code: "tasks.turn_failed" };
    }
    if ([124, 137].includes(evidence.start_exit)) {
        return { ...base, state: "failed", error: "The turn ran longer than its bound; T3 may still run it until the copy is destroyed.", error_code: "tasks.turn_timeout" };
    }
    return { ...base, state: "failed", error: `T3 did not start the turn (exit ${evidence.start_exit}).`, error_code: "tasks.copy_turn_failed" };
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
                let raw: string;
                try {
                    const bytes = await Deno.readFile(args.folder + "/result.json");
                    if (bytes.length > 8388608) throw new Error("too large");
                    raw = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
                } catch {
                    // The phase failed before it wrote its evidence: an invalid intent or an unreachable Node.
                    raw = JSON.stringify({ start_exit: -1, start: "", show_exit: null, show: "" });
                }
                const evidence = Evidence.parse(JSON.parse(raw));
                const turn = Turn.parse(shapeTurn(evidence));
                const result = { successful: turn.state === "done", turn };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
