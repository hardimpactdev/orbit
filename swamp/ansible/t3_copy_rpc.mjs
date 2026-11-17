// Reads a waiting T3 run's question, resolves its stored answered requests, interrupts that run, stops a thread's active run, or creates a new thread, through the stock
// T3 server's own WebSocket RPC in a task copy (docs/cli/task/copy-turn.mmd). task-copy-turn.yml runs it as agent in the copy, on stdin of
// the runtime's Node: `node - T3 read THREAD`, `node - T3 interrupt THREAD RUN REQUESTS`, `node - T3 stop THREAD` and
// `node - T3 answered THREAD REQUEST...` and `node - T3 create TITLE INSTANCE MODEL OPTIONS`, T3 being the admitted path of T3's command.
// Interrupt's REQUESTS are stored ids joined by newlines; answered takes one argument per id. OPTIONS is the thread's model options
// as JSON. `create` makes the thread with no message: stock T3's `thread start` takes
// no model options, and T3 0.1.7 runs a Codex thread at the effort of its `reasoningEffort` option alone. The first turn is then
// `t3 thread send`. `stop` ends the run of a turn that is over in Orbit: one its bound cut,
// or an earlier turn's. Both stops take one path: T3's `run.interrupt`, then the wait for the run's end. Stock T3's CLI can neither read
// a question nor stop a run; its server can, for a session that
// `t3 auth session issue` grants. The session lives only in this process: it is never written, printed or passed as an argument, and it
// is revoked before the exit. Conn uses the same transport for `inspect-launch ENVIRONMENT [PROVIDER]`, `launch` and `reconcile-launch`.
// For dispatch and recovery, run the script with `node --input-type=module -e SCRIPT -- orbit-rpc T3 COMMAND` and pass the frozen
// private JSON on stdin. Only bounded public evidence goes to stdout. Recovery reads the original server and never sends a message.
import { execFileSync } from "node:child_process";
import { createHash, randomUUID } from "node:crypto";
import { readFileSync, statSync } from "node:fs";

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
const launchCommand = ["inspect-launch", "launch", "reconcile-launch"].includes(command);
const [threadId, runId, stored] = command === "create" || launchCommand ? [] : rest;
const answeredRequests = command === "answered" ? rest.slice(1) : [];
const [title, instance, model, options] = command === "create" ? rest : [];
if (!T3 || (!launchCommand && (!["read", "interrupt", "answered", "stop", "create"].includes(command) || (command !== "create" && !threadId)))
    || (command === "inspect-launch" && (rest.length < 1 || rest.length > 2)) || (["launch", "reconcile-launch"].includes(command) && rest.length !== 0)
    || (command === "interrupt" && (!runId || !stored)) || (command === "answered" && (answeredRequests.length === 0 || answeredRequests.some((id) => !id)))
    || (command === "create" && (!title || !instance || !model || options === undefined))) {
    process.stderr.write("usage: T3 read THREAD | T3 interrupt THREAD RUN REQUESTS | T3 answered THREAD REQUEST... | T3 stop THREAD | T3 create TITLE INSTANCE MODEL OPTIONS | T3 inspect-launch ENVIRONMENT [PROVIDER] | T3 launch | T3 reconcile-launch\n");
    process.exit(64);
}

const userdata = `${process.env.HOME}/.t3/userdata`;
let origin;
const readOnly = ["read", "inspect-launch", "reconcile-launch"].includes(command);
const scopes = readOnly ? ["orchestration:read"] : ["orchestration:read", "orchestration:operate"];

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
    socket.onclose = () => {
        for (const finish of pending.values()) finish({ _tag: "Failure", message: "T3 closed the RPC socket." });
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
        owners: new Map(pending.map((request) => [request.id, projection.nodes.find((node) => node.id === request.nodeId)?.runId])),
        runs: all.filter((run) => !Over.has(run.status)),
        requests: pending.map((request) => ({ request_id: request.id, kind: request.kind,
            questions: (items.find((item) => item.requestId === request.id)?.questions ?? []).map(question) })),
    };
}

/** The run may be stopped only when every pending request is a question Orbit can store whole: no approval, no empty request. */
const stoppable = (seen) => seen.requests.length > 0 && seen.requests.every((request) => request.kind === "user_input" && request.questions.length > 0);
const live = (seen, run) => seen.runs.some((open) => open.id === run);

/** Resolve only the answered turn's stored questions, without sending a message. A concurrent answer needs no second response. */
async function answered(rpc) {
    const wanted = new Set(answeredRequests);
    const before = await state(rpc);
    for (const request of before.requests.filter((request) => wanted.has(request.request_id) && request.kind === "user_input")) {
        await rpc.call("orchestration.dispatchCommand", { type: "runtime-request.respond", commandId: `orbit:${randomUUID()}`,
            threadId, requestId: request.request_id, decision: "cancel" }).catch(() => {
                // It may have been answered meanwhile. The readback decides whether the continued turn may launch.
            });
    }
    const pending = (await state(rpc)).requests.filter((request) => wanted.has(request.request_id)).map((request) => request.request_id);
    return { resolved: pending.length === 0, pending,
        error: pending.length === 0 ? null : "T3 still holds a stored answered request pending; no turn may be launched." };
}

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
    const project = await workspaceProject(rpc);
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

