import { assert, assertEquals } from "jsr:@std/assert@1";
import { behavior, type Evidence, shapeTurn } from "./mod.ts";

const thread = "mcp:66105c2c-4331-449b-90ef-e108d0a5db3d";
const run = "run:thread:mcp%3A66105c2c:ordinal:1";
// As `t3 thread show --json` answered in the hand proof on copy 2685, 2026-11-13.
const show = JSON.stringify({
    thread: { threadId: thread, status: "completed", latestRunId: run, providerInstanceId: "claudeMedium", model: "claude-opus-5-5" },
    messages: [{ role: "user", runId: run, text: "Subtask: Doctor note" }, { role: "assistant", runId: run, text: "Committed d867aba2." }],
});
const transcript: { id: string; kind: "message"; label: string; text: string; at: string }[] = [
    { id: `${run}:0`, kind: "message", label: "user", text: "Subtask: Doctor note", at: "" },
    { id: `${run}:1`, kind: "message", label: "assistant", text: "Committed d867aba2.", at: "" },
];
const started = (status: string, error: string | null = null) =>
    JSON.stringify({ threadId: thread, runId: run, status, reply: status === "completed" ? "Committed d867aba2." : null, error });
const read = { list_exit: 0, found: 1, status: "completed", show_exit: 0, show };
const none = { resumed: null, review: null, detached: false, ask: null, interrupt: null, restore: null };
// The provider instance and model as T3 showed them, and no question.
const named = { launched: true, provider_instance: "claudeMedium", model: "claude-opus-5-5", questions: [], settled: [] };
const unlaunched = { launched: false, provider_instance: null, model: null, questions: [], settled: [] };
const unnamed = { launched: true, provider_instance: null, model: null, questions: [], settled: [] };
// As `t3_copy_rpc.mjs read` printed it on a stock T3 0.1.7 server, 2026-11-14: one request with two questions.
const request = "runtime-request:provider:claudeAgent:native-request:toolu_01TbF9dLFChexV5BVoUsSdii";
const asked = {
    runs: [{ id: run, status: "running" }],
    requests: [{ request_id: request, kind: "user_input", questions: [
        { id: "Which color?", header: "Color", question: "Which color?", options: [{ label: "Red", description: "Choose red" }, { label: "Blue", description: "Choose blue" }], multiSelect: false },
        { id: "Which shape?", header: "Shape", question: "Which shape?", options: [{ label: "Circle", description: "Choose circle", value: "circle" }], allowCustomAnswer: true, required: true },
    ] }],
    stoppable: true,
};
const questions = [
    { request_id: request, id: "Which color?", header: "Color", question: "Which color?", options: [{ label: "Red", description: "Choose red", value: null }, { label: "Blue", description: "Choose blue", value: null }],
        multi_select: false, allow_custom_answer: null, initial_answer: null, required: null },
    { request_id: request, id: "Which shape?", header: "Shape", question: "Which shape?", options: [{ label: "Circle", description: "Choose circle", value: "circle" }],
        multi_select: false, allow_custom_answer: true, initial_answer: null, required: true },
];
const ask = { ask_exit: 0, ask: JSON.stringify(asked) };
const stopped = { interrupt_exit: 0, interrupt: JSON.stringify({ interrupted: true, error: null, runs: [], pending: 0 }) };
const turn = (start_exit: number, start: string, change: Partial<Evidence> = {}) => shapeTurn({ ...none, launched: true, start: { start_exit, start }, read, ...change });

Deno.test("a completed run is done and over, and records the message transcript and the T3 thread", () => {
    assertEquals(turn(0, started("completed")), { state: "done", error: null, ended: true, t3_thread_id: thread, run_id: run, ...named, transcript });
});

Deno.test("a run that asks has its question stored and its run stopped: it asks for input, is over, and keeps every question in its own words", () => {
    // After the stop, T3 lists the thread as interrupted.
    assertEquals(turn(2, started("waiting"), { ask, interrupt: stopped, read: { ...read, status: "interrupted" } }), {
        state: "asking_for_input", error: null, ended: true, t3_thread_id: thread, run_id: run, ...named, questions, transcript,
    });
    // A question's text is redacted like the transcript.
    const secret = "ghp_" + "a1B2".repeat(9);
    const leaked = { ...asked, requests: [{ ...asked.requests[0], questions: [{ ...asked.requests[0].questions[0], question: `Use ${secret}?` }] }] };
    const redacted = turn(2, started("waiting"), { ask: { ask_exit: 0, ask: JSON.stringify(leaked) }, interrupt: stopped, read: { ...read, status: "interrupted" } });
    assertEquals([redacted.questions.length, redacted.questions[0].question.includes(secret)], [1, false]);
});

