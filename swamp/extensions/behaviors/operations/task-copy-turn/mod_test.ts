import { assertEquals } from "jsr:@std/assert@1";
import { behavior, shapeTurn } from "./mod.ts";

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

Deno.test("a completed run is done and records the message transcript and the T3 thread", () => {
    assertEquals(shapeTurn({ start_exit: 0, start: started("completed"), show_exit: 0, show }), {
        state: "done", error: null, t3_thread_id: thread, run_id: run, transcript,
    });
});

Deno.test("a run that waits for an answer asks for input; any other end fails the turn with T3's status", () => {
    assertEquals(shapeTurn({ start_exit: 2, start: started("waiting"), show_exit: 0, show }).state, "asking_for_input");
    assertEquals(shapeTurn({ start_exit: 1, start: started("failed", "api_error_529"), show_exit: 0, show }), {
        state: "failed", error: "T3 run failed: api_error_529", error_code: "tasks.turn_failed", t3_thread_id: thread, run_id: run, transcript,
    });
});

Deno.test("a wait that the bound killed is tasks.turn_timeout and still records the thread found by its title", () => {
    for (const exit of [124, 137]) {
        const turn = shapeTurn({ start_exit: exit, start: "", show_exit: 0, show });
        assertEquals([turn.state, turn.error_code, turn.t3_thread_id, turn.transcript.length], ["failed", "tasks.turn_timeout", thread, 2]);
    }
});

Deno.test("a start that T3 refused, or a phase that wrote nothing, fails without a thread", () => {
    assertEquals(shapeTurn({ start_exit: 1, start: "", show_exit: null, show: "" }), {
        state: "failed", error: "T3 did not start the turn (exit 1).", error_code: "tasks.copy_turn_failed", t3_thread_id: null, run_id: null, transcript: [],
    });
});

Deno.test("transcript text is redacted and bounded to the newest entries", () => {
    const secret = "ghp_" + "a1B2".repeat(9);
    const long = JSON.stringify({ thread: { threadId: thread, status: "completed" },
        messages: Array.from({ length: 400 }, (_, index) => ({ role: "assistant", runId: run, text: `${index} ${secret} ${"x".repeat(1000)}` })) });
    const turn = shapeTurn({ start_exit: 0, start: started("completed"), show_exit: 0, show: long });
    assertEquals(turn.transcript[0].id, "orbit-truncated");
    assertEquals(turn.transcript.at(-1)!.id, `${run}:399`);
    assertEquals(turn.transcript.some((entry) => entry.text.includes(secret)), false);
    assertEquals(new TextEncoder().encode(JSON.stringify(turn.transcript)).length < 262144 + 1024, true);
});

Deno.test("the method reads the run folder, keeps a missing file as a failed start, and writes its typed resource", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    Deno.mkdirSync(folder);
    try {
        const written: unknown[] = [];
        const context = { writeResource: (_spec: string, _name: string, value: unknown) => Promise.resolve(written.push(value)) };
        const missing = await behavior.methods.result.execute({ folder }, context);
        assertEquals([missing.successful, missing.turn.error_code], [false, "tasks.copy_turn_failed"]);
        Deno.writeTextFileSync(`${folder}/result.json`, JSON.stringify({ start_exit: 0, start: started("completed"), show_exit: 0, show }));
        const done = await behavior.methods.result.execute({ folder }, context);
        assertEquals([done.successful, done.turn.state, written.length], [true, "done", 2]);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