async function workspaceProject(rpc) {
    const shell = await rpc.call("orchestration.subscribeShell", {});
    return (shell?.snapshot?.projects ?? []).find((entry) => entry.workspaceRoot === "/srv/workspace" && entry.archivedAt == null);
}

async function launchIdentity(environmentId) {
    const response = await fetch(`${origin}/.well-known/t3/environment`, { redirect: "error", signal: AbortSignal.timeout(left()) });
    if (!response.ok) return null;
    const descriptor = await response.json();
    const localEnvironmentId = readFileSync(`${userdata}/environment-id`, "utf8").trim();
    if (!environmentId || descriptor.environmentId !== environmentId || localEnvironmentId !== environmentId || descriptor.serverVersion !== "0.1.7") return null;
    // The state file's identity survives a normal process restart and detects a replaced file even if its environment ID was copied.
    // Read filesystem metadata only; message and receipt evidence always comes through T3's RPC.
    const { dev, ino } = statSync(`${userdata}/statev2.sqlite`, { bigint: true });
    return createHash("sha256").update(`${environmentId}\0${dev}\0${ino}`).digest("hex");
}

// These are the pinned T3 driver defaults used by Conn when a selectable provider has an empty catalog.
const StockDefaultModels = new Map(Object.entries({
    codex: "gpt-6-astra", claudeAgent: "claude-opus-5-5", cursor: "auto", grok: "grok-build",
    fx: "default", kiro: "default", muse: "default", acpRegistry: "default", pi: "default",
    opencode: "openai/gpt-5", antigravity: "antigravity-default",
}));

function launchModel(config, projectId, requestedProvider) {
    const { settings, providers } = config;
    const override = settings.projectSettingsOverrides?.[projectId]?.providerInstances;
    const providerSettings = settings.providerInstances;
    const enabled = (provider) => {
        const id = provider.instanceId;
        const configured = providerSettings?.[id];
        if (override?.[id]?.enabled !== undefined) return override[id].enabled;
        if (configured?.enabled === false || configured?.config?.enabled === false) return false;
        return configured?.enabled ?? configured?.config?.enabled ?? provider.enabled;
    };
    if (providers.some((provider) => provider.instanceId === requestedProvider && enabled(provider))) {
        return { requested_provider_available: true, model_selection: null };
    }

    let selection = settings.defaultModelSelection;
    if (selection == null) {
        const groups = new Map();
        for (const provider of providers) {
            if (!groups.has(provider.driver)) groups.set(provider.driver, []);
            if (provider.instanceId === provider.driver) groups.get(provider.driver).unshift(provider);
            else groups.get(provider.driver).push(provider);
        }
        const selectable = (group) => {
            const available = group.filter((provider) => enabled(provider) && provider.availability !== "unavailable");
            return available.find((provider) => provider.status === "ready") ?? available.find((provider) => provider.status !== "error");
        };
        const provider = selectable(groups.values().next().value ?? []) ?? selectable([...groups.values()].flat());
        if (provider !== undefined) {
            const model = provider.models.find((item) => item.isDefault && !item.isCustom)?.slug
                ?? provider.models.find((item) => !item.isCustom)?.slug
                ?? provider.models[0]?.slug ?? StockDefaultModels.get(provider.driver);
            selection = { instanceId: provider.instanceId, model };
        }
    }
    if (typeof selection?.model !== "string" || typeof selection.instanceId !== "string") return null;
    const model = { instanceId: selection.instanceId, model: selection.model,
        ...(selection.options == null ? {} : { options: selection.options }) };
    if (Buffer.byteLength(JSON.stringify(model)) > 16384) return null;
    return { requested_provider_available: false, model_selection: model };
}

async function inspectLaunch(rpc, environmentId, requestedProvider) {
    const incarnation = await launchIdentity(environmentId);
    if (incarnation === null) return { error_code: "conn.launch_server_changed" };
    const project = await workspaceProject(rpc);
    if (typeof project?.id !== "string") return { error_code: "conn.launch_workspace_failed" };
    const model = launchModel(await rpc.call("server.getConfig", {}), project.id, requestedProvider);
    if (await launchIdentity(environmentId) !== incarnation) return { error_code: "conn.launch_server_changed" };
    if (model === null) return { error_code: "conn.launch_workspace_failed" };
    return { environment_id: environmentId, incarnation, project_id: project.id, ...model, error_code: null };
}

const exactThread = (projection, request) => projection?.thread?.id === request.threadId
    && projection.thread.projectId === request.projectId && projection.thread.deletedAt == null;

