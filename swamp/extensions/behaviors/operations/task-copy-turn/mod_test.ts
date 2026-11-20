import { assert, assertEquals } from "jsr:@std/assert@1";
import { behavior, type Evidence, shapeTurn, ThreadOver, usage } from "./mod.ts";

const thread = "mcp:66105c2c-4331-449b-90ef-e108d0a5db3d";
const run = "run:thread:mcp%3A66105c2c:ordinal:1";
// As `t3 thread show --json` answered in the hand proof on copy 2685, 2026-11-13.
const show = JSON.stringify({
    thread: { threadId: thread, status: "completed", latestRunId: run, providerInstanceId: "claudeMedium", model: "claude-opus-5-5" },
    messages: [{ role: "user", runId: run, text: "Subtask: Doctor note" }, { role: "assistant", runId: run, text: "Committed d867aba2." }],
});
const transcript: { id: string; kind: "message"; label: string; text: string; at: string }[] = [
    // A user message keeps only its ID, author and time: the prompt stays out of the archive.
    { id: `${run}:0`, kind: "message", label: "user", text: "", at: "" },
    { id: `${run}:1`, kind: "message", label: "assistant", text: "Committed d867aba2.", at: "" },
];
const started = (status: string, error: string | null = null) =>
    JSON.stringify({ threadId: thread, runId: run, status, reply: status === "completed" ? "Committed d867aba2." : null, error });
const read = { list_exit: 0, found: 1, status: "completed", show_exit: 0, show };
const none = { resumed: null, review: null, model: null, detached: false, ask: null, interrupt: null, stop: null, restore: null };
// The provider instance and model as T3 showed them, and no question.
const named = { sent: true, launched: true, spent: [], usage: null, provider_instance: "claudeMedium", model: "claude-opus-5-5", questions: [], settled: [] };
const unlaunched = { sent: false, launched: false, spent: [], usage: null, provider_instance: null, model: null, questions: [], settled: [] };
const unnamed = { sent: true, launched: true, spent: [], usage: null, provider_instance: null, model: null, questions: [], settled: [] };
// As `t3_copy_rpc.mjs read` printed it on a stock T3 0.1.7 server, 2026-11-14: one request with two questions.
const request = "runtime-request:provider:claudeAgent:native-request:toolu_01TbF9dLFChexV5BVoUsSdii";
const asked = {
    runs: [{ id: run, status: "running" }],
    requests: [{ request_id: request, kind: "user_input", run_id: run, questions: [
        { id: "Which color?", header: "Color", question: "Which color?", options: [{ label: "Red", description: "Choose red" }, { label: "Blue", description: "Choose blue" }], multiSelect: false },
        { id: "Which shape?", header: "Shape", question: "Which shape?", options: [{ label: "Circle", description: "Choose circle", value: "circle" }], allowCustomAnswer: true, required: true },
    ] }],
    stoppable: true,
};
const questions = [
    { request_id: request, run_id: run, id: "Which color?", header: "Color", question: "Which color?", options: [{ label: "Red", description: "Choose red", value: null }, { label: "Blue", description: "Choose blue", value: null }],
        multi_select: false, allow_custom_answer: null, initial_answer: null, required: null },
    { request_id: request, run_id: run, id: "Which shape?", header: "Shape", question: "Which shape?", options: [{ label: "Circle", description: "Choose circle", value: "circle" }],
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
    const held = shapeTurn({ ...none, settle: { list_exit: 0, settled: [11], open: [12], stopped: [] }, launched: false, start: null, read: null });
    assertEquals(held, { state: "failed", error: "An earlier turn may still run in the copy (Operation 12); nothing was launched.", error_code: "tasks.copy_turn_unended",
        ended: true, t3_thread_id: null, run_id: null, ...unlaunched, settled: [11], transcript: [] });
    assertEquals(turn(0, started("completed"), { settle: { list_exit: 0, settled: [11, 12], open: [], stopped: [{ operation_id: 12, run }] } }),
        { state: "done", error: null, ended: true, t3_thread_id: thread, run_id: run, ...named, settled: [11, 12], transcript });
});

