// Reads a waiting T3 run's question, or interrupts that run, or stops a thread's active run, or creates a new thread, through the stock
// T3 server's own WebSocket RPC in a task copy (docs/cli/task/copy-turn.mmd). task-copy-turn.yml runs it as agent in the copy, on stdin of
// the runtime's Node: `node - T3 read THREAD`, `node - T3 interrupt THREAD RUN REQUESTS`, `node - T3 stop THREAD` and
// `node - T3 create TITLE INSTANCE MODEL OPTIONS`, T3 being the admitted path of T3's command, REQUESTS the stored request ids joined by
// newlines and OPTIONS the thread's model options as JSON. `create` makes the thread with no message: stock T3's `thread start` takes
// no model options, and T3 0.1.7 runs a Codex thread at the effort of its `reasoningEffort` option alone. The first turn is then
// `t3 thread send`. `stop` ends the run of a turn that is over in Orbit: one its bound cut,
// or an earlier turn's. Both stops take one path: T3's `run.interrupt`, then the wait for the run's end. Stock T3's CLI can neither read
// a question nor stop a run; its server can, for a session that
// `t3 auth session issue` grants. The session lives only in this process: it is never written, printed or passed as an argument, and it
// is revoked before the exit. One JSON object goes to stdout; the phase writes it to the run folder and the result step bounds it.
import { execFileSync } from "node:child_process";
import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";

// The shortest session that outlives one read, or one interrupt or stop and its wait for the run to end.
const SessionTtl = "1m";
const PollMs = 1000;
// Everything fits the phase's 60 s bound: the issue gets 10 s, the RPC work and the wait for the run's end what is left of 42 s, and
// the revoke 5 s, so the helper prints its answer before the bound cuts it.
const Start = Date.now();
const WorkDeadline = Start + 42000;
const IssueMs = 10000;
const RevokeMs = 5000;
const left = () => Math.max(0, WorkDeadline - Date.now());
// The largest read that still fits the result step's 1 MiB bound once Ansible escapes it into the evidence file (up to six bytes a
// character); a larger one is not stored whole, so its run is not stopped.
const ReadBytes = 150000;
/** Run states in which T3 is done with a run. */
const Over = new Set(["completed", "failed", "cancelled", "interrupted", "rolled_back"]);

const [T3, command, ...rest] = process.argv.slice(2);
const [threadId, runId, stored] = command === "create" ? [] : rest;
const [title, instance, model, options] = command === "create" ? rest : [];
if (!T3 || !["read", "interrupt", "stop", "create"].includes(command) || (command !== "create" && !threadId)
    || (command === "interrupt" && (!runId || !stored)) || (command === "create" && (!title || !instance || !model || options === undefined))) {
    process.stderr.write("usage: T3 read THREAD | T3 interrupt THREAD RUN REQUESTS | T3 stop THREAD | T3 create TITLE INSTANCE MODEL OPTIONS\n");
    process.exit(64);
}

const origin = JSON.parse(readFileSync(`${process.env.HOME}/.t3/userdata/server-runtime.json`, "utf8")).origin;
const scopes = command === "read" ? ["orchestration:read"] : ["orchestration:read", "orchestration:operate"];

