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
const read = { list_exit: 0, found: 1, status: "completed", show_exit: 0, show };
const none = { resumed: null, review: null, model: null, detached: false, restore: null };
const turn = (start_exit: number, start: string, change: Partial<Evidence> = {}) => shapeTurn({ ...none, launched: true, start: { start_exit, start }, read, ...change });

Deno.test("a completed run is done and over, and records the message transcript and the T3 thread", () => {
    assertEquals(turn(0, started("completed")), { state: "done", error: null, ended: true, t3_thread_id: thread, run_id: run, transcript });
});

Deno.test("a run that waits for an answer asks for input and is not over; any other end fails the turn with T3's status", () => {
    const waiting = turn(2, started("waiting"), { read: { ...read, status: "waiting" } });
    assertEquals([waiting.state, waiting.ended], ["asking_for_input", false]);
    assertEquals(turn(1, started("failed", "api_error_529")), {
        state: "failed", error: "T3 run failed: api_error_529", error_code: "tasks.turn_failed", ended: true, t3_thread_id: thread, run_id: run, transcript,
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
        state: "failed", error: "T3 did not report the turn's end (exit 1).", error_code: "tasks.copy_turn_failed", ended: true, t3_thread_id: null, run_id: null, transcript: [],
    });
    assertEquals(shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "" }, read: { list_exit: null, found: 0, status: null, show_exit: null, show: "" } }).ended, false);
    // T3 made the thread but refused its run: the listed thread is idle.
    assertEquals(shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "" }, read: { ...read, status: "idle" } }).ended, true);
});

Deno.test("a continued turn reads like a new one, and one whose thread T3 does not hold is tasks.copy_thread_missing with nothing launched", () => {
    assertEquals(turn(0, started("completed"), { resumed: { list_exit: 0, found: 1 } }), { state: "done", error: null, ended: true, t3_thread_id: thread, run_id: run, transcript });
    for (const found of [0, 2]) {
        assertEquals(shapeTurn({ ...none, resumed: { list_exit: 0, found }, launched: false, start: null, read: null }), {
            state: "failed", error: `T3 holds ${found} threads of this title to continue, not one.`, error_code: "tasks.copy_thread_missing", ended: true,
            t3_thread_id: null, run_id: null, transcript: [],
        });
    }
});

Deno.test("a new thread whose model T3's catalog does not offer is tasks.copy_turn_model_unoffered with the offered slugs, and nothing launched", () => {
    const offered = ["claude-opus-5-5", "claude-sonnet-5-5"];
    assertEquals(shapeTurn({ ...none, model: { model: "claude-opus-9", offered, accepted: false }, launched: false, start: null, read: null }), {
        state: "failed", error: "The copy's T3 does not offer the model claude-opus-9; it offers: claude-opus-5-5, claude-sonnet-5-5.",
        error_code: "tasks.copy_turn_model_unoffered", ended: true, t3_thread_id: null, run_id: null, transcript: [],
    });
    // An offered model, or a catalog the phase could not read, runs the turn.
    assertEquals(turn(0, started("completed"), { model: { model: "claude-opus-5-5", offered, accepted: true } }).state, "done");
    assertEquals(turn(0, started("completed"), { model: { model: "claude-opus-9", offered: null, accepted: true } }).state, "done");
});

Deno.test("a long refusal keeps the end of T3's message, where T3 names the refusal", () => {
    const refused = shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "", error: "x".repeat(4000) + "Provider instance is disabled." },
        read: { list_exit: 0, found: 0, status: null, show_exit: null, show: "" } });
    assertEquals([refused.error!.endsWith("Provider instance is disabled.."), refused.error!.length < 2100], [true, true]);
});

Deno.test("a start T3 refused on stderr names T3's own message", () => {
    const refused = shapeTurn({ ...none, launched: true, start: { start_exit: 1, start: "", error: 'Provider instance "claudeMinimal" is disabled for project p.\n' },
        read: { list_exit: 0, found: 0, status: null, show_exit: null, show: "" } });
    assertEquals([refused.error, refused.error_code], ['T3 did not report the turn\'s end (exit 1): Provider instance "claudeMinimal" is disabled for project p..', "tasks.copy_turn_failed"]);
});

Deno.test("a continued turn whose thread list failed or printed no JSON is tasks.copy_turn_failed, not a missing thread", () => {
    for (const [list_exit, detail] of [[1, "exit 1"], [null, "no JSON"]] as const) {
        assertEquals(shapeTurn({ ...none, resumed: { list_exit, found: 0 }, launched: false, start: null, read: null }), {
            state: "failed", error: `T3 could not list its threads to find the one to continue (${detail}).`, error_code: "tasks.copy_turn_failed", ended: true,
            t3_thread_id: null, run_id: null, transcript: [],
        });
    }
});

Deno.test("a reviewer turn fails when its checkout could not be put on the candidate or was not restored after a run T3 ended", () => {
    const ready = { ready: true, error_code: null };
    assertEquals(turn(0, started("completed"), { review: ready, detached: true, restore: { restored: true } }).state, "done");
    assertEquals(turn(2, started("waiting"), { review: ready, detached: true, restore: { restored: true } }).state, "asking_for_input");
    for (const restore of [{ restored: false }, null]) {
        assertEquals(turn(0, started("completed"), { review: ready, detached: true, restore }), {
            state: "failed", error: "The checkout was not restored after the reviewer's turn.", error_code: "tasks.copy_review_unrestored", ended: false,
            t3_thread_id: thread, run_id: run, transcript,
        });
    }
    assertEquals(shapeTurn({ ...none, review: { ready: false, error_code: "tasks.copy_tree_dirty" }, launched: false, start: null, read: null }), {
        state: "failed", error: "The checkout had changes or no branch, so the reviewer could not be put on the candidate.", error_code: "tasks.copy_tree_dirty",
        ended: true, t3_thread_id: null, run_id: null, transcript: [],
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
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});

Deno.test("the method reads the model evidence: a model the catalog does not offer fails with what it offers", async () => {
    const folder = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    Deno.mkdirSync(folder);
    try {
        const context = { writeResource: () => Promise.resolve() };
        Deno.writeTextFileSync(`${folder}/model.json`, JSON.stringify({ model: "claude-opus-9", offered: ["claude-opus-5-5"], accepted: false }));
        const refused = await behavior.methods.result.execute({ folder }, context);
        assertEquals([refused.successful, refused.turn.error_code, refused.turn.ended], [false, "tasks.copy_turn_model_unoffered", true]);
        assertEquals(refused.turn.error, "The copy's T3 does not offer the model claude-opus-9; it offers: claude-opus-5-5.");
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
