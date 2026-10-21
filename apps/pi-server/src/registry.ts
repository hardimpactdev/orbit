import { randomBytes } from "node:crypto";
import { existsSync, realpathSync, statSync } from "node:fs";
import { sep } from "node:path";
import type { ThinkingLevel } from "@earendil-works/pi-agent-core";
import {
    type AgentSession,
    createAgentSession,
    DefaultResourceLoader,
    type ModelRuntime,
    type SessionEntry,
    SessionManager,
    SettingsManager,
} from "@earendil-works/pi-coding-agent";
import { createSearchDocsTool } from "./search-docs.ts";
import { createWorkspaceTools } from "./workspace-tools.ts";
import { deriveState, type DerivedState } from "./state.ts";
import { withProviderErrors } from "./provider-errors.ts";
import { isValidSessionId, type SessionConfig, type SessionRecord, SessionStore } from "./store.ts";

const THINKING_LEVELS: readonly string[] = [
    "off",
    "minimal",
    "low",
    "medium",
    "high",
    "xhigh",
    "max",
];
const STREAMED_EVENTS = new Set(["message_end", "turn_end", "agent_end", "agent_settled"]);

/** A request the server refuses. `status` and the dotted `code` become the HTTP error response. */
export class PiServerError extends Error {
    readonly status: number;
    readonly code: string;

    constructor(status: number, code: string, message: string) {
        super(message);
        this.status = status;
        this.code = code;
    }
}

export interface RegistryOptions {
    modelRuntime: ModelRuntime;
    agentDir: string;
    sessionDir: string;
    /** When not empty, a session's working directory must be inside one of these directories. */
    workspaceRoots: string[];
    /** Allow providers authenticated with an API key. Off by default: only subscription logins run. */
    allowApiKeys: boolean;
    /**
     * Providers allowed although Pi sees an API key, such as a CLIProxyAPI endpoint in
     * `models.json` that serves pooled subscription accounts.
     */
    allowedProviders: string[];
    /** Unload a session with no turn and no stream after this many milliseconds. */
    idleUnloadMs: number;
}

export interface CreateRequest {
    id: string;
    cwd: string;
    model: string;
    thinkingLevel: string;
    appendSystemPrompt?: string | null;
}

export interface TranscriptEntry {
    id: string;
    timestamp: string;
    message: unknown;
}

export interface Usage {
    /** Prompt tokens that are neither a cache read nor a cache write. */
    input: number;
    /** Output tokens. A reasoning count on the call is already included. */
    output: number;
    /** Input read from cache. */
    cacheRead: number;
    /** Input written to cache. This is uncached input, not a cache read. */
    cacheWrite: number;
    /** `input + output + cacheRead + cacheWrite`. */
    total: number;
    /** Assistant messages included in the sums. */
    calls: number;
    /** Largest single-call context: `input + cacheRead + cacheWrite`. Output is excluded. */
    peakContext: number;
}

export interface Snapshot {
    kind: "snapshot";
    /** Identifies this load of the session. A cursor from another run cannot resume. */
    run: string;
    sequence: number;
    session: { id: string; cwd: string; model: string; thinkingLevel: string };
    state: DerivedState["state"];
    error: string | null;
    providerError?: DerivedState["providerError"];
    /** The key of the latest accepted send. Each send starts one turn, so it identifies that turn. */
    turnId: string | null;
    entries: TranscriptEntry[];
    usage: Usage;
}

export interface EntryEvent {
    kind: "entry";
    run: string;
    sequence: number;
    entry: TranscriptEntry;
}

export interface StateEvent {
    kind: "state";
    run: string;
    sequence: number;
    state: DerivedState["state"];
    error: string | null;
    providerError?: DerivedState["providerError"];
    turnId: string | null;
    usage: Usage;
}

/**
 * Starts a stream that continues from a cursor instead of a snapshot. `context` holds the
 * assistant entries the client already has whose tool calls have no result yet, so the client
 * can name the results that follow.
 */
export interface Resumed {
    kind: "resumed";
    run: string;
    sequence: number;
    session: { id: string };
    context: TranscriptEntry[];
}

export type StreamEvent = Snapshot | Resumed | EntryEvent | StateEvent;