Deno.test("an earlier turn still running launches nothing and fails tasks.copy_turn_unended; the turns found settled are named", () => {
    const held = shapeTurn({ ...none, settle: { list_exit: 0, settled: [11], open: [12] }, launched: false, start: null, read: null });
    assertEquals(held, { state: "failed", error: "An earlier turn may still run in the copy (Operation 12); nothing was launched.", error_code: "tasks.copy_turn_unended",
        ended: true, t3_thread_id: null, run_id: null, ...unlaunched, settled: [11], transcript: [] });
    assertEquals(turn(0, started("completed"), { settle: { list_exit: 0, settled: [11, 12], open: [] } }),
        { state: "done", error: null, ended: true, t3_thread_id: thread, run_id: run, ...named, settled: [11, 12], transcript });
});

Deno.test("a free-text question with no options is stored whole, so the run it stopped asks for input and is over", () => {
    const free = { ...asked, requests: [{ request_id: request, kind: "user_input", questions: [{ id: "Why?", header: "", question: "Why does it fail?", options: [] }] }] };
    const stored = turn(2, started("waiting"), { ask: { ask_exit: 0, ask: JSON.stringify(free) }, interrupt: stopped, read: { ...read, status: "interrupted" } });
    assertEquals([stored.state, stored.error, stored.ended, stored.questions], ["asking_for_input", null, true, [{ request_id: request, id: "Why?", header: "",
        question: "Why does it fail?", options: [], multi_select: false, allow_custom_answer: null, initial_answer: null, required: null }]]);
});

Deno.test("a question Orbit could not read is not stopped and not over; a stop that failed says so and is not over", () => {
    const running = { ...read, status: "running" };
    for (const change of [{}, { ask: { ask_exit: 1, ask: "" } }, { ask: { ask_exit: 0, ask: "not json" } }]) {
        const unread = turn(2, started("waiting"), { read: running, ...change });
        assertEquals([unread.state, unread.error_code, unread.ended, unread.questions], ["asking_for_input", "tasks.copy_question_unread", false, []], JSON.stringify(change));
        assert(unread.error!.startsWith("Orbit could not read the agent's question"));
    }
    // An approval, or a request with no question, is not stored whole: the phase did not stop the run, and the error says why.
    const approval = { ...asked, requests: [{ request_id: "request:2", kind: "approval", questions: [] }], stoppable: false };
    const kept = turn(2, started("waiting"), { read: running, ask: { ask_exit: 0, ask: JSON.stringify(approval) } });
    assertEquals([kept.error_code, kept.ended, kept.error!.includes("approval")], ["tasks.copy_question_unread", false, true]);
    const refused = turn(2, started("waiting"), { ask, read: running,
        interrupt: { interrupt_exit: 0, interrupt: JSON.stringify({ interrupted: false, error: "The run was no longer waiting for this question.", runs: [{ id: run, status: "running" }], pending: 0 }) } });
    assertEquals([refused.state, refused.error_code, refused.ended, refused.error, refused.questions], ["asking_for_input", "tasks.copy_question_unstopped", false,
        "Orbit could not stop the run that asks: The run was no longer waiting for this question.", questions]);
    const cut = turn(2, started("waiting"), { ask, read: running, interrupt: { interrupt_exit: 124, interrupt: "" } });
    assertEquals([cut.error_code, cut.error, cut.ended], ["tasks.copy_question_unstopped", "Orbit could not stop the run that asks: exit 124", false]);
    assertEquals(turn(2, started("waiting"), { ask, read: running }).error, "Orbit could not stop the run that asks: no stop ran");
});

Deno.test("a run that ends other than done or asking fails the turn with T3's status", () => {
    assertEquals(turn(1, started("failed", "api_error_529")), {
        state: "failed", error: "T3 run failed: api_error_529", error_code: "tasks.turn_failed", ended: true, t3_thread_id: thread, run_id: run, ...named, transcript,
    });
    // Without a run T3 prints only the thread, with no status and no run id.
    assertEquals(turn(0, JSON.stringify({ threadId: thread, projectId: "p", runId: null })).error, "T3 started no run");
});

