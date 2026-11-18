/** `t3_copy_rpc.mjs`, run as the copy-turn phase runs it (`node -` with the script on stdin), against a stand-in T3 command and a
 * stand-in of the T3 server's own WebSocket RPC. No live T3. */
import { assert, assertEquals } from "jsr:@std/assert@1";

const script = new URL("../ansible/t3_copy_rpc.mjs", import.meta.url).pathname;
const available = new Deno.Command("sh", { args: ["-c", "command -v node"] }).outputSync().success;
const thread = "mcp:66105c2c";
const run = "run:thread:mcp%3A66105c2c:ordinal:1";
const token = "t3-session-" + "x".repeat(40);
const launchEnvironment = "fixture-launch-environment";
const launchWitness = "session-1";
const launchText = "  Fixture first message · β\nline two.  \n";
const acceptedAt = "2026-11-17T17:58:00.123Z";
const launchRequest = { commandId: "fixture-command", threadId: "fixture-thread", projectId: "fixture-project", creationSource: "mobile",
    initialMessage: { messageId: "fixture-user-message", text: launchText, attachments: [] } };
const acceptedProjection = () => ({ thread: { id: launchRequest.threadId, projectId: launchRequest.projectId },
    messages: [{ id: launchRequest.initialMessage.messageId, threadId: launchRequest.threadId, role: "user", text: launchText, createdAt: acceptedAt }] });
type LaunchProjection = ReturnType<typeof acceptedProjection>;

type Request = { id: string; kind: string; status: string; responseCapability?: { type: "message" | "live" }; runId?: string; ownerless?: boolean };
// `interrupt` is how T3 takes a `run.interrupt`: `end` ends the run, `refuse` answers a failure, `stuck` accepts it but the run never ends.
type Server = { requests: Request[]; questions: Record<string, unknown[]>; runStatus: string; commands: Record<string, unknown>[]; interrupt?: "end" | "refuse" | "stuck"; respond?: "refuse" | "stuck" | "raced";
    projects?: { id: string; workspaceRoot: string; archivedAt: string | null }[]; launchProjection?: LaunchProjection; dropLaunchReply?: boolean;
    launchReadFails?: boolean; servedEnvironment?: string; config?: unknown; bounded?: unknown;
    history?: Record<string, unknown>; historyReads?: string[]; replaceStateInPlace?: boolean };