/**
 * Answers `GET /sessions/{id}` with a cursor from the current run: the session's current state
 * and the events a stream resumed from the same cursor would send after its `resumed` event.
 */
export interface Delta {
    kind: "delta";
    run: string;
    /** The newest sequence of this run: the cursor for the next read. */
    sequence: number;
    session: { id: string };
    state: DerivedState["state"];
    error: string | null;
    providerError?: DerivedState["providerError"];
    turnId: string | null;
    usage: Usage;
    /** As in `Resumed`: entries at or before the cursor whose tool calls have no result yet. */
    context: TranscriptEntry[];
    /**
     * Exactly the events a stream resumed from the same cursor replays after its `resumed`
     * event, in sequence order.
     */
    events: (EntryEvent | StateEvent)[];
}

/** The last event a client received: the run and the sequence number within it. */
export interface StreamCursor {
    run: string;
    sequence: number;
}

export type StreamListener = (event: StreamEvent) => void;

/** What a client missed after a cursor. A resumed stream and a delta read both send it. */
interface Replay {
    /** The cursor's sequence. */
    after: number;
    context: TranscriptEntry[];
    missed: (EntryEvent | StateEvent)[];
}

interface LiveSession {
    id: string;
    /** A new random value each time the session loads. Sequence numbers restart with it. */
    run: string;
    record: SessionRecord;
    session: AgentSession;
    working: boolean;
    interruptedByRestart: boolean;
    sequence: number;
    /** The sequence each entry was streamed with. Entries loaded from disk have 0. */
    entrySequences: Map<string, number>;
    lastState: string;
    lastStateEvent: StateEvent | undefined;
    listeners: Set<StreamListener>;
    lastUsedAt: number;
}

export class SessionRegistry {
    private readonly store: SessionStore;
    private readonly live = new Map<string, LiveSession>();
    private readonly loading = new Map<string, Promise<LiveSession>>();
    private readonly settings = SettingsManager.inMemory({ defaultProjectTrust: "always" });
    private readonly options: RegistryOptions;

    constructor(options: RegistryOptions) {
        this.options = options;
        this.store = new SessionStore(options.sessionDir);
    }

    /** Creates a session. Repeating a create with the same settings returns the existing session. */
    async create(request: CreateRequest): Promise<{ id: string; created: boolean }> {
        const config = await this.validateCreate(request);
        const existing = this.store.read(request.id);
        if (existing !== undefined) {
            if (!sameConfig(existing.config, config)) {
                throw new PiServerError(
                    409,
                    "session.exists",
                    `Session ${request.id} exists with different settings.`,
                );
            }

            return { id: request.id, created: false };
        }

        this.store.write(request.id, { config, acceptedKeys: [], turnActive: false });
        await this.load(request.id);

        return { id: request.id, created: true };
    }

    /** Starts a turn. A key that was already accepted returns without starting another turn. */
    async send(id: string, key: string, text: string): Promise<{ duplicate: boolean }> {
        if (key === "" || text === "") {
            throw new PiServerError(422, "validation.failed", "A send needs a key and text.");
        }
        const live = await this.load(id);
        if (live.record.acceptedKeys.includes(key)) {
            return { duplicate: true };
        }
        if (live.working) {
            throw new PiServerError(
                409,
                "session.turn_active",
                `Session ${id} is already working on a turn.`,
            );
        }

        live.record = {
            ...live.record,
            acceptedKeys: [...live.record.acceptedKeys, key],
            turnActive: true,
        };
        this.store.write(id, live.record);
        live.working = true;
        live.interruptedByRestart = false;
        this.publishState(live);

        void live.session
            .prompt(text)
            .catch(() => undefined)
            .finally(() => {
                live.working = false;
                live.record = { ...live.record, turnActive: false };
                this.store.write(id, live.record);
                live.lastUsedAt = Date.now();
                this.publishEntries(live);
                this.publishState(live);
            });

        return { duplicate: false };
    }

    async interrupt(id: string): Promise<void> {
        const live = await this.load(id);
        if (live.working) {
            await live.session.abort();
        }
    }

