import { fauxAssistantMessage, fauxToolCall } from "@earendil-works/pi-ai";
import { afterEach, describe, expect, it } from "vite-plus/test";
import { appendSessionEntries, type Harness, startHarness, waitFor } from "./support.ts";

const MODEL = "faux/model-a";
let harness: Harness | undefined;

afterEach(async () => {
    await harness?.close();
    harness = undefined;
});

async function created(): Promise<Harness> {
    harness = await startHarness();
    const response = await harness.request("POST", "/sessions", {
        id: "thread-1",
        cwd: harness.workspace,
        model: MODEL,
        thinkingLevel: "low",
        appendSystemPrompt: "Role prompt marker.",
    });
    expect(response.status).toBe(201);

    return harness;
}

const settled = (h: Harness) =>
    waitFor(
        async () => (await h.request("GET", "/sessions/thread-1")).body,
        (snapshot) => snapshot.state !== "working",
    );

describe("authentication", () => {
    it("refuses requests without the bearer token", async () => {
        harness = await startHarness();
        const response = await harness.request("GET", "/capabilities", undefined, "wrong");

        expect(response.status).toBe(401);
        expect(response.body).toEqual({
            error: {
                code: "auth.unauthorized",
                message: "A valid bearer token is required.",
                details: {},
            },
        });
    });

    it("answers an unknown route with the error envelope", async () => {
        harness = await startHarness();
        const response = await harness.request("GET", "/unknown");

        expect(response.status).toBe(404);
        expect(response.body).toEqual({
            error: {
                code: "http.404",
                message: "No route matches this request.",
                details: {},
            },
        });
    });

    it("answers a body over 1 MiB with the error envelope", async () => {
        harness = await startHarness();
        const response = await harness.request("POST", "/sessions", {
            id: "thread-1",
            cwd: "x".repeat(1024 * 1024 + 1),
        });

        expect(response.status).toBe(413);
        expect(response.body).toEqual({
            error: {
                code: "http.413",
                message: "The request body is larger than 1 MiB.",
                details: {},
            },
        });
    });

    it("answers an unexpected failure with the error envelope", async () => {
        harness = await startHarness();
        harness.registry.availableModels = async () => {
            throw new Error("boom");
        };
        const response = await harness.request("GET", "/capabilities");

        expect(response.status).toBe(500);
        expect(response.body).toEqual({
            error: {
                code: "server.unhandled",
                message: "The Pi server failed to handle the request.",
                details: {},
            },
        });
    });

    it("lists the models this Node can run", async () => {
        harness = await startHarness();
        const response = await harness.request("GET", "/capabilities");

        expect(response.status).toBe(200);
        expect(response.body.models).toContain(MODEL);
    });
});

describe("create", () => {
    it("returns the existing session when the same create is repeated", async () => {
        const h = await created();
        const again = await h.request("POST", "/sessions", {
            id: "thread-1",
            cwd: h.workspace,
            model: MODEL,
            thinkingLevel: "low",
            appendSystemPrompt: "Role prompt marker.",
        });

        expect(again.status).toBe(200);
    });

    it("refuses a repeated ID with different settings", async () => {
        const h = await created();
        const conflict = await h.request("POST", "/sessions", {
            id: "thread-1",
            cwd: h.workspace,
            model: MODEL,
            thinkingLevel: "high",
        });

        expect(conflict.status).toBe(409);
        expect(conflict.body.error.code).toBe("session.exists");
    });

    it.each([
        [{ model: "faux/missing" }, "model.unavailable"],
        [{ model: "no-slash" }, "model.unavailable"],
        [{ id: "../escape" }, "validation.failed"],
        [{ thinkingLevel: "extreme" }, "validation.failed"],
        [{ cwd: "/" }, "validation.failed"],
    ])("refuses %j", async (override, code) => {
        harness = await startHarness();
        const response = await harness.request("POST", "/sessions", {
            id: "thread-2",
            cwd: harness.workspace,
            model: MODEL,
            thinkingLevel: "low",
            ...override,
        });

        expect(response.status).toBe(422);
        expect(response.body.error.code).toBe(code);
    });

    it("refuses a model signed in with an API key unless API keys are allowed", async () => {
        harness = await startHarness({ allowApiKeys: false });
        const response = await harness.request("POST", "/sessions", {
            id: "thread-2",
            cwd: harness.workspace,
            model: MODEL,
            thinkingLevel: "low",
        });

        expect(response.status).toBe(422);
        expect(response.body.error.message).toContain("subscription");
        expect((await harness.request("GET", "/capabilities")).body.models).toEqual([]);
    });

    it("allows a named API-key provider, such as a CLIProxyAPI endpoint", async () => {
        harness = await startHarness({ allowApiKeys: false, allowedProviders: ["faux"] });
        const response = await harness.request("POST", "/sessions", {
            id: "thread-2",
            cwd: harness.workspace,
            model: MODEL,
            thinkingLevel: "low",
        });

        expect(response.status).toBe(201);
        expect((await harness.request("GET", "/capabilities")).body.models).toEqual([MODEL]);
    });

    it("returns not found for an unknown session", async () => {
        harness = await startHarness();

        const response = await harness.request("GET", "/sessions/unknown");

        expect(response.status).toBe(404);
        expect(response.body.error).toEqual({
            code: "session.not_found",
            message: "Session unknown does not exist.",
            details: {},
        });
    });
});