/** Answers T3's ticket and RPC as T3 0.1.7 does, for one thread whose state the test sets. A stop cancels live requests but leaves message-capability questions pending. */
function serve(state: Server) {
    return Deno.serve({ port: 0, hostname: "127.0.0.1", onListen: () => {} }, (request) => {
        const url = new URL(request.url);
        if (url.pathname === "/.well-known/t3/environment") {
            return Response.json({ environmentId: state.servedEnvironment ?? launchEnvironment, serverVersion: "0.1.7" });
        }
        if (url.pathname === "/api/auth/websocket-ticket") {
            return request.headers.get("authorization") === `Bearer ${token}` ? Response.json({ ticket: "ticket-1" }) : new Response("no", { status: 401 });
        }
        const threadPath = "/api/orchestration/threads/" + launchRequest.threadId;
        if (url.pathname.startsWith(threadPath + "/")) {
            assert(request.headers.get("authorization") === "Bearer " + token);
            assertEquals(request.headers.get("x-t3-orchestration-protocol"), "2");
            if (url.pathname === threadPath + "/bounded") return Response.json(state.bounded ?? null);
            assertEquals([url.pathname, url.searchParams.get("view")], [threadPath + "/history", "conversation"]);
            const cursor = url.searchParams.get("cursor")!;
            state.historyReads?.push(cursor);
            return state.history?.[cursor] === undefined ? new Response(null, { status: 500 }) : Response.json(state.history[cursor]);
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
            if (frame.tag === "server.getConfig") {
                value = state.config;
            } else if (frame.tag === "orchestration.launchThread") {
                state.commands.push(frame.payload);
                state.launchProjection = acceptedProjection();
                if (state.dropLaunchReply) {
                    socket.close();
                    return;
                }
                value = { threadId: launchRequest.threadId, projection: state.launchProjection };
            } else if (frame.tag === "orchestration.getThreadProjection" && state.launchReadFails) {
                socket.send(JSON.stringify({ _tag: "Exit", requestId: frame.id, exit: { _tag: "Failure", cause: launchText } }) + "\n");
                return;
            } else if (frame.tag === "orchestration.getThreadProjection" && state.launchProjection !== undefined) {
                value = state.launchProjection;
            } else if (frame.tag === "orchestration.getThreadProjection") {
                value = { nodes: state.requests.filter((r) => !r.ownerless).map((r) => ({ id: `node:${r.id}`, runId: r.runId ?? run })), thread: { id: thread }, runs: [{ id: run, status: state.runStatus }], runtimeRequests: state.requests.map((r) => ({ ...r, nodeId: `node:${r.id}` })),
                    turnItems: Object.entries(state.questions).map(([requestId, questions]) => ({ type: "user_input_request", requestId, questions })) };
            } else {
                state.commands.push(frame.payload);
                if (frame.payload.type === "runtime-request.respond") {
                    const target = state.requests.find((r) => r.id === frame.payload.requestId)!;
                    if (state.respond !== "stuck" && state.respond !== "refuse") target.status = "resolved";
                    if (state.respond === "refuse" || state.respond === "raced") {
                        socket.send(JSON.stringify({ _tag: "Exit", requestId: frame.id, exit: { _tag: "Failure", cause: { _tag: "Fail", error: "request already answered or refused" } } }) + "\n");
                        return;
                    }
                } else if (state.interrupt === "refuse") {
                    socket.send(JSON.stringify({ _tag: "Exit", requestId: frame.id, exit: { _tag: "Failure", cause: { _tag: "Fail", error: "run is not interruptible" } } }) + "\n");
                    return;
                }
                if (frame.payload.type === "run.interrupt" && state.interrupt !== "stuck") {
                    state.runStatus = "interrupted";
                    state.requests = state.requests.map((pending) => ({ ...pending, status: pending.responseCapability?.type === "message" ? pending.status : "cancelled" }));
                }
                value = { sequence: 9 };
            }
            socket.send(JSON.stringify({ _tag: "Exit", requestId: frame.id, exit: { _tag: "Success", value } }) + "\n");
        };
        return response;
    });
}

