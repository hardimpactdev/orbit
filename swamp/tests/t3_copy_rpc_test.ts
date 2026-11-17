/** `t3_copy_rpc.mjs`, run as the copy-turn phase runs it (`node -` with the script on stdin), against a stand-in T3 command and a
 * stand-in of the T3 server's own WebSocket RPC. No live T3. */
import { assert, assertEquals } from "jsr:@std/assert@1";

const script = new URL("../ansible/t3_copy_rpc.mjs", import.meta.url).pathname;
const available = new Deno.Command("sh", { args: ["-c", "command -v node"] }).outputSync().success;
const thread = "mcp:66105c2c";
const run = "run:thread:mcp%3A66105c2c:ordinal:1";
const token = "t3-session-" + "x".repeat(40);

type Request = { id: string; kind: string; status: string };
// `interrupt` is how T3 takes a `run.interrupt`: `end` ends the run, `refuse` answers a failure, `stuck` accepts it but the run never ends.
type Server = { requests: Request[]; questions: Record<string, unknown[]>; runStatus: string; commands: Record<string, unknown>[]; interrupt?: "end" | "refuse" | "stuck";
    projects?: { id: string; workspaceRoot: string; archivedAt: string | null }[] };

/** Answers T3's ticket and RPC as T3 0.1.7 does, for one thread whose state the test sets. A stop ends the run and cancels its requests. */
function serve(state: Server) {
    return Deno.serve({ port: 0, hostname: "127.0.0.1", onListen: () => {} }, (request) => {
        const url = new URL(request.url);
        if (url.pathname === "/api/auth/websocket-ticket") {
            return request.headers.get("authorization") === `Bearer ${token}` ? Response.json({ ticket: "ticket-1" }) : new Response("no", { status: 401 });
        }
        assertEquals([url.pathname, url.searchParams.get("orchestrationProtocol"), url.searchParams.get("wsTicket")], ["/ws", "2", "ticket-1"]);
        const { socket, response } = Deno.upgradeWebSocket(request);
        socket.onmessage = (event) => {
            const frame = JSON.parse(String(event.data));
            let value: unknown;
            if (frame.tag === "orchestration.subscribeShell") {
                // A stream: its first chunk holds the snapshot. The helper interrupts it after.
                socket.send(JSON.stringify({ _tag: "Chunk", requestId: frame.id, values: [{ kind: "snapshot", snapshot: { projects: state.projects ?? [] } }] }) + "\n");
                return;
            }
            if (frame._tag === "Interrupt") return;
            if (frame.tag === "orchestration.getThreadProjection") {
                value = { thread: { id: thread }, runs: [{ id: run, status: state.runStatus }], runtimeRequests: state.requests,
                    turnItems: Object.entries(state.questions).map(([requestId, questions]) => ({ type: "user_input_request", requestId, questions })) };
            } else {
                state.commands.push(frame.payload);
                if (state.interrupt === "refuse") {
                    socket.send(JSON.stringify({ _tag: "Exit", requestId: frame.id, exit: { _tag: "Failure", cause: { _tag: "Fail", error: "run is not interruptible" } } }) + "\n");
                    return;
                }
                if (state.interrupt !== "stuck") {
                    state.runStatus = "interrupted";
                    state.requests = state.requests.map((pending) => ({ ...pending, status: "cancelled" }));
                }
                value = { sequence: 9 };
            }
            socket.send(JSON.stringify({ _tag: "Exit", requestId: frame.id, exit: { _tag: "Success", value } }) + "\n");
        };
        return response;
    });
}

