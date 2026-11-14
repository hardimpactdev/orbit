import { assertEquals } from "jsr:@std/assert@1";
import { behavior, type Evidence, shapeTurn } from "./mod.ts";

const thread = "mcp:66105c2c-4331-449b-90ef-e108d0a5db3d";
const run = "run:thread:mcp%3A66105c2c:ordinal:1";
// As `t3 thread show --json` answered in the hand proof on copy 2685, 2026-11-13.
const show = JSON.stringify({
    thread: { threadId: thread, status: "completed", latestRunId: run, providerInstanceId: "claudeAgent", model: "claude-opus-5-5" },
    messages: [{ role: "user", runId: run, text: "Subtask: Doctor note" }, { role: "assistant", runId: run, text: "Committed d867aba2." }],
});
const transcript: { id: string; kind: "message"; label: string; text: string; at: string }[] = [
    { id: `${run}:0`, kind: "message", label: "user", text: "Subtask: Doctor note", at: "" },
    { id: `${run}:1`, kind: "message", label: "assistant", text: "Committed d867aba2.", at: "" },
];
const started = (status: string, error: string | null = null) =>
    JSON.stringify({ threadId: thread, runId: run, status, reply: status === "completed" ? "Committed d867aba2." : null, error });
const read = { list_exit: 0, found: 1, show_exit: 0, show };
const turn = (start_exit: number, start: string, change: Partial<Evidence> = {}) => shapeTurn({ launched: true, start: { start_exit, start }, read, ...change });

Deno.test("a completed run is done and over, and records the message transcript and the T3 thread", () => {
    assertEquals(turn(0, started("completed")), { state: "done", error: null, ended: true, t3_thread_id: thread, run_id: run, transcript });
});

Deno.test("a run that waits for an answer asks for input; any other end fails the turn with T3's status", () => {
    assertEquals(turn(2, started("waiting")).state, "asking_for_input");
    assertEquals(turn(1, started("failed", "api_error_529")), {
        state: "failed", error: "T3 run failed: api_error_529", error_code: "tasks.turn_failed", ended: true, t3_thread_id: thread, run_id: run, transcript,
    });
    // Without a run T3 prints only the thread, with no status and no run id.
    assertEquals(turn(0, JSON.stringify({ threadId: thread, projectId: "p", runId: null })).error, "T3 started no run");
});

Deno.test("a wait that the bound killed is tasks.turn_timeout, records the thread found by its title, and is not over", () => {
    for (const exit of [124, 137]) {
        const cut = turn(exit, "");
        assertEquals([cut.state, cut.error_code, cut.ended, cut.t3_thread_id, cut.transcript.length], ["failed", "tasks.turn_timeout", false, thread, 2]);
    }
});

Deno.test("a phase cut before the start's end is over only when nothing was launched or T3 holds no such thread", () => {
    const cut = shapeTurn({ launched: true, start: null, read: null });
    assertEquals([cut.state, cut.error_code, cut.ended], ["failed", "tasks.copy_turn_failed", false]);
    assertEquals(shapeTurn({ launched: false, start: null, read: null }).ended, true);
    assertEquals(shapeTurn({ launched: true, start: { start_exit: 1, start: "" }, read: { list_exit: 0, found: 0, show_exit: null, show: "" } }), {
        state: "failed", error: "T3 did not report the turn's end (exit 1).", error_code: "tasks.copy_turn_failed", ended: true, t3_thread_id: null, run_id: null, transcript: [],
    });
    assertEquals(shapeTurn({ launched: true, start: { start_exit: 1, start: "" }, read: { list_exit: null, found: 0, show_exit: null, show: "" } }).ended, false);
});

Deno.test("a message without a run id keeps the transcript", () => {
    const shown = JSON.stringify({ thread: { threadId: thread, status: "completed" }, messages: [{ role: "user", text: "Hello" }] });
    assertEquals(turn(0, started("completed"), { read: { ...read, show: shown } }).transcript, [{ id: "run:0", kind: "message", label: "user", text: "Hello", at: "" }]);
});

Deno.test("transcript text is redacted and bounded to the newest entries", () => {
    const secret = "ghp_" + "a1B2".repeat(9);
    const long = JSON.stringify({ thread: { threadId: thread, status: "completed" },
        messages: Array.from({ length: 400 }, (_, index) => ({ role: "assistant", runId: run, text: `${index} ${secret} ${"x".repeat(1000)}` })) });
    const bounded = turn(0, started("completed"), { read: { ...read, show: long } });
    assertEquals(bounded.transcript[0].id, "orbit-truncated");
    assertEquals(bounded.transcript.at(-1)!.id, `${run}:399`);
    assertEquals(bounded.transcript.some((entry) => entry.text.includes(secret)), false);
    assertEquals(new TextEncoder().encode(JSON.stringify(bounded.transcript)).length < 262144 + 1024, true);
});

Deno.test("the method reads each evidence file on its own, so an oversized read keeps the start's end", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    Deno.mkdirSync(folder);
    try {
        const written: unknown[] = [];
        const context = { writeResource: (_spec: string, _name: string, value: unknown) => Promise.resolve(written.push(value)) };
        const nothing = await behavior.methods.result.execute({ folder }, context);
        assertEquals([nothing.successful, nothing.turn.error_code, nothing.turn.ended], [false, "tasks.copy_turn_failed", true]);
        Deno.writeTextFileSync(`${folder}/launch.json`, "{}");
        Deno.writeTextFileSync(`${folder}/start.json`, JSON.stringify({ start_exit: 0, start: started("completed") }));
        Deno.writeTextFileSync(`${folder}/read.json`, JSON.stringify({ ...read, show: "x".repeat(16777217) }));
        const done = await behavior.methods.result.execute({ folder }, context);
        assertEquals([done.successful, done.turn.state, done.turn.ended, done.turn.transcript.length, written.length], [true, "done", true, 0, 2]);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