    /**
     * Reads a session. With a cursor from the current run that is not ahead of it, returns a
     * delta: the current state and the events after the cursor, as a resumed stream sends them.
     * Otherwise returns a full snapshot.
     */
    async read(id: string, after?: StreamCursor, maxBytes?: number): Promise<Snapshot | Delta> {
        const live = await this.load(id);
        const replay = this.replay(live, after);
        if (replay === undefined) {
            return this.buildSnapshot(live, maxBytes);
        }

        const delta: Delta = {
            kind: "delta",
            run: live.run,
            sequence: live.sequence,
            session: { id: live.id },
            ...this.derive(live),
            turnId: latestTurn(live),
            usage: usage(live.session),
            context: replay.context,
            events: replay.missed,
        };
        return maxBytes !== undefined && Buffer.byteLength(JSON.stringify(delta)) > maxBytes
            ? this.buildSnapshot(live, maxBytes)
            : delta;
    }

    /** Reads current state and usage without building a transcript or replay. */
    async observe(id: string): Promise<StateEvent & { session: { id: string } }> {
        const live = await this.load(id);
        return {
            kind: "state",
            run: live.run,
            sequence: live.sequence,
            session: { id: live.id },
            ...this.derive(live),
            turnId: latestTurn(live),
            usage: usage(live.session),
        };
    }

    /**
     * Subscribes to a session. With a cursor from the current run, the listener first receives a
     * `resumed` event and the events after the cursor. Otherwise it receives a full snapshot.
     * Then it receives changes.
     */
    async subscribe(
        id: string,
        listener: StreamListener,
        after?: StreamCursor,
        maxBytes?: number,
    ): Promise<() => void> {
        const live = await this.load(id);
        live.listeners.add(listener);
        const replay = this.replay(live, after);
        const start: StreamEvent[] =
            replay === undefined
                ? [this.buildSnapshot(live, maxBytes)]
                : [
                      {
                          kind: "resumed",
                          run: live.run,
                          sequence: replay.after,
                          session: { id: live.id },
                          context: replay.context,
                      },
                      ...replay.missed,
                  ];
        if (
            maxBytes !== undefined &&
            start.some((event) => Buffer.byteLength(JSON.stringify(event)) > maxBytes)
        ) {
            listener(this.buildSnapshot(live, maxBytes));
        } else {
            start.forEach(listener);
        }

        return () => {
            live.listeners.delete(listener);
            live.lastUsedAt = Date.now();
        };
    }

    /** Unloads sessions that have no turn and no stream and have not been used recently. */
    unloadIdle(now = Date.now()): void {
        for (const live of this.live.values()) {
            if (
                !live.working &&
                live.listeners.size === 0 &&
                now - live.lastUsedAt >= this.options.idleUnloadMs
            ) {
                live.session.dispose();
                this.live.delete(live.id);
            }
        }
    }

    /**
     * Lists the models a session can use, as `provider/model`. Unless API keys are allowed, a
     * provider counts only when it is signed in with a subscription or named in
     * `allowedProviders`, so a stray API key in the environment never enables per-token billing.
     */
    async availableModels(): Promise<string[]> {
        const runtime = this.options.modelRuntime;

        return (await runtime.getAvailable())
            .filter(
                (model) =>
                    this.options.allowApiKeys ||
                    this.options.allowedProviders.includes(model.provider) ||
                    runtime.isUsingSubscription(model.provider),
            )
            .map((model) => `${model.provider}/${model.id}`);
    }

    dispose(): void {
        for (const live of this.live.values()) {
            live.session.dispose();
        }
        this.live.clear();
    }

    private async load(id: string): Promise<LiveSession> {
        if (!isValidSessionId(id)) {
            throw new PiServerError(404, "session.not_found", `Session ${id} does not exist.`);
        }
        const live = this.live.get(id);
        if (live !== undefined) {
            live.lastUsedAt = Date.now();
            return live;
        }
        const pending =
            this.loading.get(id) ?? this.open(id).finally(() => this.loading.delete(id));
        this.loading.set(id, pending);

        return pending;
    }