/** One RPC socket on a fresh ticket; T3's RPC frames are newline-separated JSON. */
async function connect() {
    const ticket = await fetch(`${origin}/api/auth/websocket-ticket`, { method: "POST", headers: { authorization: `Bearer ${issued.token}` },
        signal: AbortSignal.timeout(left()) });
    if (!ticket.ok) throw new Error(`T3 refused a WebSocket ticket (HTTP ${ticket.status}).`);
    const socket = new WebSocket(`${origin.replace(/^http/, "ws")}/ws?orchestrationProtocol=2&wsTicket=${encodeURIComponent((await ticket.json()).ticket)}`);
    const pending = new Map();
    let next = 0;
    socket.onmessage = (event) => {
        for (const line of String(event.data).split("\n").filter(Boolean)) {
            const frame = JSON.parse(line);
            if (frame._tag === "Ping") socket.send(JSON.stringify({ _tag: "Pong" }));
            if (frame._tag === "Exit" && pending.has(String(frame.requestId))) pending.get(String(frame.requestId))(frame.exit);
            // A stream's first chunk answers it; the stream then ends.
            if (frame._tag === "Chunk" && pending.has(String(frame.requestId))) {
                pending.get(String(frame.requestId))({ _tag: "Success", value: frame.values?.[0] });
                socket.send(JSON.stringify({ _tag: "Interrupt", requestId: String(frame.requestId) }));
            }
        }
    };
    const timed = (promise, what) => {
        let timer;
        return Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`T3 did not answer ${what} in time.`)), left()); })])
            .finally(() => clearTimeout(timer));
    };
    await timed(new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = () => reject(new Error("T3 refused the RPC socket.")); }), "the socket");
    const call = (tag, payload) => timed(new Promise((resolve, reject) => {
        const id = String(++next);
        pending.set(id, (exit) => {
            pending.delete(id);
            exit._tag === "Success" ? resolve(exit.value) : reject(new Error(`T3 answered ${tag} with ${JSON.stringify(exit).slice(0, 2000)}`));
        });
        socket.send(JSON.stringify({ _tag: "Request", id, tag, payload, headers: [] }));
    }), tag);
    return { call, close: () => socket.close() };
}

/** One question in the shape the result step reads: T3's own words and options, with the fields T3 may leave out made explicit. */
const question = (asked) => ({
    id: String(asked.id ?? ""),
    header: String(asked.header ?? ""),
    question: String(asked.question ?? ""),
    options: (asked.options ?? []).map((option) => ({ label: String(option.label ?? ""), description: String(option.description ?? ""),
        ...(option.value === undefined ? {} : { value: String(option.value) }) })),
    ...(asked.multiSelect === undefined ? {} : { multiSelect: asked.multiSelect === true }),
    ...(asked.allowCustomAnswer === undefined ? {} : { allowCustomAnswer: asked.allowCustomAnswer === true }),
    ...(asked.initialAnswer === undefined ? {} : { initialAnswer: String(asked.initialAnswer) }),
    ...(asked.required === undefined ? {} : { required: asked.required === true }),
});

/** Every run of the thread with its state, the runs that are not over, and every pending request with its questions. */
async function state(rpc) {
    const projection = await rpc.call("orchestration.getThreadProjection", { threadId });
    const pending = projection.runtimeRequests.filter((request) => request.status === "pending");
    const items = projection.turnItems.filter((item) => item.type === "user_input_request");
    const all = projection.runs.map((run) => ({ id: run.id, status: run.status }));
    return {
        all,
        runs: all.filter((run) => !Over.has(run.status)),
        requests: pending.map((request) => ({ request_id: request.id, kind: request.kind,
            questions: (items.find((item) => item.requestId === request.id)?.questions ?? []).map(question) })),
    };
}

/** The run may be stopped only when every pending request is a question Orbit can store whole: no approval, no empty request. */
const stoppable = (seen) => seen.requests.length > 0 && seen.requests.every((request) => request.kind === "user_input" && request.questions.length > 0);
const live = (seen, run) => seen.runs.some((open) => open.id === run);

/**
 * The one stop path of `interrupt` and `stop`: T3's `run.interrupt` for the run, then a wait for its end that takes what is left of the
 * deadline, keeping one poll's time for its last read. A refused interrupt is not waited for.
 */
async function interrupt(rpc, run, reason) {
    let error = null;
    await rpc.call("orchestration.dispatchCommand", { type: "run.interrupt", commandId: `orbit:${randomUUID()}`, threadId, runId: run, reason })
        .catch((cause) => { error = cause.message; });
    let after = await state(rpc);
    while (error === null && live(after, run) && left() > 2 * PollMs) {
        await new Promise((resolve) => setTimeout(resolve, PollMs));
        after = await state(rpc);
    }
    return { error, after };
}

