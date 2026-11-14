// Reads a waiting T3 run's question, or interrupts that run, through the stock T3 server's own WebSocket RPC in a task copy
// (docs/cli/task/copy-turn.mmd). task-copy-turn.yml runs it as agent in the copy, on stdin of the runtime's Node: `node - read THREAD`
// and `node - interrupt THREAD RUN`. Stock T3's CLI can neither read a question nor stop a run; its server can, for a session that
// `t3 auth session issue` grants. The session lives only in this process: it is never written, printed or passed as an argument, and it
// is revoked before the exit. One JSON object goes to stdout; the phase writes it to the run folder and the result step bounds it.
import { execFileSync } from "node:child_process";
import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";

const T3 = "/opt/orbit-agent/current/agent/bin/t3";
// The shortest session that outlives one read, or one interrupt and its wait for the run to end.
const SessionTtl = "1m";
const EndWaitMs = 30000;
const PollMs = 1000;
/** Run states in which T3 is done with a run. */
const Over = new Set(["completed", "failed", "cancelled", "interrupted", "rolled_back"]);

const [command, threadId, runId] = process.argv.slice(2);
if (!["read", "interrupt"].includes(command) || !threadId || (command === "interrupt" && !runId)) {
    process.stderr.write("usage: read THREAD | interrupt THREAD RUN\n");
    process.exit(64);
}

const origin = JSON.parse(readFileSync(`${process.env.HOME}/.t3/userdata/server-runtime.json`, "utf8")).origin;
const scopes = command === "read" ? ["orchestration:read"] : ["orchestration:read", "orchestration:operate"];
const issued = JSON.parse(execFileSync(T3, ["auth", "session", "issue", ...scopes.flatMap((scope) => ["--scope", scope]), "--ttl", SessionTtl,
    "--label", "orbit copy turn", "--json"], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"], timeout: 20000 }));

/** One RPC socket on a fresh ticket; T3's RPC frames are newline-separated JSON. */
async function connect() {
    const ticket = await fetch(`${origin}/api/auth/websocket-ticket`, { method: "POST", headers: { authorization: `Bearer ${issued.token}` } });
    if (!ticket.ok) throw new Error(`T3 refused a WebSocket ticket (HTTP ${ticket.status}).`);
    const socket = new WebSocket(`${origin.replace(/^http/, "ws")}/ws?orchestrationProtocol=2&wsTicket=${encodeURIComponent((await ticket.json()).ticket)}`);
    const pending = new Map();
    let next = 0;
    socket.onmessage = (event) => {
        for (const line of String(event.data).split("\n").filter(Boolean)) {
            const frame = JSON.parse(line);
            if (frame._tag === "Ping") socket.send(JSON.stringify({ _tag: "Pong" }));
            if (frame._tag === "Exit" && pending.has(String(frame.requestId))) pending.get(String(frame.requestId))(frame.exit);
        }
    };
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = () => reject(new Error("T3 refused the RPC socket.")); });
    const call = (tag, payload) => new Promise((resolve, reject) => {
        const id = String(++next);
        pending.set(id, (exit) => {
            pending.delete(id);
            exit._tag === "Success" ? resolve(exit.value) : reject(new Error(`T3 answered ${tag} with ${JSON.stringify(exit).slice(0, 2000)}`));
        });
        socket.send(JSON.stringify({ _tag: "Request", id, tag, payload, headers: [] }));
    });
    return { call, close: () => socket.close() };
}

/** The thread's runs that are not over and every pending request with its questions, exactly as T3 holds them. */
async function state(rpc) {
    const projection = await rpc.call("orchestration.getThreadProjection", { threadId });
    const pending = projection.runtimeRequests.filter((request) => request.status === "pending");
    const items = projection.turnItems.filter((item) => item.type === "user_input_request");
    return {
        runs: projection.runs.filter((run) => !Over.has(run.status)).map((run) => ({ id: run.id, status: run.status })),
        requests: pending.map((request) => ({ request_id: request.id, kind: request.kind,
            questions: items.find((item) => item.requestId === request.id)?.questions ?? [] })),
    };
}

let rpc = null;
let out;
try {
    rpc = await connect();
    if (command === "read") {
        out = await state(rpc);
    } else {
        // Only the run whose question the phase stored: a run that someone answered meanwhile goes on.
        const live = (seen) => seen.runs.some((run) => run.id === runId);
        const before = await state(rpc);
        let error = null;
        if (live(before) && before.requests.length > 0) {
            await rpc.call("orchestration.dispatchCommand", { type: "run.interrupt", commandId: `orbit:${randomUUID()}`, threadId, runId,
                reason: "Orbit ended the turn at its question; the answer comes as the next turn." }).catch((cause) => { error = cause.message; });
        } else {
            error = "The run was no longer waiting for this question.";
        }
        let after = await state(rpc);
        for (const deadline = Date.now() + EndWaitMs; error === null && live(after) && Date.now() < deadline;) {
            await new Promise((resolve) => setTimeout(resolve, PollMs));
            after = await state(rpc);
        }
        out = { interrupted: error === null && !live(after), error, runs: after.runs, pending: after.requests.length };
    }
} finally {
    rpc?.close();
    try {
        execFileSync(T3, ["auth", "session", "revoke", issued.sessionId], { stdio: "ignore", timeout: 20000 });
    } catch {
        // The session expires after its minute.
    }
}
process.stdout.write(JSON.stringify(out));
process.exit(0);