// Undefined means the message is outside this window. Null means evidence is missing or contradictory.
function initialAcceptance(projection, request) {
    if (!exactThread(projection, request) || !Array.isArray(projection.messages)) return null;
    const expected = request.initialMessage;
    const matches = projection.messages.filter((message) => message.id === expected.messageId);
    if (matches.length === 0) return undefined;
    if (matches.length !== 1 || matches[0].threadId !== request.threadId || matches[0].role !== "user"
        || matches[0].text !== expected.text) return null;
    return acceptanceTime(matches[0].createdAt);
}

function acceptanceTime(value) {
    const at = typeof value === "string" ? Date.parse(value) : NaN;
    return Number.isFinite(at) ? new Date(at).toISOString() : null;
}

async function readLaunchHistory(request) {
    const path = "/api/orchestration/threads/" + encodeURIComponent(request.threadId);
    const read = async (url) => {
        const response = await fetch(origin + url, {
            headers: { authorization: "Bearer " + issued.token, "x-t3-orchestration-protocol": "2" },
            redirect: "error", signal: AbortSignal.timeout(left()),
        });
        return response.ok ? response.json() : null;
    };
    const bounded = await read(path + "/bounded");
    const recent = initialAcceptance(bounded?.projection, request);
    if (recent !== undefined) return recent;

    const seen = new Set();
    let cursor = bounded.hasMoreHistory ? bounded.historyCursor : null;
    while (typeof cursor === "string" && cursor.length > 0 && !seen.has(cursor) && left() > 0) {
        seen.add(cursor);
        const page = await read(path + "/history?view=conversation&cursor=" + encodeURIComponent(cursor));
        if (!Array.isArray(page?.items)) return null;
        const matches = page.items.filter((row) => row.item?.messageId === request.initialMessage.messageId);
        if (matches.length !== 0) {
            const row = matches[0];
            const item = row.item;
            return matches.length === 1 && row.visibility === "local" && row.sourceThreadId === request.threadId
                && item.threadId === request.threadId && item.type === "user_message" && item.createdBy === "user"
                && item.text === request.initialMessage.text ? acceptanceTime(item.startedAt) : null;
        }
        cursor = page.hasMoreHistory ? page.nextCursor : null;
    }
    return null;
}

async function launchMessage(rpc, send) {
    const frozen = JSON.parse(readFileSync(0, "utf8"));
    const request = frozen?.request;
    if (typeof frozen?.incarnation !== "string" || typeof request?.commandId !== "string" || typeof request.threadId !== "string"
        || typeof request.projectId !== "string" || typeof request.initialMessage?.messageId !== "string"
        || typeof request.initialMessage.text !== "string" || !Array.isArray(request.initialMessage.attachments)
        || request.initialMessage.attachments.length !== 0 || request.reuseExistingThread !== undefined) {
        return { error_code: "conn.launch_invalid_dispatch" };
    }
    if (await launchIdentity(frozen.environment_id) !== frozen.incarnation) return { error_code: "conn.launch_server_changed" };
    const projection = send
        ? (await rpc.call("orchestration.launchThread", request))?.projection
        : await rpc.call("orchestration.getThreadProjection", { threadId: request.threadId });
    let acceptedAt = initialAcceptance(projection, request);
    if (!send && acceptedAt === undefined) acceptedAt = await readLaunchHistory(request);
    if (await launchIdentity(frozen.environment_id) !== frozen.incarnation) return { error_code: "conn.launch_server_changed" };
    return acceptedAt == null
        ? { error_code: "conn.launch_first_message_unconfirmed" }
        : { accepted_at: acceptedAt, error_code: null };
}

let raw = "";
let issued = null;
let rpc = null;
let out;
try {
    origin = JSON.parse(readFileSync(userdata + "/server-runtime.json", "utf8")).origin;
    if (launchCommand) {
        const local = new URL(origin);
        if (local.origin !== origin || local.protocol !== "http:" || !["127.0.0.1", "[::1]"].includes(local.hostname)) {
            throw new Error("Launch requires a local T3 server.");
        }
    }
    raw = execFileSync(T3, ["auth", "session", "issue", ...scopes.flatMap((scope) => ["--scope", scope]), "--ttl", SessionTtl,
        "--label", "orbit copy turn", "--json"], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"], timeout: IssueMs });
    try {
        issued = JSON.parse(raw);
    } catch {
        // The parser's message would quote the output, which holds the session.
        throw new Error("t3 auth session issue printed no JSON.");
    }
    rpc = await connect();
    if (command === "inspect-launch") {
        out = await inspectLaunch(rpc, rest[0], rest[1]);
    } else if (launchCommand) {
        out = await launchMessage(rpc, command === "launch");
    } else if (command === "create") {
        out = await create(rpc);
    } else if (command === "answered") {
        out = await answered(rpc);
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
        const { error, after } = live(before, runId) && stoppable(before) && before.requests.every((request) => before.owners.get(request.request_id) === runId)
            && JSON.stringify(asking) === JSON.stringify(wanted)
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
} catch (cause) {
    if (!launchCommand) throw cause;
    // A failed launch response can contain submitted text. Keep diagnostics to the bounded receipt.
    out = { error_code: command === "inspect-launch" ? "conn.launch_workspace_failed" : "conn.launch_first_message_unconfirmed" };
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