type Ran = { code: number; out: Record<string, unknown> | null; stderr: string; revoked: string[] };
async function helper(state: Server, args: string[], issue = "json"): Promise<Ran> {
    const folder = Deno.makeTempDirSync();
    const server = serve(state);
    try {
        Deno.mkdirSync(`${folder}/home/.t3/userdata`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/home/.t3/userdata/server-runtime.json`, JSON.stringify({ origin: `http://127.0.0.1:${server.addr.port}` }));
        const issued = issue === "json" ? JSON.stringify({ sessionId: "session-1", token }) : `warning: ${token} "sessionId": "session-1"`;
        Deno.writeTextFileSync(`${folder}/t3`, `#!/bin/sh
if [ "$1 $2 $3" = "auth session issue" ]; then printf '%s' '${issued}'; exit 0; fi
if [ "$1 $2 $3" = "auth session revoke" ]; then echo "$4" >> "${folder}/revoked"; exit 0; fi
exit 2
`);
        Deno.chmodSync(`${folder}/t3`, 0o755);
        const child = new Deno.Command("node", { args: ["-", `${folder}/t3`, ...args], stdin: "piped", stdout: "piped", stderr: "piped",
            env: { HOME: `${folder}/home`, PATH: Deno.env.get("PATH") ?? "" }, clearEnv: true }).spawn();
        const writer = child.stdin.getWriter();
        await writer.write(Deno.readFileSync(script));
        await writer.close();
        const done = await child.output();
        const stdout = new TextDecoder().decode(done.stdout);
        let revoked: string[] = [];
        try {
            revoked = Deno.readTextFileSync(`${folder}/revoked`).split("\n").filter(Boolean);
        } catch {
            revoked = [];
        }
        return { code: done.code, out: stdout === "" ? null : JSON.parse(stdout), stderr: new TextDecoder().decode(done.stderr), revoked };
    } finally {
        await server.shutdown();
        Deno.removeSync(folder, { recursive: true });
    }
}

const color = { id: "Which color?", header: "Color", question: "Which color?", options: [{ label: "Red", description: "Choose red" }], multiSelect: false };
const waiting = (requests: Request[], questions: Record<string, unknown[]>): Server => ({ requests, questions, runStatus: "running", commands: [] });

Deno.test({ name: "the read prints every pending question in the result step's shape and stops nothing; free text without options is stored whole",
    ignore: !available, async fn() {
        const state = waiting([{ id: "r1", kind: "user_input", status: "pending" }], { r1: [color, { id: "Why?", question: "Why does it fail?" }] });
        const read = await helper(state, ["read", thread]);
        assertEquals(read.code, 0, read.stderr);
        assertEquals(read.out, { runs: [{ id: run, status: "running" }], stoppable: true, requests: [{ request_id: "r1", kind: "user_input", questions: [
            { id: "Which color?", header: "Color", question: "Which color?", options: [{ label: "Red", description: "Choose red" }], multiSelect: false },
            { id: "Why?", header: "", question: "Why does it fail?", options: [] },
        ] }] });
        assertEquals([state.commands, read.revoked], [[], ["session-1"]]);
        // An approval is not a question Orbit can store: the read says the run may not be stopped.
        const approval = await helper(waiting([{ id: "a1", kind: "approval", status: "pending" }], {}), ["read", thread]);
        assertEquals(approval.out!.stoppable, false);
        // A question too large to fit the result step's bound once escaped is not stored whole either.
        const large = await helper(waiting([{ id: "r1", kind: "user_input", status: "pending" }], { r1: [{ ...color, question: "x".repeat(160000) }] }), ["read", thread]);
        assertEquals([large.code, large.out!.stoppable, (large.out!.requests as unknown[]).length], [0, false, 1]);
    } });

Deno.test({ name: "the stop ends the run only while it waits for exactly the stored questions", ignore: !available, async fn() {
    const state = waiting([{ id: "r1", kind: "user_input", status: "pending" }], { r1: [color] });
    const stopped = await helper(state, ["interrupt", thread, run, "r1"]);
    assertEquals(stopped.out, { interrupted: true, error: null, runs: [], pending: 0 });
    assertEquals([state.commands.length, state.commands[0].type, state.commands[0].runId, stopped.revoked], [1, "run.interrupt", run, ["session-1"]]);
    // R1 was answered and the run now asks R2, or also raises an approval: the run goes on and R2 is not lost.
    for (const requests of [[{ id: "r2", kind: "user_input", status: "pending" }],
        [{ id: "r1", kind: "user_input", status: "pending" }, { id: "a1", kind: "approval", status: "pending" }]]) {
        const other = waiting(requests, { r2: [color], r1: [color] });
        const kept = await helper(other, ["interrupt", thread, run, "r1"]);
        assertEquals([kept.out!.interrupted, other.commands, other.runStatus], [false, [], "running"]);
        assert(String(kept.out!.error).includes("asks something else"));
    }
    // Two stored questions, in any order, are the set the run waits for.
    const two = waiting([{ id: "r2", kind: "user_input", status: "pending" }, { id: "r1", kind: "user_input", status: "pending" }], { r1: [color], r2: [color] });
    assertEquals((await helper(two, ["interrupt", thread, run, "r1\nr2"])).out!.interrupted, true);
} });

Deno.test({ name: "a session issue that prints no JSON fails without quoting it and still revokes the session it names", ignore: !available, async fn() {
    const broken = await helper(waiting([], {}), ["read", thread], "garbled");
    assertEquals([broken.code, broken.out, broken.revoked], [1, null, ["session-1"]]);
    assertEquals(broken.stderr.includes(token), false);
} });

Deno.test({ name: "the stop ends the thread's active run through the same interrupt and waits for its end; a thread with no active run is left alone",
    ignore: !available, async fn() {
        const state = waiting([], {});
        const stopped = await helper(state, ["stop", thread]);
        assertEquals([stopped.code, stopped.out, stopped.revoked], [0, { stopped: true, run, state: "interrupted" }, ["session-1"]]);
        assertEquals([state.commands.length, state.commands[0].type, state.commands[0].threadId, state.commands[0].runId], [1, "run.interrupt", thread, run]);
        // A run that asks is stopped too: its turn is over in Orbit, whatever the run waits for.
        const asking = waiting([{ id: "a1", kind: "approval", status: "pending" }], {});
        assertEquals((await helper(asking, ["stop", thread])).out, { stopped: true, run, state: "interrupted" });
        // T3 is already done with the run: nothing is sent.
        const over = { ...waiting([], {}), runStatus: "completed" };
        const idle = await helper(over, ["stop", thread]);
        assertEquals([idle.code, idle.out, over.commands, idle.revoked], [0, { stopped: false, run: null, state: null }, [], ["session-1"]]);
    } });

Deno.test({ name: "a stop that T3 refuses, or a run that does not end within the deadline, answers stopped: false with the run's last state",
    ignore: !available, async fn() {
        const refusing = { ...waiting([], {}), interrupt: "refuse" as const };
        const refused = await helper(refusing, ["stop", thread]);
        assertEquals([refused.code, refused.out, refusing.commands.length, refused.revoked], [0, { stopped: false, run, state: "running" }, 1, ["session-1"]]);
        // The wait ends inside the phase's 60 s bound, so the answer is always written.
        const stuck = { ...waiting([], {}), interrupt: "stuck" as const };
        const began = Date.now();
        const kept = await helper(stuck, ["stop", thread]);
        assertEquals([kept.code, kept.out, kept.revoked], [0, { stopped: false, run, state: "running" }, ["session-1"]]);
        assert(Date.now() - began < 60000, `the stop took ${Date.now() - began} ms`);
    } });

Deno.test({ name: "the create makes a thread of the workspace's project with the title, instance, model and options, in full access, and sends no message",
    ignore: !available, async fn() {
    const options = [{ id: "reasoningEffort", value: "high" }];
    const state: Server = { ...waiting([], {}), projects: [{ id: "old", workspaceRoot: "/srv/workspace", archivedAt: "2026-01-01" },
        { id: "other", workspaceRoot: "/srv/other", archivedAt: null }, { id: "p1", workspaceRoot: "/srv/workspace", archivedAt: null }] };
    const made = await helper(state, ["create", "Orbit task 1 thread 2", "codexHigh", "gpt-6.1-sol", JSON.stringify(options)]);
    assertEquals([made.code, made.out!.error, made.revoked], [0, null, ["session-1"]]);
    assert(String(made.out!.thread_id).startsWith("orbit:"));
    const [create] = state.commands;
    assertEquals(state.commands.length, 1);
    assertEquals({ ...create, commandId: null, createdAt: null }, { type: "thread.create", commandId: null, threadId: made.out!.thread_id, projectId: "p1",
        title: "Orbit task 1 thread 2", modelSelection: { instanceId: "codexHigh", model: "gpt-6.1-sol", options }, runtimeMode: "full-access",
        interactionMode: "default", branch: null, worktreePath: null, createdBy: "system", creationSource: "server", createdAt: null });
    // A Claude thread carries no options. A refused create, or no project at the workspace, makes no thread and says why.
    const claude = await helper({ ...waiting([], {}), projects: [{ id: "p1", workspaceRoot: "/srv/workspace", archivedAt: null }] },
        ["create", "Orbit task 1 thread 3", "claudeMedium", "claude-opus-5-5", "[]"]);
    assertEquals(claude.out!.error, null);
    const refused = await helper({ ...waiting([], {}), interrupt: "refuse", projects: [{ id: "p1", workspaceRoot: "/srv/workspace", archivedAt: null }] },
        ["create", "Orbit task 1 thread 4", "codexHigh", "gpt-6.1-sol", "[]"]);
    assertEquals(refused.out!.thread_id, null);
    assert(String(refused.out!.error).includes("run is not interruptible"));
    const none = await helper(waiting([], {}), ["create", "Orbit task 1 thread 5", "codexHigh", "gpt-6.1-sol", "[]"]);
    assertEquals(none.out, { thread_id: null, error: "T3 holds no project at /srv/workspace." });
} });