    private async open(id: string): Promise<LiveSession> {
        const record = this.store.read(id);
        if (record === undefined) {
            throw new PiServerError(404, "session.not_found", `Session ${id} does not exist.`);
        }
        const { cwd, model: modelKey, thinkingLevel, appendSystemPrompt } = record.config;
        const model = this.resolveModel(modelKey);
        const file = this.store.sessionFile(id);
        const sessionManager =
            file === undefined
                ? SessionManager.create(cwd, this.options.sessionDir, { id })
                : SessionManager.open(file, this.options.sessionDir);
        const resourceLoader = new DefaultResourceLoader({
            cwd,
            agentDir: this.options.agentDir,
            settingsManager: this.settings,
            appendSystemPrompt: appendSystemPrompt === null ? [] : [appendSystemPrompt],
        });
        await resourceLoader.reload();

        const { session } = await createAgentSession({
            cwd,
            agentDir: this.options.agentDir,
            modelRuntime: this.options.modelRuntime,
            model,
            thinkingLevel: thinkingLevel as ThinkingLevel,
            settingsManager: this.settings,
            resourceLoader,
            sessionManager,
            // read and bash replace Pi's tools so a result over 8 KiB is stored
            // under the workspace .git before it is appended to the session.
            customTools: [
                createSearchDocsTool({ cwd }),
                ...createWorkspaceTools({ cwd, sessionId: id }),
            ],
        });

        session.agent.streamFunction = withProviderErrors(session.agent.streamFunction);

        const live: LiveSession = {
            id,
            run: randomBytes(8).toString("hex"),
            record,
            session,
            working: false,
            interruptedByRestart: record.turnActive,
            sequence: 0,
            entrySequences: new Map(transcript(session).map((entry) => [entry.id, 0])),
            lastState: "",
            lastStateEvent: undefined,
            listeners: new Set(),
            lastUsedAt: Date.now(),
        };
        live.lastState = stateKey(this.derive(live), usage(session));
        session.subscribe((event) => {
            if (STREAMED_EVENTS.has(event.type)) {
                // Pi persists the finished message in its own listener; read entries after it runs.
                setImmediate(() => {
                    this.publishEntries(live);
                    this.publishState(live);
                });
            }
        });
        this.live.set(id, live);

        return live;
    }

    private publishEntries(live: LiveSession): void {
        for (const entry of transcript(live.session)) {
            if (!live.entrySequences.has(entry.id)) {
                const sequence = ++live.sequence;
                live.entrySequences.set(entry.id, sequence);
                this.emit(live, { kind: "entry", run: live.run, sequence, entry });
            }
        }
    }

    private publishState(live: LiveSession): void {
        const derived = this.derive(live);
        const tokens = usage(live.session);
        const key = stateKey(derived, tokens);
        if (key === live.lastState) {
            return;
        }
        live.lastState = key;
        live.lastStateEvent = {
            kind: "state",
            run: live.run,
            sequence: ++live.sequence,
            ...derived,
            turnId: latestTurn(live),
            usage: tokens,
        };
        this.emit(live, live.lastStateEvent);
    }

    /**
     * Rebuilds what a client missed after a cursor: the entries streamed after it and the latest
     * state if it changed after it, in sequence order, and the open tool calls at the cursor.
     * Returns undefined when there is no cursor, or it belongs to another run or is ahead of this
     * run, so the caller sends a snapshot instead.
     */
    private replay(live: LiveSession, after: StreamCursor | undefined): Replay | undefined {
        if (after === undefined || after.run !== live.run || after.sequence > live.sequence) {
            return undefined;
        }
        const missed: (EntryEvent | StateEvent)[] = [];
        const openCalls = new Map<string, TranscriptEntry>();
        for (const entry of transcript(live.session)) {
            const sequence = live.entrySequences.get(entry.id);
            if (sequence === undefined) {
                // Not streamed yet. It reaches this listener as a live entry event.
                continue;
            }
            if (sequence > after.sequence) {
                missed.push({ kind: "entry", run: live.run, sequence, entry });
                continue;
            }
            const message = entry.message as { role?: unknown; toolCallId?: unknown };
            if (message.role === "assistant") {
                toolCallIds(entry.message).forEach((callId) => openCalls.set(callId, entry));
            } else if (message.role === "toolResult" && typeof message.toolCallId === "string") {
                openCalls.delete(message.toolCallId);
            }
        }
        const state = live.lastStateEvent;
        if (state !== undefined && state.sequence > after.sequence) {
            missed.push(state);
        }
        missed.sort((a, b) => a.sequence - b.sequence);

        return { after: after.sequence, context: [...new Set(openCalls.values())], missed };
    }