/**
 * A new thread of the workspace's T3 project, with the turn's title, provider instance, model and model options, in full access as
 * `t3 thread start` makes one. No message: its first turn is the phase's `t3 thread send`.
 */
async function create(rpc) {
    const shell = await rpc.call("orchestration.subscribeShell", {});
    const project = (shell?.snapshot?.projects ?? []).find((entry) => entry.workspaceRoot === "/srv/workspace" && entry.archivedAt == null);
    if (project === undefined) return { thread_id: null, error: "T3 holds no project at /srv/workspace." };
    const created = `orbit:${randomUUID()}`;
    try {
        await rpc.call("orchestration.dispatchCommand", { type: "thread.create", commandId: `orbit:${randomUUID()}`, threadId: created,
            projectId: project.id, title, modelSelection: { instanceId: instance, model, options: JSON.parse(options) }, runtimeMode: "full-access",
            interactionMode: "default", branch: null, worktreePath: null, createdBy: "system", creationSource: "server", createdAt: new Date().toISOString() });
    } catch (cause) {
        return { thread_id: null, error: cause.message };
    }
    return { thread_id: created, error: null };
}

let raw = "";
let issued = null;
let rpc = null;
let out;
try {
    raw = execFileSync(T3, ["auth", "session", "issue", ...scopes.flatMap((scope) => ["--scope", scope]), "--ttl", SessionTtl,
        "--label", "orbit copy turn", "--json"], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"], timeout: IssueMs });
    try {
        issued = JSON.parse(raw);
    } catch {
        // The parser's message would quote the output, which holds the session.
        throw new Error("t3 auth session issue printed no JSON.");
    }
    rpc = await connect();
    if (command === "create") {
        out = await create(rpc);
    } else if (command === "read") {
        const seen = await state(rpc);
        const read = { runs: seen.runs, requests: seen.requests };
        out = { ...read, stoppable: stoppable(seen) && Buffer.byteLength(JSON.stringify(read)) < ReadBytes };
    } else if (command === "interrupt") {
        // Only the run whose questions the phase stored, while it waits for exactly those: a run that someone answered meanwhile, or that
        // now asks something else, goes on.
        const wanted = [...new Set(stored.split("\n"))].sort();
        const before = await state(rpc);
        const asking = before.requests.map((request) => request.request_id).sort();
        const { error, after } = live(before, runId) && stoppable(before) && JSON.stringify(asking) === JSON.stringify(wanted)
            ? await interrupt(rpc, runId, "Orbit ended the turn at its question; the answer comes as the next turn.")
            : { error: "The run no longer waits for the stored questions: it was answered, or it asks something else.", after: await state(rpc) };
        out = { interrupted: error === null && !live(after, runId), error, runs: after.runs, pending: after.requests.length };
    } else {
        // The run of a turn that is over in Orbit: whatever it does, it goes. Stopped only once T3 shows the thread with no active run.
        const active = (await state(rpc)).runs.at(-1);
        if (active === undefined) {
            out = { stopped: false, run: null, state: null };
        } else {
            const { error, after } = await interrupt(rpc, active.id, "Orbit stopped the run: its turn is over in Orbit, cut by its bound, cancelled or lost.");
            out = { stopped: error === null && after.runs.length === 0, run: active.id, state: after.all.find((run) => run.id === active.id)?.status ?? active.status };
        }
    }
} finally {
    rpc?.close();
    const session = issued?.sessionId ?? /"sessionId"\s*:\s*"([^"]+)"/.exec(raw)?.[1];
    if (session) {
        try {
            execFileSync(T3, ["auth", "session", "revoke", session], { stdio: "ignore", timeout: RevokeMs });
        } catch {
            // The session expires after its minute.
        }
    }
}
// Exit only once the answer is written: a pipe takes 64 KiB at a time, and an earlier exit would cut a long read.
process.stdout.write(JSON.stringify(out), () => process.exit(0));