describe("turns", () => {
    it("streams a snapshot, then entries and states through a completed turn", async () => {
        const h = await created();
        h.faux.setResponses([
            fauxAssistantMessage([fauxToolCall("bash", { command: "echo probe" })], {
                stopReason: "toolUse",
            }),
            fauxAssistantMessage("finished"),
        ]);
        const collecting = h.stream("thread-1", (events) =>
            events.some((e) => e.kind === "state" && e.state === "done"),
        );
        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(
            (await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" }))
                .status,
        ).toBe(202);
        const events = await collecting;

        expect(events[0]).toMatchObject({
            kind: "snapshot",
            run: expect.any(String),
            state: "idle",
            turnId: null,
            entries: [],
        });
        expect(events[1]).toMatchObject({ kind: "state", state: "working", turnId: "k1" });
        const roles = events.filter((e) => e.kind === "entry").map((e) => e.entry.message.role);
        expect(roles).toEqual(["user", "assistant", "toolResult", "assistant"]);
        const sequences = events.slice(1).map((e) => e.sequence);
        expect(sequences).toEqual([...sequences].sort((a, b) => a - b));
        expect(events.at(-1)).toMatchObject({ kind: "state", state: "done", error: null });
        expect(events.at(-1).usage.total).toBeGreaterThan(0);
    });

    it("does not start a second turn for a repeated key", async () => {
        const h = await created();
        h.faux.setResponses([fauxAssistantMessage("once")]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await settled(h);

        const repeat = await h.request("POST", "/sessions/thread-1/messages", {
            key: "k1",
            text: "go",
        });

        expect(repeat).toMatchObject({ status: 200, body: { duplicate: true } });
        expect(h.faux.state.callCount).toBe(1);
    });

    it("refuses a new key while a turn is active and reports an interrupt as failed", async () => {
        const h = await created();
        let onStart!: () => void;
        const started = new Promise<void>((resolve) => {
            onStart = resolve;
        });
        h.faux.setResponses([
            async (_context, options) => {
                // Keep the turn active until interrupt, without a token-rate timing window.
                await new Promise<void>((resolve) => {
                    options!.signal!.addEventListener("abort", () => resolve(), { once: true });
                    onStart();
                });
                return fauxAssistantMessage("interrupted answer");
            },
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await started;

        const busy = await h.request("POST", "/sessions/thread-1/messages", {
            key: "k2",
            text: "more",
        });
        expect(busy.status).toBe(409);
        expect(busy.body.error.code).toBe("session.turn_active");

        expect((await h.request("POST", "/sessions/thread-1/interrupt")).status).toBe(202);
        expect(await settled(h)).toMatchObject({
            state: "failed",
            error: "The turn was interrupted.",
        });
    });

    it("reports a provider error as failed with its message", async () => {
        const h = await created();
        h.faux.setResponses([
            fauxAssistantMessage("", { stopReason: "error", errorMessage: "quota exceeded" }),
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });

        expect(await settled(h)).toMatchObject({ state: "failed", error: "quota exceeded" });
    });

    it("sends project skills, AGENTS.md, and the role prompt to the model", async () => {
        const h = await created();
        let systemPrompt = "";
        h.faux.setResponses([
            (context) => {
                systemPrompt = JSON.stringify(context);
                return fauxAssistantMessage("ok");
            },
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await settled(h);

        expect(systemPrompt).toContain("orbit-probe");
        expect(systemPrompt).toContain("Workspace instruction marker.");
        expect(systemPrompt).toContain("Role prompt marker.");
    });

    it("gives the model search_docs, which explains when no Laravel app is found", async () => {
        const h = await created();
        let context = "";
        h.faux.setResponses([
            (request) => {
                context = JSON.stringify(request);
                return fauxAssistantMessage(
                    [fauxToolCall("search_docs", { queries: ["validation"] })],
                    { stopReason: "toolUse" },
                );
            },
            fauxAssistantMessage("finished"),
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const snapshot = await settled(h);

        for (const tool of ["read", "bash", "edit", "write", "search_docs"]) {
            expect(context).toContain(`"name":"${tool}"`);
        }
        const result = snapshot.entries.find((e: any) => e.message.role === "toolResult").message;
        expect(result.isError).toBe(true);
        expect(JSON.stringify(result.content)).toContain("No Laravel app with Laravel Boost found");
    });
});

describe("resume", () => {
    const done = (events: any[]) => events.some((e) => e.kind === "state" && e.state === "done");

    /** Runs one turn with a bash call and returns the events of a stream that watched it. */
    async function completedTurn(): Promise<{ h: Harness; events: any[] }> {
        const h = await created();
        h.faux.setResponses([
            fauxAssistantMessage([fauxToolCall("bash", { command: "echo probe" })], {
                stopReason: "toolUse",
            }),
            fauxAssistantMessage("finished"),
        ]);
        const collecting = h.stream("thread-1", done);
        await new Promise((resolve) => setTimeout(resolve, 50));
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });

        return { h, events: await collecting };
    }

    it("sends only the events after the cursor, without a snapshot", async () => {
        const { h, events } = await completedTurn();
        const run = events[0].run;
        const first = events.find((e) => e.kind === "entry");

        const resumed = await h.stream("thread-1", done, `?run=${run}&after=${first.sequence}`);

        expect(resumed[0]).toMatchObject({
            kind: "resumed",
            run,
            sequence: first.sequence,
            session: { id: "thread-1" },
            context: [],
        });
        expect(resumed.some((e) => e.kind === "snapshot")).toBe(false);
        const later = (e: any) => e.kind === "entry" && e.sequence > first.sequence;
        expect(resumed.filter(later)).toEqual(events.filter(later));
        expect(resumed.filter((e) => e.kind === "entry" && e.sequence <= first.sequence)).toEqual(
            [],
        );
        expect(resumed.at(-1)).toMatchObject({ kind: "state", state: "done", run });
        expect(resumed.at(-1).sequence).toBe(events.at(-1).sequence);
        const sequences = resumed.slice(1).map((e) => e.sequence);
        expect(sequences).toEqual([...sequences].sort((a, b) => a - b));
    });

    it("names a tool call that has no result yet at the cursor", async () => {
        const { h, events } = await completedTurn();
        const call = events.find((e) => e.kind === "entry" && e.entry.message.role === "assistant");

        const resumed = await h.stream("thread-1", done, `?run=${call.run}&after=${call.sequence}`);

        expect(resumed[0].context).toEqual([call.entry]);
        expect(resumed[1].entry.message).toMatchObject({
            role: "toolResult",
            toolCallId: call.entry.message.content[0].id,
        });
    });

    it.each([
        ["another run", (run: string) => `?run=other${run}&after=1`],
        ["a sequence ahead of the run", (run: string) => `?run=${run}&after=999999`],
        ["a malformed sequence", (run: string) => `?run=${run}&after=abc`],
        ["no run", () => "?after=1"],
    ])("sends a snapshot for %s", async (_name, query) => {
        const { h, events } = await completedTurn();

        const [first] = await h.stream("thread-1", (e) => e.length > 0, query(events[0].run));

        expect(first).toMatchObject({ kind: "snapshot", run: events[0].run, state: "done" });
        expect(first.entries).toHaveLength(4);
    });

    it("reads only the events after the cursor, as a resumed stream sends them", async () => {
        const { h, events } = await completedTurn();
        const run = events[0].run;
        const first = events.find((e) => e.kind === "entry");
        const cursor = `?run=${run}&after=${first.sequence}`;

        const delta = (await h.request("GET", `/sessions/thread-1${cursor}`)).body;
        const resumed = await h.stream("thread-1", done, cursor);
        const snapshot = (await h.request("GET", "/sessions/thread-1")).body;

        expect(delta).toMatchObject({
            kind: "delta",
            run,
            sequence: events.at(-1).sequence,
            session: { id: "thread-1" },
            state: "done",
            error: null,
            turnId: "k1",
            context: [],
        });
        expect(delta).not.toHaveProperty("entries");
        expect(delta.usage).toEqual(snapshot.usage);
        expect(delta.usage).toEqual(events.at(-1).usage);
        const later = (e: any) => e.kind === "entry" && e.sequence > first.sequence;
        expect(delta.events).toEqual([...events.filter(later), events.at(-1)]);
        expect(delta.context).toEqual(resumed[0].context);
        expect(delta.events).toEqual(resumed.slice(1));
    });

    it("reads a tool call that has no result yet at the cursor into the context", async () => {
        const { h, events } = await completedTurn();
        const call = events.find((e) => e.kind === "entry" && e.entry.message.role === "assistant");

        const delta = (
            await h.request("GET", `/sessions/thread-1?run=${call.run}&after=${call.sequence}`)
        ).body;

        expect(delta.kind).toBe("delta");
        expect(delta.context).toEqual([call.entry]);
        expect(delta.events[0].entry.message).toMatchObject({
            role: "toolResult",
            toolCallId: call.entry.message.content[0].id,
        });
    });

    it.each([
        ["no cursor", () => ""],
        ["another run", (run: string) => `?run=other${run}&after=1`],
        ["a sequence ahead of the run", (run: string) => `?run=${run}&after=999999`],
        ["a malformed sequence", (run: string) => `?run=${run}&after=abc`],
        ["no run", () => "?after=1"],
    ])("reads a snapshot for %s", async (_name, query) => {
        const { h, events } = await completedTurn();

        const read = (await h.request("GET", `/sessions/thread-1${query(events[0].run)}`)).body;

        expect(read).toMatchObject({
            kind: "snapshot",
            run: events[0].run,
            sequence: events.at(-1).sequence,
            state: "done",
        });
        expect(read.entries).toHaveLength(4);
    });

    it("reads no events at the newest sequence", async () => {
        const { h, events } = await completedTurn();
        const newest = events.at(-1);

        const delta = (
            await h.request("GET", `/sessions/thread-1?run=${newest.run}&after=${newest.sequence}`)
        ).body;

        expect(delta).toMatchObject({
            kind: "delta",
            run: newest.run,
            sequence: newest.sequence,
            state: "done",
            context: [],
            events: [],
        });
    });

    it("sends a snapshot for a cursor from before a restart", async () => {
        const { h, events } = await completedTurn();
        const cursor = `?run=${events[0].run}&after=${events.at(-1).sequence}`;

        harness = await h.restart();
        const [first] = await harness.stream("thread-1", (e) => e.length > 0, cursor);

        expect(first).toMatchObject({ kind: "snapshot", state: "done" });
        expect(first.run).not.toBe(events[0].run);
        expect(first.entries).toHaveLength(4);
    });
});

describe("token usage", () => {
    it("sums several model calls and reports the peak context", async () => {
        const h = await created();
        expect((await h.request("GET", "/sessions/thread-1")).body.usage).toEqual({
            input: 0,
            output: 0,
            cacheRead: 0,
            cacheWrite: 0,
            total: 0,
            calls: 0,
            peakContext: 0,
            cost: 0,
        });
        h.faux.setResponses([
            fauxAssistantMessage([fauxToolCall("bash", { command: "echo probe" })], {
                stopReason: "toolUse",
            }),
            fauxAssistantMessage("first answer"),
        ]);
        const collecting = h.stream("thread-1", (events) =>
            events.some((event) => event.kind === "state" && event.state === "done"),
        );
        await new Promise((resolve) => setTimeout(resolve, 50));
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const done = (await collecting).at(-1);

        h.faux.appendResponses([fauxAssistantMessage("second answer, with a longer completion")]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k2", text: "again" });
        const snapshot = await settled(h);
        const calls = countedCalls(snapshot.entries);

        expect(calls).toHaveLength(3);
        expect(done.usage).toEqual(aggregate(calls.slice(0, 2)));
        expect(snapshot.usage).toEqual(aggregate(calls));
        expect(snapshot.usage.output).toBeGreaterThan(calls.at(-1)?.output ?? 0);
        expect(snapshot.usage.peakContext).toBeLessThan(
            calls.reduce((sum, call) => sum + call.input + call.cacheRead + call.cacheWrite, 0),
        );
        expect(snapshot.usage.total).toBe(
            snapshot.usage.input +
                snapshot.usage.output +
                snapshot.usage.cacheRead +
                snapshot.usage.cacheWrite,
        );
    });

    it("skips an assistant message without numeric usage and a non-message usage entry", async () => {
        const h = await created();
        h.faux.setResponses([
            fauxAssistantMessage([fauxToolCall("bash", { command: "echo probe" })], {
                stopReason: "toolUse",
            }),
            fauxAssistantMessage("finished"),
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const before = (await settled(h)).usage;
        expect(before.calls).toBe(2);

        const fixtureIds = await appendSessionEntries(h, [
            {
                type: "message",
                id: "partial1",
                timestamp: "2026-09-26T00:00:00.000Z",
                message: {
                    role: "assistant",
                    content: [{ type: "text", text: "ignored call" }],
                    api: "faux",
                    provider: "faux",
                    model: "model-a",
                    stopReason: "stop",
                    timestamp: 1,
                    usage: { input: 1000, output: 1000, cacheRead: 1000 },
                },
            },
            {
                type: "usage",
                id: "warmuse1",
                timestamp: "2026-09-26T00:00:01.000Z",
                kind: "cache_warm",
                provider: "faux",
                model: "model-a",
                usage: {
                    input: 2000,
                    output: 2000,
                    cacheRead: 2000,
                    cacheWrite: 2000,
                    totalTokens: 8000,
                    cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 },
                },
            },
            {
                type: "message",
                id: "counted1",
                timestamp: "2026-09-26T00:00:02.000Z",
                message: {
                    role: "assistant",
                    content: [{ type: "text", text: "counted call" }],
                    api: "faux",
                    provider: "faux",
                    model: "model-a",
                    stopReason: "stop",
                    timestamp: 2,
                    usage: {
                        input: 3,
                        output: 4,
                        cacheRead: 5,
                        cacheWrite: 6,
                        totalTokens: 18,
                        cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 },
                    },
                },
            },
        ]);

        harness = await h.restart();
        const snapshot = (await harness.request("GET", "/sessions/thread-1")).body;

        expect(snapshot.entries.some((entry: any) => entry.id === fixtureIds.get("partial1"))).toBe(
            true,
        );
        expect(snapshot.usage).toEqual({
            input: before.input + 3,
            output: before.output + 4,
            cacheRead: before.cacheRead + 5,
            cacheWrite: before.cacheWrite + 6,
            total: before.total + 18,
            calls: before.calls + 1,
            peakContext: Math.max(before.peakContext, 14),
            cost: null,
        });
    });

    it("sums priced calls and leaves the cost unknown after an unpriced call", async () => {
        const h = await created();
        const priced = (id: string, total: number) => ({
            type: "message",
            id,
            timestamp: "2026-10-27T00:00:00.000Z",
            message: {
                role: "assistant",
                content: [{ type: "text", text: id }],
                api: "faux",
                provider: "faux",
                model: "model-a",
                stopReason: "stop",
                timestamp: 1,
                usage: {
                    input: 10,
                    output: 20,
                    cacheRead: 30,
                    cacheWrite: 40,
                    totalTokens: 100,
                    cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total },
                },
            },
        });
        await appendSessionEntries(h, [priced("pricedcall1", 0.25), priced("pricedcall2", 0.5)]);
        const restarted = await h.restart();
        harness = restarted;

        expect((await restarted.request("GET", "/sessions/thread-1")).body.usage.cost).toBeCloseTo(
            0.75,
        );

        await appendSessionEntries(restarted, [priced("unpricedcall", 0)]);
        const again = await restarted.restart();
        harness = again;

        expect((await again.request("GET", "/sessions/thread-1")).body.usage.cost).toBeNull();
    });
});

describe("large session reads", () => {
    it("observes and reads a session file over the Gateway budget", async () => {
        const h = await created();
        h.faux.setResponses([fauxAssistantMessage("initial turn")]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const before = await settled(h);
        const maxBytes = 16 * 1024 * 1024;
        const fixtureIds = await appendSessionEntries(h, [
            {
                type: "message",
                id: "large-history",
                timestamp: "2026-10-21T00:00:00.000Z",
                message: { role: "user", content: "😀".repeat(5 * 1024 * 1024), timestamp: 1 },
            },
            {
                type: "message",
                id: "latest",
                timestamp: "2026-10-21T00:00:01.000Z",
                message: {
                    role: "assistant",
                    content: [{ type: "text", text: "latest answer" }],
                    api: "faux",
                    provider: "faux",
                    model: "model-a",
                    stopReason: "stop",
                    timestamp: 2,
                    usage: {
                        input: 3,
                        output: 4,
                        cacheRead: 5,
                        cacheWrite: 6,
                        totalTokens: 18,
                        cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 },
                    },
                },
            },
        ]);
        harness = await h.restart();
        const full = (await harness.request("GET", "/sessions/thread-1")).body;
        expect(Buffer.byteLength(JSON.stringify(full))).toBeGreaterThan(maxBytes);

        const state = await harness.request("GET", "/sessions/thread-1?view=state");
        expect(state.status).toBe(200);
        expect(state.body).toMatchObject({ kind: "state", state: "done", turnId: "k1" });
        expect(state.body).not.toHaveProperty("entries");
        expect(state.body.usage.total).toBe(before.usage.total + 18);
        expect(state.body.usage).toEqual(full.usage);

        const bounded = await harness.request("GET", `/sessions/thread-1?max_bytes=${maxBytes}`);
        expect(bounded.status).toBe(200);
        expect(Buffer.byteLength(JSON.stringify(bounded.body))).toBeLessThanOrEqual(maxBytes);
        expect(bounded.body.entries[0].message).toMatchObject({ role: "system" });
        expect(bounded.body.entries[0].message.content).toContain("Transcript truncated");
        expect(bounded.body.entries.at(-1).id).toBe(fixtureIds.get("latest"));
        expect(bounded.body.usage).toEqual(full.usage);
        expect(bounded.body.sequence).toBe(full.sequence);

        const [streamed] = await harness.stream(
            "thread-1",
            (events) => events.length > 0,
            `?max_bytes=${maxBytes}`,
        );
        expect(streamed).toEqual(bounded.body);
    });

    it("resets an oversized delta to a bounded snapshot and resumes at its current cursor", async () => {
        const h = await created();
        const start = (await h.request("GET", "/sessions/thread-1")).body;
        h.faux.setResponses([fauxAssistantMessage("😀".repeat(2000))]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const full = await settled(h);

        const reset = await h.request(
            "GET",
            `/sessions/thread-1?run=${start.run}&after=${start.sequence}&max_bytes=4096`,
        );
        expect(reset.body.kind).toBe("snapshot");
        expect(Buffer.byteLength(JSON.stringify(reset.body))).toBeLessThanOrEqual(4096);
        expect(reset.body.entries[0].message.content).toContain("Transcript truncated");
        expect(reset.body.sequence).toBe(full.sequence);
        expect(reset.body.usage).toEqual(full.usage);

        const next = await h.request(
            "GET",
            `/sessions/thread-1?run=${reset.body.run}&after=${reset.body.sequence}&max_bytes=4096`,
        );
        expect(next.body).toMatchObject({ kind: "delta", events: [], state: "done" });
    });

    it("keeps an exact-budget UTF-8 snapshot intact and marks a read one byte smaller", async () => {
        const h = await created();
        h.faux.setResponses([fauxAssistantMessage("😀".repeat(2000))]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const full = await settled(h);
        const bytes = Buffer.byteLength(JSON.stringify(full));

        const exact = await h.request("GET", `/sessions/thread-1?max_bytes=${bytes}`);
        expect(exact.body).toEqual(full);

        const smaller = await h.request("GET", `/sessions/thread-1?max_bytes=${bytes - 1}`);
        expect(Buffer.byteLength(JSON.stringify(smaller.body))).toBeLessThanOrEqual(bytes - 1);
        expect(smaller.body.entries[0].message.content).toContain("Transcript truncated");
    });

    it.each(["0", "-1", "4095", "abc", "1.5", "9007199254740992"])(
        "rejects invalid response budget %s",
        async (value) => {
            const h = await created();
            const response = await h.request("GET", `/sessions/thread-1?max_bytes=${value}`);
            expect(response.status).toBe(422);
            expect(response.body.error.code).toBe("validation.failed");
        },
    );
});

describe("restart", () => {
    it("reloads the transcript and keeps completed outcomes", async () => {
        const h = await created();
        h.faux.setResponses([fauxAssistantMessage("persisted")]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await settled(h);

        harness = await h.restart();
        const snapshot = (await harness.request("GET", "/sessions/thread-1")).body;

        expect(snapshot.state).toBe("done");
        expect(snapshot.entries.map((e: any) => e.message.role)).toEqual(["user", "assistant"]);
        const repeat = await harness.request("POST", "/sessions/thread-1/messages", {
            key: "k1",
            text: "go",
        });
        expect(repeat.body.duplicate).toBe(true);
    });

    it("resumes a turn that was active during a restart without a Gateway resend", async () => {
        const h = await created();
        let started!: () => void;
        const active = new Promise<void>((resolve) => {
            started = resolve;
        });
        h.faux.setResponses([
            async (_context, options) => {
                await new Promise<void>((resolve) => {
                    options!.signal!.addEventListener("abort", () => resolve(), { once: true });
                    started();
                });
                return fauxAssistantMessage("cut off");
            },
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await active;
        harness = await h.restart();
        harness.faux.setResponses([fauxAssistantMessage("resumed answer")]);
        const snapshot = await settled(harness);
        expect(snapshot).toMatchObject({ state: "done", error: null, turnId: "k1" });
        expect(snapshot.entries.filter((entry: any) => entry.message.role === "user")).toHaveLength(
            1,
        );
        expect(snapshot.entries.at(-1).message.content).toEqual([
            { type: "text", text: "resumed answer" },
        ]);
        expect(
            (
                await harness.request("POST", "/sessions/thread-1/messages", {
                    key: "k1",
                    text: "go",
                })
            ).body.duplicate,
        ).toBe(true);
    });
});

interface CallUsage {
    input: number;
    output: number;
    cacheRead: number;
    cacheWrite: number;
}

/** Assistant messages that report all four token counts, in transcript order. */
function countedCalls(entries: any[]): CallUsage[] {
    return entries.flatMap((entry) => {
        const usage = entry.message?.usage;
        if (entry.message?.role !== "assistant" || usage === undefined || usage === null) {
            return [];
        }
        const call = {
            input: usage.input,
            output: usage.output,
            cacheRead: usage.cacheRead,
            cacheWrite: usage.cacheWrite,
        };
        return Object.values(call).every((value) => typeof value === "number") ? [call] : [];
    });
}

function aggregate(calls: CallUsage[]) {
    const usage = {
        input: 0,
        output: 0,
        cacheRead: 0,
        cacheWrite: 0,
        total: 0,
        calls: calls.length,
        peakContext: 0,
        // Faux calls carry tokens at a zero price, which is an unknown cost.
        cost: calls.length === 0 ? 0 : null,
    };
    for (const call of calls) {
        usage.input += call.input;
        usage.output += call.output;
        usage.cacheRead += call.cacheRead;
        usage.cacheWrite += call.cacheWrite;
        usage.peakContext = Math.max(
            usage.peakContext,
            call.input + call.cacheRead + call.cacheWrite,
        );
    }
    usage.total = usage.input + usage.output + usage.cacheRead + usage.cacheWrite;

    return usage;
}