    private emit(live: LiveSession, event: StreamEvent): void {
        for (const listener of live.listeners) {
            listener(event);
        }
    }

    private buildSnapshot(live: LiveSession, maxBytes?: number): Snapshot {
        const { cwd, model, thinkingLevel } = live.record.config;

        const snapshot: Snapshot = {
            kind: "snapshot",
            run: live.run,
            sequence: live.sequence,
            session: { id: live.id, cwd, model, thinkingLevel },
            ...this.derive(live),
            turnId: latestTurn(live),
            entries: transcript(live.session),
            usage: usage(live.session),
        };
        if (maxBytes === undefined) {
            return snapshot;
        }

        const marker: TranscriptEntry = {
            id: "orbit-transcript-truncated",
            timestamp: "",
            message: {
                role: "system",
                content:
                    "[Transcript truncated: older entries omitted to fit the Gateway response budget.]",
            },
        };
        const entries: TranscriptEntry[] = [];
        let bytes = Buffer.byteLength(JSON.stringify({ ...snapshot, entries }));
        for (let index = snapshot.entries.length - 1; index >= 0; index--) {
            const entry = snapshot.entries[index]!;
            const size = Buffer.byteLength(JSON.stringify(entry)) + (entries.length === 0 ? 0 : 1);
            if (bytes + size > maxBytes) {
                break;
            }
            bytes += size;
            entries.push(entry);
        }
        if (entries.length < snapshot.entries.length) {
            const markerSize = Buffer.byteLength(JSON.stringify(marker));
            while (entries.length > 0 && bytes + markerSize + 1 > maxBytes) {
                bytes -=
                    Buffer.byteLength(JSON.stringify(entries.pop())) +
                    (entries.length === 0 ? 0 : 1);
            }
            entries.push(marker);
        }
        entries.reverse();
        return { ...snapshot, entries };
    }

    private derive(live: LiveSession): DerivedState {
        const last = [...live.session.messages]
            .reverse()
            .find((message) => message.role === "assistant");

        return deriveState({
            working: live.working,
            interruptedByRestart: live.interruptedByRestart,
            lastAssistant: last === undefined || last.role !== "assistant" ? undefined : last,
        });
    }

    private async validateCreate(request: CreateRequest): Promise<SessionConfig> {
        if (!isValidSessionId(request.id)) {
            throw new PiServerError(
                422,
                "validation.failed",
                "The session ID must be 1 to 128 letters, digits, hyphens, or underscores.",
            );
        }
        if (!THINKING_LEVELS.includes(request.thinkingLevel)) {
            throw new PiServerError(
                422,
                "validation.failed",
                `Unknown thinking level ${request.thinkingLevel}.`,
            );
        }
        const cwd = this.resolveWorkspace(request.cwd);
        const model = this.resolveModel(request.model);
        const available = await this.availableModels();
        if (!available.includes(`${model.provider}/${model.id}`)) {
            throw new PiServerError(
                422,
                "model.unavailable",
                this.options.allowApiKeys
                    ? `Provider ${model.provider} is not signed in on this Node, or it does not offer ${model.id}.`
                    : `Provider ${model.provider} is not signed in with a subscription or allowed with --allow-provider on this Node, or it does not offer ${model.id}.`,
            );
        }

        return {
            cwd,
            model: request.model,
            thinkingLevel: request.thinkingLevel,
            appendSystemPrompt: request.appendSystemPrompt ?? null,
        };
    }

    private resolveWorkspace(cwd: string): string {
        if (!cwd.startsWith("/") || !existsSync(cwd) || !statSync(cwd).isDirectory()) {
            throw new PiServerError(
                422,
                "validation.failed",
                `The workspace ${cwd} is not an existing absolute directory.`,
            );
        }
        const real = realpathSync(cwd);
        const roots = this.options.workspaceRoots.map((root) => realpathSync(root));
        if (
            roots.length > 0 &&
            !roots.some((root) => real === root || real.startsWith(root + sep))
        ) {
            throw new PiServerError(
                422,
                "validation.failed",
                `The workspace ${cwd} is outside the allowed roots.`,
            );
        }

        return real;
    }