type Ran = { code: number; out: Record<string, unknown> | null; stderr: string; revoked: string[] };
async function helper(state: Server, args: string[], issue = "json", frozen?: (incarnation: string) => object): Promise<Ran> {
    const folder = Deno.makeTempDirSync();
    const server = serve(state);
    try {
        const userdata = `${folder}/home/.t3/userdata`;
        Deno.mkdirSync(userdata, { recursive: true });
        Deno.writeTextFileSync(`${userdata}/server-runtime.json`, JSON.stringify({ origin: `http://127.0.0.1:${server.addr.port}` }));
        Deno.writeTextFileSync(`${userdata}/environment-id`, `${launchEnvironment}\n`);
        const statePath = `${userdata}/statev2.sqlite`;
        const makeState = async (path: string, witness: string) => {
            const created = await new Deno.Command("node", {
                args: ["--input-type=module", "-e", 'import { DatabaseSync } from "node:sqlite"; const db = new DatabaseSync(process.argv[1]); db.exec("CREATE TABLE auth_sessions (session_id TEXT)"); db.prepare("INSERT INTO auth_sessions VALUES (?)").run(process.argv[2]); db.close();', path, witness],
            }).output();
            assert(created.success);
        };
        if (args[0].includes("launch")) {
            await makeState(statePath, launchWitness);
            if (state.replaceStateInPlace) {
                const original = Deno.statSync(statePath);
                await makeState(`${userdata}/replacement.sqlite`, "other-session");
                Deno.writeFileSync(statePath, Deno.readFileSync(`${userdata}/replacement.sqlite`));
                const replaced = Deno.statSync(statePath);
                assertEquals([replaced.dev, replaced.ino], [original.dev, original.ino]);
            }
        }
        const issued = issue === "json" ? JSON.stringify({ sessionId: "session-1", token }) : `warning: ${token} "sessionId": "session-1"`;
        Deno.writeTextFileSync(`${folder}/t3`, `#!/bin/sh
if [ "$1 $2 $3" = "auth session issue" ]; then printf '%s' '${issued}'; exit 0; fi
if [ "$1 $2 $3" = "auth session revoke" ]; then echo "$4" >> "${folder}/revoked"; exit 0; fi
exit 2
`);
        Deno.chmodSync(`${folder}/t3`, 0o755);
        const command = frozen === undefined ? ["-"] : ["--input-type=module", "-e", Deno.readTextFileSync(script), "--", "orbit-rpc"];
        const child = new Deno.Command("node", { args: [...command, `${folder}/t3`, ...args], stdin: "piped", stdout: "piped", stderr: "piped",
            env: { HOME: `${folder}/home`, PATH: Deno.env.get("PATH") ?? "" }, clearEnv: true }).spawn();
        const writer = child.stdin.getWriter();
        if (frozen === undefined) {
            await writer.write(Deno.readFileSync(script));
        } else {
            const { dev, ino } = Deno.statSync(`${userdata}/statev2.sqlite`);
            const bytes = new TextEncoder().encode(`${launchEnvironment}\0${dev}\0${ino}\0${launchWitness}`);
            const incarnation = Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)), (byte) => byte.toString(16).padStart(2, "0")).join("");
            await writer.write(new TextEncoder().encode(JSON.stringify(frozen(`${launchWitness}.${incarnation}`))));
        }
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
const waiting = (requests: Request[], questions: Record<string, unknown[]>): Server => ({ requests: requests.map((r) => ({ ...r,
    responseCapability: r.responseCapability ?? { type: r.kind === "user_input" ? "message" : "live" } })), questions, runStatus: "running", commands: [] });