Deno.test("a wait that the bound killed is tasks.turn_timeout, records the thread found by its title, and is over only when T3's thread is", () => {
    for (const exit of [124, 137]) {
        const cut = turn(exit, "", { read: { ...read, status: "running" } });
        assertEquals([cut.state, cut.error_code, cut.ended, cut.t3_thread_id, cut.transcript.length], ["failed", "tasks.turn_timeout", false, thread, 2]);
    }
    // The run ended inside the kill grace: T3 lists the thread as completed.
    assertEquals(turn(124, "").ended, true);
    assertEquals(turn(124, "", { read: { ...read, status: null } }).ended, false);
});

Deno.test("a phase cut before the start's end is over only when nothing was launched or T3 holds no such thread", () => {
    const cut = shapeTurn({ ...none, launched: true, start: null, read: null });
    assertEquals([cut.state, cut.error_code, cut.ended], ["failed", "tasks.copy_turn_failed", false]);
    assertEquals(shapeTurn({ ...none, launched: false, start: null, read: null }).ended, true);
    assertEquals(shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "" }, read: { list_exit: 0, found: 0, status: null, show_exit: null, show: "" } }), {
        state: "failed", error: "T3 did not report the turn's end (exit 1).", error_code: "tasks.copy_turn_failed", ended: true, t3_thread_id: null, run_id: null, ...unnamed,
        transcript: [],
    });
    assertEquals(shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "" }, read: { list_exit: null, found: 0, status: null, show_exit: null, show: "" } }).ended, false);
    // T3 made the thread but refused its run: the listed thread is idle.
    assertEquals(shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "" }, read: { ...read, status: "idle" } }).ended, true);
});

Deno.test("a continued turn reads like a new one, and one whose thread T3 does not hold is tasks.copy_thread_missing with nothing launched", () => {
    assertEquals(turn(0, started("completed"), { resumed: { list_exit: 0, found: 1 } }), { state: "done", error: null, ended: true, t3_thread_id: thread, run_id: run, ...named, transcript });
    for (const found of [0, 2]) {
        assertEquals(shapeTurn({ ...none, resumed: { list_exit: 0, found }, launched: false, start: null, read: null }), {
            state: "failed", error: `T3 holds ${found} threads of this title to continue, not one.`, error_code: "tasks.copy_thread_missing", ended: true,
            t3_thread_id: null, run_id: null, ...unlaunched, transcript: [],
        });
    }
});

Deno.test("a continued turn whose thread list failed or printed no JSON is tasks.copy_turn_failed, not a missing thread", () => {
    for (const [list_exit, detail] of [[1, "exit 1"], [null, "no JSON"]] as const) {
        assertEquals(shapeTurn({ ...none, resumed: { list_exit, found: 0 }, launched: false, start: null, read: null }), {
            state: "failed", error: `T3 could not list its threads to find the one to continue (${detail}).`, error_code: "tasks.copy_turn_failed", ended: true,
            t3_thread_id: null, run_id: null, ...unlaunched, transcript: [],
        });
    }
});

Deno.test("a reviewer turn fails when its checkout could not be put on the candidate or was not restored after a run T3 ended", () => {
    const ready = { ready: true, error_code: null };
    assertEquals(turn(0, started("completed"), { review: ready, detached: true, restore: { restored: true } }).state, "done");
    const asking = turn(2, started("waiting"), { review: ready, detached: true, restore: { restored: true }, ask, interrupt: stopped, read: { ...read, status: "interrupted" } });
    assertEquals([asking.state, asking.ended], ["asking_for_input", true]);
    // A reviewer that asked and whose checkout was not restored holds the group like any unrestored turn.
    const unrestored = turn(2, started("waiting"), { review: ready, detached: true, restore: { restored: false }, ask, interrupt: stopped, read: { ...read, status: "interrupted" } });
    assertEquals([unrestored.error_code, unrestored.ended], ["tasks.copy_review_unrestored", false]);
    for (const restore of [{ restored: false }, null]) {
        assertEquals(turn(0, started("completed"), { review: ready, detached: true, restore }), {
            state: "failed", error: "The checkout was not restored after the reviewer's turn.", error_code: "tasks.copy_review_unrestored", ended: false,
            t3_thread_id: thread, run_id: run, ...named, transcript,
        });
    }
    assertEquals(shapeTurn({ ...none, review: { ready: false, error_code: "tasks.copy_tree_dirty" }, launched: false, start: null, read: null }), {
        state: "failed", error: "The checkout had changes or no branch, so the reviewer could not be put on the candidate.", error_code: "tasks.copy_tree_dirty",
        ended: true, t3_thread_id: null, run_id: null, ...unlaunched, transcript: [],
    });
    assertEquals(shapeTurn({ ...none, review: { ready: false, error_code: "tasks.copy_turn_failed" }, launched: false, start: null, read: null }).error_code,
        "tasks.copy_turn_failed");
});