    private resolveModel(key: string): NonNullable<ReturnType<ModelRuntime["getModel"]>> {
        const slash = key.indexOf("/");
        const model =
            slash > 0
                ? this.options.modelRuntime.getModel(key.slice(0, slash), key.slice(slash + 1))
                : undefined;
        if (model === undefined) {
            throw new PiServerError(
                422,
                "model.unavailable",
                `Unknown model ${key}. Use provider/model.`,
            );
        }

        return model;
    }
}

function transcript(session: AgentSession): TranscriptEntry[] {
    return session.sessionManager
        .getEntries()
        .filter(
            (entry): entry is Extract<SessionEntry, { type: "message" }> =>
                entry.type === "message",
        )
        .filter((entry) => entry.message.role !== "system")
        .map((entry) => ({ id: entry.id, timestamp: entry.timestamp, message: entry.message }));
}

function toolCallIds(message: unknown): string[] {
    const content = (message as { content?: unknown }).content;

    return (Array.isArray(content) ? content : []).flatMap((block: unknown) => {
        const call = block as { type?: unknown; id?: unknown };
        return call.type === "toolCall" && typeof call.id === "string" ? [call.id] : [];
    });
}

function latestTurn(live: LiveSession): string | null {
    return live.record.acceptedKeys.at(-1) ?? null;
}

/**
 * Cumulative usage over assistant messages with numeric `input`, `output`,
 * `cacheRead`, and `cacheWrite`. A message missing one of those is not a call,
 * and neither is a tool result, compaction, or other usage entry.
 */
function usage(session: AgentSession): Usage {
    const totals: Usage = {
        input: 0,
        output: 0,
        cacheRead: 0,
        cacheWrite: 0,
        total: 0,
        calls: 0,
        peakContext: 0,
    };
    for (const entry of session.sessionManager.getEntries()) {
        if (entry.type !== "message") {
            continue;
        }
        const call = countedCall(entry.message);
        if (call === undefined) {
            continue;
        }
        totals.input += call.input;
        totals.output += call.output;
        totals.cacheRead += call.cacheRead;
        totals.cacheWrite += call.cacheWrite;
        totals.peakContext = Math.max(
            totals.peakContext,
            call.input + call.cacheRead + call.cacheWrite,
        );
        totals.calls += 1;
    }
    totals.total = totals.input + totals.output + totals.cacheRead + totals.cacheWrite;

    return totals;
}

interface CountedCall {
    input: number;
    output: number;
    cacheRead: number;
    cacheWrite: number;
}

/** A model call reports all four token counts. Anything else is not a call. */
function countedCall(message: unknown): CountedCall | undefined {
    if (typeof message !== "object" || message === null) {
        return undefined;
    }
    const record = message as { role?: unknown; usage?: unknown };
    if (record.role !== "assistant" || typeof record.usage !== "object" || record.usage === null) {
        return undefined;
    }
    const usage = record.usage as Record<string, unknown>;
    const input = usage.input;
    const output = usage.output;
    const cacheRead = usage.cacheRead;
    const cacheWrite = usage.cacheWrite;
    if (
        !isTokenCount(input) ||
        !isTokenCount(output) ||
        !isTokenCount(cacheRead) ||
        !isTokenCount(cacheWrite)
    ) {
        return undefined;
    }

    return { input, output, cacheRead, cacheWrite };
}

function isTokenCount(value: unknown): value is number {
    return typeof value === "number" && Number.isFinite(value);
}

function stateKey(derived: DerivedState, tokens: Usage): string {
    return JSON.stringify([derived.state, derived.error, tokens]);
}

function sameConfig(a: SessionConfig, b: SessionConfig): boolean {
    return (
        a.cwd === b.cwd &&
        a.model === b.model &&
        a.thinkingLevel === b.thinkingLevel &&
        a.appendSystemPrompt === b.appendSystemPrompt
    );
}