Deno.test({ name: "the read prints every pending question in the result step's shape and stops nothing; free text without options is stored whole",
    ignore: !available, async fn() {
        const state = waiting([{ id: "r1", kind: "user_input", status: "pending" }], { r1: [color, { id: "Why?", question: "Why does it fail?" }] });
        const read = await helper(state, ["read", thread]);
        assertEquals(read.code, 0, read.stderr);
        assertEquals(read.out, { runs: [{ id: run, status: "running" }], stoppable: true, requests: [{ request_id: "r1", kind: "user_input", run_id: run, questions: [
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
        // A question whose request names no node of a run has no owner: the read says the run may not be stopped.
        const ownerless = await helper(waiting([{ id: "r1", kind: "user_input", status: "pending", ownerless: true }], { r1: [color] }), ["read", thread]);
        assertEquals([ownerless.code, ownerless.out!.stoppable, (ownerless.out!.requests as { run_id: unknown }[])[0].run_id], [0, false, null]);
    } });

Deno.test({ name: "the stop ends the run only while it waits for exactly the stored questions", ignore: !available, async fn() {
    const state = waiting([{ id: "r1", kind: "user_input", status: "pending" }], { r1: [color] });
    const stopped = await helper(state, ["interrupt", thread, run, "r1"]);
    assertEquals(stopped.out, { interrupted: true, error: null, runs: [], pending: 1 });
    assertEquals([state.commands.length, state.commands[0].type, state.commands[0].runId, stopped.revoked], [1, "run.interrupt", run, ["session-1"]]);
    // Claude's live question is cancelled by the same interrupt; Codex's message question above stays pending.
    const claude = waiting([{ id: "r1", kind: "user_input", status: "pending", responseCapability: { type: "live" } }], { r1: [color] });
    assertEquals((await helper(claude, ["interrupt", thread, run, "r1"])).out, { interrupted: true, error: null, runs: [], pending: 0 });
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

Deno.test({ name: "the stop ends exactly the named run through the same interrupt and waits for its end; a run already over is left alone",
    ignore: !available, async fn() {
        const state = waiting([], {});
        const stopped = await helper(state, ["stop", thread, run]);
        assertEquals([stopped.code, stopped.out, stopped.revoked], [0, { stopped: true, run, state: "interrupted" }, ["session-1"]]);
        assertEquals([state.commands.length, state.commands[0].type, state.commands[0].threadId, state.commands[0].runId], [1, "run.interrupt", thread, run]);
        // A run that asks is stopped too: its turn is over in Orbit, whatever the run waits for.
        const asking = waiting([{ id: "a1", kind: "approval", status: "pending" }], {});
        assertEquals((await helper(asking, ["stop", thread, run])).out, { stopped: true, run, state: "interrupted" });
        // T3 is already done with the run: nothing is sent.
        const over = { ...waiting([], {}), runStatus: "completed" };
        const idle = await helper(over, ["stop", thread, run]);
        assertEquals([idle.code, idle.out, over.commands, idle.revoked], [0, { stopped: false, run: null, state: "completed" }, [], ["session-1"]]);
        // Another run is active on the thread, but not the named one: nothing is stopped, and the answer says the run was not found.
        const other = waiting([], {});
        const refused = await helper(other, ["stop", thread, "orbit-run-A"]);
        assertEquals([refused.code, refused.out!.stopped, refused.out!.run, other.commands], [0, false, "orbit-run-A", []]);
    } });

Deno.test({ name: "a stop that T3 refuses, or a run that does not end within the deadline, answers stopped: false with the run's last state",
    ignore: !available, async fn() {
        const refusing = { ...waiting([], {}), interrupt: "refuse" as const };
        const refused = await helper(refusing, ["stop", thread, run]);
        assertEquals([refused.code, refused.out, refusing.commands.length, refused.revoked], [0, { stopped: false, run, state: "running" }, 1, ["session-1"]]);
        // The wait ends inside the phase's 60 s bound, so the answer is always written.
        const stuck = { ...waiting([], {}), interrupt: "stuck" as const };
        const began = Date.now();
        const kept = await helper(stuck, ["stop", thread, run]);
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

Deno.test({ name: "answered resolves exactly the stored pending questions once and leaves unrelated requests alone", ignore: !available, async fn() {
    const state = { ...waiting([
        { id: "r1", kind: "user_input", status: "pending" },
        { id: "r2", kind: "user_input", status: "pending", responseCapability: { type: "live" as const } },
        { id: "r3", kind: "user_input", status: "cancelled" },
        { id: "other", kind: "user_input", status: "pending" },
        { id: "approval", kind: "approval", status: "pending" },
    ], { r1: [color], r2: [color] }), runStatus: "interrupted" };
    const resolved = await helper(state, ["answered", thread, run, "r1", "r2", "r1", "r3", "missing"]);
    assertEquals([resolved.code, resolved.out, resolved.revoked], [0, { resolved: true, pending: [], error: null }, ["session-1"]]);
    assertEquals(state.commands.map(({ commandId: _, ...command }) => command), ["r1", "r2"].map((requestId) => ({
        type: "runtime-request.respond", threadId: thread, requestId, decision: "cancel",
    })));
    assertEquals(state.requests.filter((r) => r.status === "pending").map((r) => r.id), ["other", "approval"]);
    state.commands = [];
    assertEquals((await helper(state, ["answered", thread, run, "r1", "r2", "r3", "missing"])).out, { resolved: true, pending: [], error: null });
    assertEquals(state.commands, []);
} });

Deno.test({ name: "answered confirms resolution after a concurrent answer and refuses a stored request that stays pending", ignore: !available, async fn() {
    for (const respond of ["refuse", "stuck", "raced"] as const) {
        const state = { ...waiting([{ id: "r1", kind: "user_input", status: "pending" }], { r1: [color] }), respond };
        const done = await helper(state, ["answered", thread, run, "r1"]);
        assertEquals([done.code, done.out!.resolved, done.out!.pending, done.revoked], [0, respond === "raced", respond === "raced" ? [] : ["r1"], ["session-1"]]);
        assertEquals(done.out!.error === null, respond === "raced");
    }
    const approval = waiting([{ id: "a1", kind: "approval", status: "pending" }], {});
    const refused = await helper(approval, ["answered", thread, run, "a1"]);
    assertEquals([refused.out!.resolved, refused.out!.pending, approval.commands], [false, ["a1"], []]);
    // A stored request that another run owns is never cancelled: it stays pending, and the launch is refused.
    const foreign = waiting([{ id: "r1", kind: "user_input", status: "pending", runId: "run:B" }], { r1: [color] });
    const kept = await helper(foreign, ["answered", thread, run, "r1"]);
    assertEquals([kept.out!.resolved, kept.out!.pending, foreign.commands], [false, ["r1"], []]);
} });

Deno.test({ name: "interrupt never stops a new run for a stored question owned by an earlier run", ignore: !available, async fn() {
    const state = waiting([{ id: "r1", kind: "user_input", status: "pending", runId: "run:earlier" }], { r1: [color] });
    const done = await helper(state, ["interrupt", thread, run, "r1"]);
    assertEquals([done.out!.interrupted, state.commands, state.runStatus], [false, [], "running"]);
} });

const freezeLaunch = (incarnation: string) => ({ incarnation, environment_id: launchEnvironment, request: launchRequest });

Deno.test({ name: "Conn launch uses its frozen first message and recovers a lost reply without resending", ignore: !available, async fn() {
    const state = { ...waiting([], {}), dropLaunchReply: true, projects: [{ id: launchRequest.projectId, workspaceRoot: "/srv/workspace", archivedAt: null }],
        config: { providers: [], settings: { defaultModelSelection: { instanceId: "codex", model: "gpt-6.2-sol", options: { reasoningEffort: "high" } } } } };
    const inspected = await helper(state, ["inspect-launch", launchEnvironment]);
    assertEquals([inspected.code, inspected.out!.environment_id, inspected.out!.project_id, inspected.out!.error_code], [0, launchEnvironment, launchRequest.projectId, null]);
    assertEquals(inspected.out!.model_selection, state.config.settings.defaultModelSelection);
    assert(/^session-1\.[0-9a-f]{64}$/.test(String(inspected.out!.incarnation)));
    const lost = await helper(state, ["launch"], "json", freezeLaunch);
    assertEquals([lost.code, lost.out, lost.stderr, state.commands, lost.revoked],
        [0, { error_code: "conn.launch_first_message_unconfirmed" }, "", [launchRequest], ["session-1"]]);
    const recovered = await helper(state, ["reconcile-launch"], "json", freezeLaunch);
    assertEquals([recovered.code, recovered.out, recovered.stderr, state.commands, recovered.revoked],
        [0, { accepted_at: acceptedAt, error_code: null }, "", [launchRequest], ["session-1"]]);
} });

Deno.test({ name: "Conn launch fails closed on a replaced server or mismatched first message", ignore: !available, async fn() {
    const replaced = { ...waiting([], {}), servedEnvironment: "other-launch-environment" };
    const refused = await helper(replaced, ["launch"], "json", freezeLaunch);
    assertEquals([refused.out, replaced.commands], [{ error_code: "conn.launch_server_changed" }, []]);
    const overwritten = { ...waiting([], {}), replaceStateInPlace: true };
    for (const command of ["launch", "reconcile-launch"]) {
        const refusedInPlace = await helper(overwritten, [command], "json", freezeLaunch);
        assertEquals([refusedInPlace.out, overwritten.commands], [{ error_code: "conn.launch_server_changed" }, []]);
    }
    const original = waiting([], {});
    const wrongState = await helper(original, ["reconcile-launch"], "json", () => freezeLaunch("previous-state"));
    assertEquals([wrongState.out, original.commands], [{ error_code: "conn.launch_server_changed" }, []]);
    const differentText = acceptedProjection();
    differentText.messages[0].text = "A different user message.";
    const partial = acceptedProjection();
    partial.messages = [];
    for (const state of [
        { ...waiting([], {}), launchProjection: differentText },
        { ...waiting([], {}), launchProjection: partial },
        { ...waiting([], {}), launchReadFails: true },
    ]) {
        const rejected = await helper(state, ["reconcile-launch"], "json", freezeLaunch);
        assertEquals([rejected.out, rejected.stderr, state.commands], [{ error_code: "conn.launch_first_message_unconfirmed" }, "", []]);
    }
} });

Deno.test({ name: "Conn launch keeps the requested model on an enabled provider and resolves the fallback for the workspace", ignore: !available, async fn() {
    const project = { id: launchRequest.projectId, workspaceRoot: "/srv/workspace", archivedAt: null };
    const config = { providers: [
        { instanceId: "disabled", driver: "codex", enabled: true, status: "ready", models: [{ slug: "disabled-model" }] },
        { instanceId: "checking", driver: "codex", enabled: false, status: "checking", models: [] },
        { instanceId: "codex", driver: "codex", enabled: true, status: "ready", models: [
            { slug: "custom", isDefault: true, isCustom: true }, { slug: "ordinary" }, { slug: "default-model", isDefault: true },
        ] },
        { instanceId: "claudeAgent", driver: "claudeAgent", enabled: true, status: "ready", models: [{ slug: "claude-model" }] },
    ], settings: { projectSettingsOverrides: { [project.id]: { providerInstances: { disabled: { enabled: false }, checking: { enabled: true } } } } } };
    const state = { ...waiting([], {}), projects: [project], config };
    const retained = await helper(state, ["inspect-launch", launchEnvironment, "checking"]);
    assertEquals([retained.out!.requested_provider_available, retained.out!.model_selection, retained.revoked], [true, null, ["session-1"]]);
    const fallback = await helper(state, ["inspect-launch", launchEnvironment, "disabled"]);
    assertEquals([fallback.out!.requested_provider_available, fallback.out!.model_selection, state.commands],
        [false, { instanceId: "codex", model: "default-model" }, []]);
} });

Deno.test({ name: "Conn launch finds the first message in older local history without consulting a command receipt", ignore: !available, async fn() {
    const oldWindow = { ...acceptedProjection(), messages: [] };
    const item = { threadId: launchRequest.threadId, type: "user_message", createdBy: "user",
        messageId: launchRequest.initialMessage.messageId, text: launchText, startedAt: acceptedAt };
    const historyItem = { sourceThreadId: launchRequest.threadId, visibility: "local", item };
    const state = { ...waiting([], {}), launchProjection: oldWindow,
        bounded: { projection: oldWindow, hasMoreHistory: true, historyCursor: "later" },
        history: { later: { items: [], hasMoreHistory: true, nextCursor: "older" },
            older: { items: [historyItem], hasMoreHistory: false, nextCursor: null } }, historyReads: [] as string[] };
    const found = await helper(state, ["reconcile-launch"], "json", freezeLaunch);
    assertEquals([found.out, found.stderr, found.revoked, state.historyReads, state.commands],
        [{ accepted_at: acceptedAt, error_code: null }, "", ["session-1"], ["later", "older"], []]);

    state.history.older.items[0] = { ...historyItem, visibility: "inherited" };
    const inherited = await helper(state, ["reconcile-launch"], "json", freezeLaunch);
    assertEquals([inherited.out, state.commands], [{ error_code: "conn.launch_first_message_unconfirmed" }, []]);
    const broken = await helper({ ...state, history: {} }, ["reconcile-launch"], "json", freezeLaunch);
    assertEquals([broken.out, broken.stderr, state.commands], [{ error_code: "conn.launch_first_message_unconfirmed" }, "", []]);
} });