Deno.test("a detached reviewer checkout without a restore holds the group whatever the run did", () => {
    const ready = { ready: true, error_code: null };
    // (B) The run failed and the restore could not reset the checkout: the turn is unrestored, not only failed, and holds the group.
    const failed = turn(1, started("failed", "api_error_529"), { review: ready, detached: true, restore: { restored: false } });
    assertEquals([failed.state, failed.error_code, failed.ended, failed.error],
        ["failed", "tasks.copy_review_unrestored", false, "The checkout was not restored after the reviewer's turn. The run: T3 run failed: api_error_529"]);
    // (C) A cancel cut the phase after the detach and before the launch mark: nothing ran, but nothing was restored either.
    const cut = shapeTurn({ ...none, review: null, detached: true, launched: false, start: null, read: null });
    assertEquals([cut.state, cut.error_code, cut.ended], ["failed", "tasks.copy_review_unrestored", false]);
    // A run cut by its bound and restored stays held by its run, with its own error.
    const timeout = turn(124, "", { review: ready, detached: true, restore: { restored: true }, read: { ...read, status: "running" } });
    assertEquals([timeout.error_code, timeout.ended], ["tasks.turn_timeout", false]);
    // A failed run whose checkout was restored keeps its own error and is free.
    const restored = turn(1, started("failed", "api_error_529"), { review: ready, detached: true, restore: { restored: true } });
    assertEquals([restored.error_code, restored.ended], ["tasks.turn_failed", true]);
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
        Deno.writeTextFileSync(`${folder}/continue.json`, JSON.stringify({ list_exit: 0, found: 0 }));
        const missing = await behavior.methods.result.execute({ folder }, context);
        assertEquals([missing.successful, missing.turn.error_code, missing.turn.ended], [false, "tasks.copy_thread_missing", true]);
        Deno.writeTextFileSync(`${folder}/continue.json`, JSON.stringify({ list_exit: 0, found: 1 }));
        Deno.writeTextFileSync(`${folder}/launch.json`, "{}");
        Deno.writeTextFileSync(`${folder}/start.json`, JSON.stringify({ start_exit: 0, start: started("completed") }));
        Deno.writeTextFileSync(`${folder}/read.json`, JSON.stringify({ ...read, show: "x".repeat(16777217) }));
        const done = await behavior.methods.result.execute({ folder }, context);
        assertEquals([done.successful, done.turn.state, done.turn.ended, done.turn.transcript.length, written.length], [true, "done", true, 0, 3]);
        // A reviewer's turn is done only with its restore evidence once its checkout was detached.
        Deno.writeTextFileSync(`${folder}/review.json`, JSON.stringify({ ready: true, error_code: null }));
        Deno.writeTextFileSync(`${folder}/detach.json`, "{}");
        const unrestored = await behavior.methods.result.execute({ folder }, context);
        assertEquals([unrestored.successful, unrestored.turn.error_code, unrestored.turn.ended], [false, "tasks.copy_review_unrestored", false]);
        Deno.writeTextFileSync(`${folder}/restore.json`, JSON.stringify({ restored: true }));
        assertEquals((await behavior.methods.result.execute({ folder }, context)).successful, true);
        // A run that asked: the Operation succeeds once its question is stored and its run stopped, and fails while it is not.
        Deno.writeTextFileSync(`${folder}/start.json`, JSON.stringify({ start_exit: 2, start: started("waiting") }));
        Deno.writeTextFileSync(`${folder}/read.json`, JSON.stringify({ ...read, status: "running" }));
        Deno.writeTextFileSync(`${folder}/ask.json`, JSON.stringify(ask));
        const unstopped = await behavior.methods.result.execute({ folder }, context);
        assertEquals([unstopped.successful, unstopped.turn.error_code, unstopped.turn.ended], [false, "tasks.copy_question_unstopped", false]);
        Deno.writeTextFileSync(`${folder}/interrupt.json`, JSON.stringify(stopped));
        Deno.writeTextFileSync(`${folder}/read.json`, JSON.stringify({ ...read, status: "interrupted" }));
        const asking = await behavior.methods.result.execute({ folder }, context);
        assertEquals([asking.successful, asking.turn.state, asking.turn.ended, asking.turn.questions], [true, "asking_for_input", true, questions]);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