Deno.test("a free-text question with no options is stored whole, so the run it stopped asks for input and is over", () => {
    const free = { ...asked, requests: [{ request_id: request, kind: "user_input", run_id: run, questions: [{ id: "Why?", header: "", question: "Why does it fail?", options: [] }] }] };
    const stored = turn(2, started("waiting"), { ask: { ask_exit: 0, ask: JSON.stringify(free) }, interrupt: stopped, read: { ...read, status: "interrupted" } });
    assertEquals([stored.state, stored.error, stored.ended, stored.questions], ["asking_for_input", null, true, [{ request_id: request, run_id: run, id: "Why?", header: "",
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
        // The helper's own words stay in the run folder.
        "Orbit could not stop the run that asks: T3 refused or did not end the stop", questions]);
    const cut = turn(2, started("waiting"), { ask, read: running, interrupt: { interrupt_exit: 124, interrupt: "" } });
    assertEquals([cut.error_code, cut.error, cut.ended], ["tasks.copy_question_unstopped", "Orbit could not stop the run that asks: exit 124", false]);
    assertEquals(turn(2, started("waiting"), { ask, read: running }).error, "Orbit could not stop the run that asks: no stop ran");
});

Deno.test("a run that ends other than done or asking fails the turn with T3's status", () => {
    assertEquals(turn(1, started("failed", "api_error_529")), {
        state: "failed", error: "T3 run failed", error_code: "tasks.turn_failed", ended: true, t3_thread_id: thread, run_id: run, ...named, transcript,
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
    assertEquals([turn(124, "").ended, turn(124, "").error], [true, "The turn ran longer than its bound. Its run has ended."]);
    assertEquals(turn(124, "", { read: { ...read, status: null } }).ended, false);
    // The read looked for the thread the turn sent to by its ID: a thread T3 does not list proves nothing, so the turn holds. Only a
    // turn that made no thread, read by its title, is over when T3 holds none.
    assertEquals(turn(124, "", { read: { ...read, thread_id: thread, found: 0, status: null, show_exit: null, show: "" } }).ended, false);
    assertEquals(turn(124, "", { read: { ...read, thread_id: null, found: 0, status: null, show_exit: null, show: "" } }).ended, true);
});

Deno.test("a cut turn whose last thread list failed keeps the thread it targeted, so the next turn settles that thread by its ID", () => {
    const unread = turn(124, "", { read: { list_exit: 1, thread_id: thread, found: 0, status: null, show_exit: null, show: "" } });
    assertEquals([unread.state, unread.error_code, unread.ended, unread.t3_thread_id], ["failed", "tasks.turn_timeout", false, thread]);
    assertEquals(turn(124, "", { read: { list_exit: null, thread_id: null, found: 0, status: null, show_exit: null, show: "" } }).t3_thread_id, null);
});

Deno.test("a wait that the bound killed and whose run the phase stopped is over and holds nothing, still failing tasks.turn_timeout", () => {
    const halted = (answer: unknown, stop_exit = 0) => ({ stop_exit, stop: JSON.stringify(answer) });
    for (const exit of [124, 137]) {
        // The read back follows the stop: T3 lists the stopped thread interrupted.
        const cut = turn(exit, "", { read: { ...read, status: "interrupted" }, stop: halted({ stopped: true, over: true, run, state: "interrupted" }) });
        assertEquals([cut.state, cut.error_code, cut.ended, cut.error], ["failed", "tasks.turn_timeout", true, "The turn ran longer than its bound. Orbit stopped its run."]);
    }
    // Another run that T3 lists active on the thread after the stop neither holds nor ends this turn: the stop proved this run's end.
    assertEquals(turn(124, "", { read: { ...read, status: "running" }, stop: halted({ stopped: true, over: true, run, state: "interrupted" }) }).ended, true);
    // The stop read the run already over: it ended on its own.
    const idle = turn(124, "", { read: { ...read, status: "idle" }, stop: halted({ stopped: false, over: true, run, state: "completed" }) });
    assertEquals([idle.ended, idle.error], [true, "The turn ran longer than its bound. Its run has ended."]);
    // A stop that T3 refused, one cut by its own bound, an accepted interrupt whose readback omits the run, or an answer that is no
    // stop's, leaves the run going: the next turn stops it.
    for (const stop of [halted({ stopped: false, over: false, run, state: "running" }), halted({}, 124), { stop_exit: 0, stop: "not json" }, halted({ interrupted: true }),
        halted({ stopped: false, over: false, run, state: null }), halted({ stopped: false, run: null, state: null })]) {
        const held = turn(124, "", { read: { ...read, status: "running" }, stop });
        assertEquals([held.error_code, held.ended, held.error], ["tasks.turn_timeout", false,
            "The turn ran longer than its bound. Orbit did not stop its run; the turn holds until its run is over."], JSON.stringify(stop));
    }
    // A cut reviewer whose run stopped and whose checkout was restored is free.
    const reviewer = turn(124, "", { review: { ready: true, error_code: null }, detached: true, restore: { restored: true }, read: { ...read, status: "interrupted" },
        stop: halted({ stopped: true, over: true, run, state: "interrupted" }) });
    assertEquals([reviewer.error_code, reviewer.ended], ["tasks.turn_timeout", true]);
});

Deno.test("a phase cut before the start's end is over only when nothing was launched or T3 holds no such thread", () => {
    const cut = shapeTurn({ ...none, launched: true, start: null, read: null });
    assertEquals([cut.state, cut.error_code, cut.ended], ["failed", "tasks.copy_turn_failed", false]);
    assertEquals(shapeTurn({ ...none, launched: false, start: null, read: null }).ended, true);
    assertEquals(shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "" }, read: { list_exit: 0, found: 0, status: null, show_exit: null, show: "" } }), {
        state: "failed", error: "T3 did not report the turn's end (exit 1).", error_code: "tasks.copy_turn_failed", ended: true, t3_thread_id: null, run_id: null, ...unnamed,
        // The launch mark says only that the send may have reached T3; with no run reported, the turn is possibly sent, not launched.
        launched: false, transcript: [],
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

Deno.test("a new thread whose model T3's catalog does not offer is tasks.copy_turn_model_unoffered, naming no catalog entry, and nothing launched", () => {
    const offered = ["claude-opus-5-5", "claude-sonnet-5-5"];
    assertEquals(shapeTurn({ ...none, model: { model: "claude-opus-9", offered, accepted: false }, launched: false, start: null, read: null }), {
        // The catalog's entries stay in the run folder: the error names none of them.
        state: "failed", error: "The copy's T3 does not offer the thread's model.",
        error_code: "tasks.copy_turn_model_unoffered", ended: true, t3_thread_id: null, run_id: null, ...unlaunched, transcript: [],
    });
    // An offered model, or a catalog the phase could not read, runs the turn.
    assertEquals(turn(0, started("completed"), { model: { model: "claude-opus-5-5", offered, accepted: true } }).state, "done");
    assertEquals(turn(0, started("completed"), { model: { model: "claude-opus-9", offered: null, accepted: true } }).state, "done");
});

Deno.test("a start T3 refused on stderr keeps none of T3's message in the result", () => {
    const refused = shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "", error: 'Provider instance "claudeMinimal" is disabled for project p.\n' },
        read: { list_exit: 0, found: 0, status: null, show_exit: null, show: "" } });
    assertEquals([refused.error, refused.error_code, "diagnostic" in refused], ["T3 did not report the turn's end (exit 1).", "tasks.copy_turn_failed", false]);
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
        ["failed", "tasks.copy_review_unrestored", false, "The checkout was not restored after the reviewer's turn. The run: T3 run failed"]);
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

Deno.test("a message without a run id keeps the transcript; a user message keeps only its ID, author and time", () => {
    const shown = JSON.stringify({ thread: { threadId: thread, status: "completed" }, messages: [{ role: "user", text: "Hello" }] });
    assertEquals(turn(0, started("completed"), { read: { ...read, show: shown } }).transcript, [{ id: "run:0", kind: "message", label: "user", text: "", at: "" }]);
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

Deno.test("the turn returns the handoff receipts that its phase read and removed, and null for one it did not leave", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    Deno.mkdirSync(folder);
    try {
        const context = { writeResource: () => Promise.resolve(undefined) };
        const none = await behavior.methods.result.execute({ folder }, context);
        assertEquals(none.turn.receipts, { delivery: null, review: null });
        // A SHA-256 object ID stays whole, though a credential pattern would match it; only the receipt's own fields are kept.
        const receipt = { commit: "a".repeat(64), tree: "b".repeat(64), parts: [{ name: "pest", result: "passed" as const, seconds: 1.5 }] };
        Deno.writeTextFileSync(`${folder}/receipts.json`, JSON.stringify({ delivery: JSON.stringify({ ...receipt, marker: "unrelated" }) }));
        const left = await behavior.methods.result.execute({ folder }, context);
        assertEquals(left.turn.receipts, { delivery: receipt, review: null });
        // A receipt is agent text: a part name and a review receipt are redacted.
        const secret = "ghp_" + "a1B2".repeat(9);
        Deno.writeTextFileSync(`${folder}/receipts.json`, JSON.stringify({
            delivery: JSON.stringify({ ...receipt, parts: [{ name: secret, result: "passed", seconds: 1 }] }),
            review: `{"verdict": "${secret}"}`,
        }));
        const redacted = await behavior.methods.result.execute({ folder }, context);
        assertEquals(JSON.stringify(redacted.turn.receipts).includes(secret), false);
        // A review receipt stays JSON when a finding quotes a header that redaction cuts to the end of its line.
        const review = { verdict: "changes", findings: [{ summary: `The proxy drops the Authorization: Bearer ${secret} header.` }] };
        Deno.writeTextFileSync(`${folder}/receipts.json`, JSON.stringify({ review: JSON.stringify(review) }));
        const kept = JSON.parse((await behavior.methods.result.execute({ folder }, context)).turn.receipts?.review ?? "");
        assertEquals(Object.keys(kept), ["verdict", "findings"]);
        assertEquals(kept.findings.length, 1);
        assertEquals(JSON.stringify(kept).includes(secret), false);
        // Any other receipt is invalid before any redaction and passes on no content: a secret under any field name, a field that a
        // line break parts from its value, a verdict that only redaction would make valid, terminal formatting around JSON, deep nesting.
        const plain = "hunter2-plain-value";
        const esc = "\u001b[31m";
        for (const other of [`VERDICT: APPROVE ${secret}`, JSON.stringify({ verdict: "approve", findings: [], key: plain }),
            JSON.stringify({ verdict: "approve", findings: [{ summary: "x", keys: [plain] }] }), `{"verdict":"approve","findings":[],"password":\n"${plain}"}`,
            JSON.stringify({ verdict: `${esc}approve\u001b[0m`, findings: [] }), `${esc}{"verdict":"approve","findings":[]}`,
            '{"verdict":"approve","findings":[],"x":' + "[".repeat(30000) + "]".repeat(30000) + "}"]) {
            Deno.writeTextFileSync(`${folder}/receipts.json`, JSON.stringify({ review: other }));
            assertEquals((await behavior.methods.result.execute({ folder }, context)).turn.receipts?.review, "invalid");
        }
        // Anything else is invalid, never passed on.
        for (const invalid of ["not json", JSON.stringify({ ...receipt, parts: {} }), JSON.stringify({ ...receipt, parts: [] }),
            JSON.stringify({ ...receipt, commit: "HEAD" }), JSON.stringify({ ...receipt, parts: [{ name: "pest", result: "failed", seconds: 1 }] }),
            JSON.stringify(receipt).replace('"seconds":1.5', '"seconds":1e400'),
            // A name that is only terminal formatting is empty once redacted.
            JSON.stringify({ ...receipt, parts: [{ name: "\u001b[31m\u001b[0m", result: "passed", seconds: 1 }] })]) {
            Deno.writeTextFileSync(`${folder}/receipts.json`, JSON.stringify({ delivery: invalid }));
            assertEquals((await behavior.methods.result.execute({ folder }, context)).turn.receipts?.delivery, "invalid");
        }
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
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

Deno.test("the method reads the stop of a cut run and the runs the settle stopped", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    Deno.mkdirSync(folder);
    try {
        const context = { writeResource: () => Promise.resolve() };
        Deno.writeTextFileSync(`${folder}/settle.json`, JSON.stringify({ list_exit: 0, settled: [11], open: [], stopped: [{ operation_id: 11, run: "run:earlier" }] }));
        Deno.writeTextFileSync(`${folder}/launch.json`, "{}");
        Deno.writeTextFileSync(`${folder}/start.json`, JSON.stringify({ start_exit: 124, start: "" }));
        Deno.writeTextFileSync(`${folder}/read.json`, JSON.stringify({ ...read, status: "running" }));
        const unstopped = await behavior.methods.result.execute({ folder }, context);
        assertEquals([unstopped.successful, unstopped.turn.error_code, unstopped.turn.ended, unstopped.turn.settled], [false, "tasks.turn_timeout", false, [11]]);
        Deno.writeTextFileSync(`${folder}/stop.json`, JSON.stringify({ stop_exit: 0, stop: JSON.stringify({ stopped: true, run, state: "interrupted" }) }));
        Deno.writeTextFileSync(`${folder}/read.json`, JSON.stringify({ ...read, status: "interrupted" }));
        const stopped = await behavior.methods.result.execute({ folder }, context);
        assertEquals([stopped.successful, stopped.turn.error_code, stopped.turn.ended, stopped.turn.settled], [false, "tasks.turn_timeout", true, [11]]);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});

Deno.test("the method reads the model evidence: a model the catalog does not offer fails without naming the catalog", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    Deno.mkdirSync(folder);
    try {
        const context = { writeResource: () => Promise.resolve() };
        Deno.writeTextFileSync(`${folder}/model.json`, JSON.stringify({ model: "claude-opus-9", offered: ["claude-opus-5-5"], accepted: false }));
        const refused = await behavior.methods.result.execute({ folder }, context);
        assertEquals([refused.successful, refused.turn.error_code, refused.turn.ended], [false, "tasks.copy_turn_model_unoffered", true]);
        assertEquals(refused.turn.error, "The copy's T3 does not offer the thread's model.");
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});

Deno.test("an answered question whose resolution cannot be confirmed fails before launch and holds no run", () => {
    for (const evidence of [
        { answered_exit: 0, answered: JSON.stringify({ resolved: false, pending: [request], error: "Still pending." }) },
        { answered_exit: 1, answered: "" },
        { answered_exit: 124, answered: "" },
        { answered_exit: 0, answered: "not json" },
        { answered_exit: 0, answered: JSON.stringify({ resolved: true, pending: [request], error: null }) },
    ]) {
        const refused = shapeTurn({ ...none, answered: evidence, resumed: { list_exit: 0, found: 1 }, launched: false, start: null, read: null });
        assertEquals([refused.state, refused.error_code, refused.ended, refused.launched, refused.run_id, refused.questions],
            ["failed", "tasks.copy_question_unresolved", true, false, null, []]);
    }
    assertEquals(turn(0, started("completed"), { answered: { answered_exit: 0, answered: JSON.stringify({ resolved: true, pending: [], error: null }) } }).state, "done");
});

Deno.test("the method reads the answered evidence and refuses an unconfirmed resolution before launch", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    try {
        await Deno.mkdir(folder);
        await Deno.writeTextFile(`${folder}/answered.json`, JSON.stringify({ answered_exit: 0, answered: JSON.stringify({ resolved: false, pending: [request], error: "Still pending." }) }));
        const result = await behavior.methods.result.execute({ folder }, { writeResource: () => Promise.resolve() });
        assertEquals([result.successful, result.turn.error_code, result.turn.ended, result.turn.launched], [false, "tasks.copy_question_unresolved", true, false]);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("a question whose owner run Orbit could not read is not stored and its run is not stopped or over", () => {
    const ownerless = { ...asked, requests: asked.requests.map(({ run_id: _, ...rest }) => rest), stoppable: false };
    const unread = turn(2, started("waiting"), { ask: { ask_exit: 0, ask: JSON.stringify(ownerless) }, read: { ...read, status: "waiting" } });
    assertEquals([unread.state, unread.error_code, unread.ended, unread.questions], ["asking_for_input", "tasks.copy_question_unread", false, []]);
    // Also when an older read still called the run stoppable.
    const older = turn(2, started("waiting"), { ask: { ask_exit: 0, ask: JSON.stringify({ ...ownerless, stoppable: true }) }, interrupt: stopped, read: { ...read, status: "interrupted" } });
    assertEquals([older.error_code, older.questions], ["tasks.copy_question_unread", []]);
});

Deno.test("a start that printed no status names no end: with no run and a queued readback the turn is possibly sent and holds", () => {
    const queued = turn(0, JSON.stringify({ threadId: thread }), { read: { ...read, status: "queued" } });
    assertEquals([queued.sent, queued.launched, queued.ended, queued.error_code], [true, false, false, "tasks.turn_failed"]);
    // T3 lists the thread idle: nothing of the turn runs.
    assertEquals(turn(0, JSON.stringify({ threadId: thread }), { read: { ...read, status: "idle" } }).ended, true);
});


Deno.test("a failure keeps no text of T3 or the provider and parses nothing from it", () => {
    for (const text of ["Invalid input: Acquire Juniper", 'Invalid input: {"type":"billing_error"}',
        'API Error: 429 {"type":"error","error":{"type":"rate_limit_error","message":"HTTP 400; retry after 2 minutes"}}']) {
        const failed = turn(1, started("failed", text));
        assertEquals([failed.error, failed.error_code, "diagnostic" in failed, JSON.stringify(failed).includes("Juniper")], ["T3 run failed", "tasks.turn_failed", false, false], text);
    }
});

Deno.test("a question without its owner run keeps the turn unended, whatever the list shows", () => {
    const ownerless = { ...asked, requests: asked.requests.map(({ run_id: _, ...rest }) => rest) };
    for (const status of ["completed", "interrupted"]) {
        const unread = turn(2, started("waiting"), { ask: { ask_exit: 0, ask: JSON.stringify(ownerless) }, interrupt: stopped, read: { ...read, status } });
        assertEquals([unread.error_code, unread.questions, unread.ended], ["tasks.copy_question_unread", [], false], status);
    }
});

Deno.test("a run status outside T3's known statuses is not named and names no end", () => {
    for (const status of ["acquire_juniper", "Acquire Juniper"]) {
        const unknown = turn(1, JSON.stringify({ threadId: thread, runId: run, status, error: null }), { read: { ...read, status: "running" } });
        assertEquals([unknown.error, unknown.error_code, unknown.ended], ["T3 reported a run status Orbit does not know", "tasks.turn_failed", false]);
    }
    // An unknown status in both the start and the list names no end either.
    const paused = turn(1, JSON.stringify({ threadId: thread, runId: run, status: "paused", error: null }), { read: { ...read, status: "paused" } });
    assertEquals([paused.error, paused.ended], ["T3 reported a run status Orbit does not know", false]);
    // A known status that ends this turn's run ends the turn, whatever another run of the thread does.
    assertEquals([turn(1, started("cancelled"), { read: { ...read, status: "completed" } }).ended, turn(1, started("cancelled"), { read: { ...read, status: "running" } }).ended],
        [true, true]);
});

Deno.test("ended follows the rows of over(), judged on this turn's run", () => {
    const ownerless = { ...asked, requests: asked.requests.map(({ run_id: _, ...rest }) => rest) };
    const halted = { stop_exit: 0, stop: JSON.stringify({ stopped: true, over: true, run, state: "interrupted" }) };
    const failedList = { list_exit: 1, found: 0, status: null, show_exit: null, show: "" };
    const rows: [string, boolean, Evidence][] = [
        ["1 unrestored reviewer checkout, run completed", false, { ...none, launched: true, detached: true, restore: { restored: false }, start: { start_exit: 0, start: started("completed") }, read }],
        ["2 nothing launched", true, { ...none, launched: false, start: null, read: null }],
        ["3 question stored and its run's interrupt confirmed, list failed", true, { ...none, launched: true, start: { start_exit: 2, start: started("waiting") }, ask, interrupt: stopped, read: failedList }],
        ["4 question unread, list completed", false, { ...none, launched: true, start: { start_exit: 2, start: started("waiting") }, ask: { ask_exit: 1, ask: "" }, read }],
        ["4 question without owner, list completed", false, { ...none, launched: true, start: { start_exit: 2, start: started("waiting") }, ask: { ask_exit: 0, ask: JSON.stringify(ownerless) }, interrupt: stopped, read }],
        ["4 interrupt unconfirmed, list completed", false, { ...none, launched: true, start: { start_exit: 2, start: started("waiting") }, ask, interrupt: { interrupt_exit: 124, interrupt: "" }, read }],
        ["5 this run completed, another run active", true, { ...none, launched: true, start: { start_exit: 0, start: started("completed") }, read: { ...read, status: "running" } }],
        ["4 a question another run owns, its interrupt refused, list completed", false, { ...none, launched: true, start: { start_exit: 2, start: started("waiting") },
            ask: { ask_exit: 0, ask: JSON.stringify({ ...asked, requests: asked.requests.map((request) => ({ ...request, run_id: "run:B" })) }) },
            interrupt: { interrupt_exit: 0, interrupt: JSON.stringify({ interrupted: false, error: "not this run's", runs: [], pending: 1 }) }, read }],
        ["6 run running, thread idle", false, { ...none, launched: true, start: { start_exit: 1, start: started("running") }, read: { ...read, status: "idle" } }],
        ["6 run queued, thread idle", false, { ...none, launched: true, start: { start_exit: 1, start: started("queued") }, read: { ...read, status: "idle" } }],
        ["6 unknown run status, list completed", false, { ...none, launched: true, start: { start_exit: 1, start: started("paused") }, read }],
        ["7 cut run's stop proved, list failed", true, { ...none, launched: true, start: { start_exit: 124, start: "" }, stop: halted, read: failedList }],
        ["8 no status, thread idle", true, { ...none, launched: true, start: { start_exit: 0, start: JSON.stringify({ threadId: thread }) }, read: { ...read, status: "idle" } }],
        ["8 no status, no thread of the title", true, { ...none, launched: true, start: { start_exit: 1, start: "" }, read: { ...read, found: 0, status: null } }],
        ["9 cut run's interrupt accepted, readback omits it, thread running", false, { ...none, launched: true, start: { start_exit: 124, start: "" },
            stop: { stop_exit: 0, stop: JSON.stringify({ stopped: false, over: false, run, state: null }) }, read: { ...read, status: "running" } }],
        ["9 no status, T3 lists no thread of the turn's ID", false, { ...none, launched: true, start: { start_exit: 124, start: "" },
            read: { ...read, thread_id: thread, found: 0, status: null, show_exit: null, show: "" } }],
        ["9 no status, thread queued", false, { ...none, launched: true, start: { start_exit: 0, start: JSON.stringify({ threadId: thread }) }, read: { ...read, status: "queued" } }],
        ["9 no status, thread paused", false, { ...none, launched: true, start: { start_exit: 1, start: "" }, read: { ...read, status: "paused" } }],
        ["9 no status, no list", false, { ...none, launched: true, start: { start_exit: 1, start: "" }, read: failedList }],
    ];
    for (const [row, ended, evidence] of rows) assertEquals(shapeTurn(evidence).ended, ended, row);
});

Deno.test("the phase knows the same run and thread states that run nothing as the result step", () => {
    const phase = Deno.readTextFileSync(new URL("../../../../ansible/task-copy-turn.yml", import.meta.url));
    const list = (name: string) => phase.match(new RegExp(`${name}: \\[([^\\]]*)\\]`))![1].split(",").map((state) => state.trim().replace(/'/g, "")).sort();
    assertEquals(list("thread_over"), [...ThreadOver].sort());
    assertEquals(list("run_over"), [...ThreadOver].filter((state) => state !== "idle").sort());
});

Deno.test("a question that another run owns is not stored, and the turn holds", () => {
    const foreign = { ...asked, requests: asked.requests.map((request) => ({ ...request, run_id: "run:B" })) };
    const held = turn(2, started("waiting"), { ask: { ask_exit: 0, ask: JSON.stringify(foreign) }, interrupt: stopped, read: { ...read, status: "interrupted" } });
    assertEquals([held.state, held.error_code, held.questions, held.ended], ["asking_for_input", "tasks.copy_question_unread", [], false]);
});

Deno.test("a thread's token use is the sum of its provider turns in Orbit's columns; unknown use is null, never zeros", () => {
    const read = (turns: unknown[], exit = 0) => ({ usage_exit: exit, usage: JSON.stringify({ turns }) });
    // As conn-server 0.2.0 keeps it: inputTokens holds the cache reads, which Orbit's input excludes.
    const first = { usageStatus: "complete", inputTokens: 1000, cachedInputTokens: 800, outputTokens: 50 };
    const second = { usageStatus: "complete", inputTokens: 300, outputTokens: 20 };
    assertEquals(usage(read([first, second])), { tokens: 1370, input_tokens: 500, cached_input_tokens: 800, output_tokens: 70, model_calls: 2 });
    // The turn carries it, so a continued thread's total replaces the stored one.
    assertEquals(turn(0, started("completed"), { usage: read([first]) }).usage, { tokens: 1050, input_tokens: 200, cached_input_tokens: 800, output_tokens: 50, model_calls: 1 });
    for (const unknown of [
        read([]), read([first, null]), read([first, { ...second, usageStatus: "partial" }]), read([{ ...second, usageStatus: "unavailable" }]),
        read([{ usageStatus: "complete", outputTokens: 1 }]), read([{ ...first, cachedInputTokens: 2000 }]), read([first], 1),
        { usage_exit: 0, usage: "not json" }, null, undefined,
    ]) {
        assertEquals(usage(unknown), null, JSON.stringify(unknown));
    }
    assertEquals(turn(0, started("completed")).usage, null);
});

Deno.test("the method reads the usage evidence", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    Deno.mkdirSync(folder);
    try {
        Deno.writeTextFileSync(`${folder}/launch.json`, "{}");
        Deno.writeTextFileSync(`${folder}/start.json`, JSON.stringify({ start_exit: 0, start: started("completed") }));
        Deno.writeTextFileSync(`${folder}/read.json`, JSON.stringify(read));
        Deno.writeTextFileSync(`${folder}/usage.json`, JSON.stringify({ usage_exit: 0,
            usage: JSON.stringify({ turns: [{ usageStatus: "complete", inputTokens: 10, cachedInputTokens: 4, outputTokens: 3 }] }) }));
        const result = await behavior.methods.result.execute({ folder }, { writeResource: () => Promise.resolve() });
        assertEquals(result.turn.usage, { tokens: 13, input_tokens: 6, cached_input_tokens: 4, output_tokens: 3, model_calls: 1 });
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
