import { randomBytes } from "node:crypto";
import { existsSync, realpathSync, statSync } from "node:fs";
import { sep } from "node:path";
import type { AttachedReplicatedState } from "@earendil-works/chord";
import { BACKGROUND_CONTEXT } from "@earendil-works/chord/context";
import type { AssistantMessage, ModelThinkingLevel } from "@earendil-works/pi-ai";
import {
    DefaultResourceLoader,
    type ModelRuntime,
    SettingsManager,
} from "@earendil-works/pi-coding-agent";
import {
    ConversationBusy,
    GenerationTask,
    hook,
    createRegistry,
    defineDoc,
    defineExtension,
    Harness,
    section,
    type Conversation,
    type ConversationView,
    type Cursor,
    type EntryRecord,
    type DocumentState,
    type HarnessSettings,
    type LiveState,
    type SubmissionRecord,
} from "@earendil-works/pi-durable";
import { NodeExecutionEnv } from "@earendil-works/pi-durable/env/node";
import { openNodeSqliteStorage } from "@earendil-works/pi-durable/storage/sqlite/node";
import { createEditTool, createWriteTool } from "@earendil-works/pi-durable/tools";
import { buildSystemPrompt } from "./system-prompt.ts";
import { createDurableSearchDocsTool } from "./search-docs.ts";
import { createDurableWorkspaceTools } from "./workspace-tools.ts";
import { deriveState, type DerivedState } from "./state.ts";
import { modelsWithProviderErrors } from "./provider-errors.ts";
import { isValidSessionId, type SessionConfig, type SessionRecord, SessionStore } from "./store.ts";

const context = BACKGROUND_CONTEXT;
const OutcomeDoc = defineDoc({
    kind: "orbit.outcome",
    version: 1,
    scope: "conversation",
    history: "latest",
    fork: "initial",
    initial: () => ({
        interruptedTurnId: null as string | null,
        toolResults: {} as Record<string, string>,
    }),
    checkpointWhen: () => true,
});
export const PROVIDER_RETRY_POLICY = {
    enabled: true,
    maxRetries: 4,
    baseDelayMs: 60_000,
    maxAgentDelayMs: 480_000,
};

const THINKING_LEVELS: readonly string[] = [
    "off",
    "minimal",
    "low",
    "medium",
    "high",
    "xhigh",
    "max",
];

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
    /** In-process test seam; production uses Orbit's retry budget. Not an HTTP setting. */
    harnessSettings?: HarnessSettings;
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
    /** US dollars at Pi's model prices. Null when a call with tokens has no positive cost. */
    cost: number | null;
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
    harness: Harness;
    conversation: Conversation;
    view: AttachedReplicatedState<ConversationView>;
    outcome: DocumentState<{
        interruptedTurnId: string | null;
        toolResults: Record<string, string>;
    }>;
    entries: TranscriptEntry[];
    turnId: string | null;
    submission: SubmissionRecord | undefined;
    sending: Promise<unknown>;
    admitting: number;
    sequence: number;
    /** The sequence each entry was streamed with. Entries loaded from disk have 0. */
    entrySequences: Map<string, number>;
    lastState: string;
    lastStateEvent: StateEvent | undefined;
    listeners: Set<StreamListener>;
    lastUsedAt: number;
    parked: boolean;
}

export class SessionRegistry {
    private readonly store: SessionStore;
    private readonly live = new Map<string, LiveSession>();
    private readonly closing = new Map<string, Promise<void>>();
    private readonly creating = new Map<string, Promise<unknown>>();
    private readonly loading = new Map<string, Promise<LiveSession>>();
    private readonly settings = SettingsManager.inMemory({ defaultProjectTrust: "always" });
    private readonly options: RegistryOptions;

    constructor(options: RegistryOptions) {
        this.options = options;
        this.store = new SessionStore(options.sessionDir, options.agentDir);
    }

    /** Creates a session. Repeating a create with the same settings returns the existing session. */
    create(request: CreateRequest): Promise<{ id: string; created: boolean }> {
        const creating = (this.creating.get(request.id) ?? Promise.resolve())
            .catch(() => undefined)
            .then(() => this.createOnce(request));
        this.creating.set(request.id, creating);
        void creating
            .finally(() => {
                if (this.creating.get(request.id) === creating) this.creating.delete(request.id);
            })
            .catch(() => undefined);
        return creating;
    }

    private async createOnce(request: CreateRequest): Promise<{ id: string; created: boolean }> {
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

            await this.load(request.id);
            return { id: request.id, created: false };
        }

        this.store.write(request.id, { config });
        await this.load(request.id);

        return { id: request.id, created: true };
    }

    /** Starts a turn. A key that was already accepted returns without starting another turn. */
    async send(id: string, key: string, text: string): Promise<{ duplicate: boolean }> {
        if (key === "" || text === "") {
            throw new PiServerError(422, "validation.failed", "A send needs a key and text.");
        }
        const live = await this.load(id);
        // Serialize HTTP admissions; Durable makes requestId admission and busy rejection atomic.
        live.admitting++;
        const send = live.sending.then(async () => {
            const existing = await live.harness.commit(
                (tx) => tx.submissionByRequest(live.conversation.id, key),
                context,
            );
            if (existing !== undefined) return { duplicate: true };
            try {
                await live.conversation.submit(
                    {
                        type: "input",
                        content: text,
                        requestId: key,
                        whenBusy: "reject",
                        entry: { kind: "orbit.input", data: { key } },
                    },
                    context,
                );
            } catch (error) {
                if (!(error instanceof ConversationBusy)) throw error;
                throw new PiServerError(
                    409,
                    "session.turn_active",
                    `Session ${id} is already working on a turn.`,
                );
            }
            return { duplicate: false };
        });
        live.sending = send.catch(() => undefined);
        return send.finally(() => {
            live.admitting--;
        });
    }

    async interrupt(id: string): Promise<void> {
        const live = await this.load(id);
        await live.sending;
        if (live.view.value.docs["pi.live"]?.run !== undefined) {
            await live.conversation.commit(async (tx) => {
                const outcome = await tx.doc(OutcomeDoc, live.conversation.id);
                outcome.interruptedTurnId = live.turnId;
            }, context);
            await live.conversation.abort(context);
            live.parked = false;
            this.publishState(live);
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
            usage: usage(live.entries),
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
            usage: usage(live.entries),
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
                live.view.value.docs["pi.live"]?.run === undefined &&
                live.listeners.size === 0 &&
                live.admitting === 0 &&
                now - live.lastUsedAt >= this.options.idleUnloadMs
            ) {
                // Keep load() behind close(), so two Harnesses never own one SQLite file.
                const closing = this.closeLive(live);
                // Do not reopen until a caller actually needs the session.
                this.closing.set(live.id, closing);
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
            .filter((model) => model.provider !== "anthropic" && !/claude/i.test(model.id))
            .filter(
                (model) =>
                    this.options.allowApiKeys ||
                    this.options.allowedProviders.includes(model.provider) ||
                    runtime.isUsingSubscription(model.provider),
            )
            .map((model) => `${model.provider}/${model.id}`);
    }

    async dispose(): Promise<void> {
        await Promise.all(
            [...this.loading.values()].map((pending) => pending.catch(() => undefined)),
        );
        await Promise.all([...this.live.values()].map((live) => this.closeLive(live)));
        await Promise.all(this.closing.values());
        this.live.clear();
    }

    private async closeLive(live: LiveSession): Promise<void> {
        await live.sending;
        live.view.dispose();
        live.outcome.dispose();
        await live.harness.close(context);
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
        await this.closing.get(id);
        this.closing.delete(id);
        const record = this.store.read(id);
        if (record === undefined) {
            throw new PiServerError(404, "session.not_found", `Session ${id} does not exist.`);
        }
        const { cwd, model: modelKey, thinkingLevel, appendSystemPrompt } = record.config;
        const model = this.resolveModel(modelKey);
        const resourceLoader = new DefaultResourceLoader({
            cwd,
            agentDir: this.options.agentDir,
            settingsManager: this.settings,
            appendSystemPrompt: appendSystemPrompt === null ? [] : [appendSystemPrompt],
            noExtensions: true,
        });
        await resourceLoader.reload();
        let activeConversation: Conversation | undefined;
        let recordAbortedBash: ((callId: string, text: string) => Promise<void>) | undefined;
        const tools = [
            ...createDurableWorkspaceTools({
                cwd,
                sessionId: id,
                model: modelKey,
                thinkingLevel,
                sessionFile: this.store.databasePath(id),
                onAbortedBash: (callId, text) =>
                    recordAbortedBash?.(callId, text) ?? Promise.resolve(),
            }),
            createEditTool(),
            createWriteTool(),
            createDurableSearchDocsTool({ cwd }),
        ];
        const registry = createRegistry();
        registry.install(
            defineExtension({
                name: "orbit",
                tools,
                hooks: [
                    hook(GenerationTask, {
                        async beforeRequest(request, api, hookContext) {
                            const outcome = await api.snapshot(
                                OutcomeDoc,
                                api.conversationId,
                                hookContext,
                            );
                            if (
                                activeConversation === undefined ||
                                outcome === undefined ||
                                Object.keys(outcome.toolResults).length === 0
                            )
                                return;
                            const replacements = new Map<string, string>();
                            for (const entry of (await activeConversation.context(hookContext))
                                .entries) {
                                const text = outcome.toolResults[String(entry.byTaskId)];
                                if (text === undefined) continue;
                                for (const message of entry.model ?? []) {
                                    if (message.role === "toolResult")
                                        replacements.set(
                                            JSON.stringify([message.toolCallId, message.timestamp]),
                                            text,
                                        );
                                }
                            }
                            return {
                                messages: request.messages.map((message) => {
                                    const text =
                                        message.role === "toolResult"
                                            ? replacements.get(
                                                  JSON.stringify([
                                                      message.toolCallId,
                                                      message.timestamp,
                                                  ]),
                                              )
                                            : undefined;
                                    return text === undefined
                                        ? message
                                        : {
                                              ...message,
                                              content: [{ type: "text" as const, text }],
                                          };
                                }),
                            };
                        },
                    }),
                ],
                sections: [
                    section(
                        "preamble",
                        () =>
                            buildSystemPrompt({
                                cwd,
                                customPrompt: resourceLoader.getSystemPrompt(),
                                appendSystemPrompt: resourceLoader
                                    .getAppendSystemPrompt()
                                    .join("\n\n"),
                                skills: resourceLoader.getSkills().skills,
                                contextFiles: resourceLoader.getAgentsFiles().agentsFiles,
                                selectedTools: tools.map((tool) => tool.name),
                                toolSnippets: {
                                    read: "Read file contents",
                                    bash: "Execute bash commands (ls, grep, find, etc.)",
                                    edit: "Make precise edits to files",
                                    write: "Create or overwrite files",
                                    search_docs:
                                        "Search Laravel ecosystem docs for the Project's installed package versions",
                                },
                                toolGuidelines: {
                                    read: ["Use read to examine files instead of cat or sed."],
                                    bash: [
                                        "You can inspect PI_* environment variables for current model and session details.",
                                    ],
                                    search_docs: [
                                        "Use search_docs before you write Laravel ecosystem code you are not sure about, so the code matches the installed package versions.",
                                    ],
                                },
                            }),
                        { tag: false },
                    ),
                ],
            }),
        );
        let parked = false;
        let loaded: LiveSession | undefined;
        const harness = await Harness.open(
            await openNodeSqliteStorage(this.store.databasePath(id)),
            {
                models: modelsWithProviderErrors(this.options.modelRuntime),
                registry,
                env: () => new NodeExecutionEnv({ cwd }),
                onReport: (_error, info) => {
                    if (info.type === "task_parked") {
                        parked = true;
                        if (loaded !== undefined) {
                            loaded.parked = true;
                            this.publishState(loaded);
                        }
                    }
                },
                settings: {
                    retry: PROVIDER_RETRY_POLICY,
                    stream: { maxRetries: 0 },
                    toolExecution: "sequential",
                    ...this.options.harnessSettings,
                },
            },
            context,
        );
        try {
            const conversation = await harness.root(context, {
                agent: {
                    cwd,
                    model: { provider: model.provider, modelId: model.id },
                    thinkingLevel: thinkingLevel as ModelThinkingLevel,
                },
            });
            activeConversation = conversation;
            await conversation.commit(async (tx) => {
                await tx.doc(OutcomeDoc, conversation.id);
            }, context);
            const outcome = await harness.documentState(OutcomeDoc, conversation.id, context);
            if (outcome === undefined) throw new Error("Missing Orbit outcome document.");
            const view = await conversation.viewState(context);
            const raw: EntryRecord[] = [];
            let cursor: Cursor | undefined;
            do {
                const page = await conversation.entries(
                    { order: "ascending" },
                    1000,
                    cursor,
                    context,
                );
                raw.push(...page.items);
                cursor = page.next;
            } while (cursor !== undefined);
            recordAbortedBash = async (callId, text) => {
                // Host-owned presentation evidence can commit after the invocation was cancelled.
                // Close seals admission; a crash/reopen keeps Durable's interrupted-tool evidence.
                await conversation
                    .commit(async (tx) => {
                        (await tx.doc(OutcomeDoc, conversation.id)).toolResults[callId] = text;
                    }, context)
                    .catch(() => undefined);
            };
            const entries = raw.flatMap((entry) =>
                transcriptEntry(entry, outcome.value?.toolResults),
            );
            const turnId = raw.reduce<string | null>((key, entry) => inputKey(entry) ?? key, null);
            const submission =
                turnId === null
                    ? undefined
                    : await harness.commit(
                          (tx) => tx.submissionByRequest(conversation.id, turnId),
                          context,
                      );
            const live: LiveSession = {
                id,
                run: randomBytes(8).toString("hex"),
                record,
                harness,
                conversation,
                view,
                outcome,
                entries,
                turnId,
                submission,
                sending: Promise.resolve(),
                admitting: 0,
                sequence: 0,
                entrySequences: new Map(entries.map((entry) => [entry.id, 0])),
                lastState: "",
                lastStateEvent: undefined,
                listeners: new Set(),
                lastUsedAt: Date.now(),
                parked:
                    parked ||
                    (await harness.inspect(context)).tasks.some(
                        (task) =>
                            task.state.kind === "blocked" && task.state.reason === "restart_limit",
                    ),
            };
            loaded = live;
            live.lastState = stateKey(this.derive(live), usage(entries), live.turnId);
            // Submission settlements are authoritative even when no assistant entry is appended.
            harness.subscribeCommits((publication) => {
                for (const change of publication.changes) {
                    if (
                        change.type === "submission" &&
                        change.value.type === "input" &&
                        change.value.conversationId === conversation.id &&
                        (live.submission === undefined || change.value.id >= live.submission.id)
                    ) {
                        live.submission = change.value;
                        live.turnId = change.value.requestId ?? null;
                    }
                }
                // Chord delivers the adopted view in a microtask, before this projection.
                queueMicrotask(() => {
                    const value = view.value;
                    live.lastUsedAt = Date.now();
                    for (const rawEntry of value.entries) {
                        live.turnId = inputKey(rawEntry) ?? live.turnId;
                        for (const entry of transcriptEntry(rawEntry, outcome.value?.toolResults)) {
                            if (!live.entrySequences.has(entry.id)) live.entries.push(entry);
                        }
                    }
                    if (value.docs["pi.live"]?.run !== undefined) this.publishState(live);
                    this.publishEntries(live);
                    this.publishState(live);
                });
            });
            this.live.set(id, live);
            harness.resume();
            return live;
        } catch (error) {
            await harness.close(context);
            throw error;
        }
    }

    private publishEntries(live: LiveSession): void {
        for (const entry of live.entries) {
            if (!live.entrySequences.has(entry.id)) {
                const sequence = ++live.sequence;
                live.entrySequences.set(entry.id, sequence);
                this.emit(live, { kind: "entry", run: live.run, sequence, entry });
            }
        }
    }

    private publishState(live: LiveSession): void {
        const derived = this.derive(live);
        const tokens = usage(live.entries);
        const key = stateKey(derived, tokens, live.turnId);
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
        for (const entry of live.entries) {
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
            entries: live.entries,
            usage: usage(live.entries),
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
        if (live.parked) {
            return {
                state: "failed",
                error: "Pi Durable parked the turn after its restart limit (crash loop). Operator assistance is required.",
            };
        }
        const generation = (live.view.value.docs["pi.live"] as LiveState | undefined)?.generation;
        if (generation?.retry !== undefined) {
            const last = [...live.entries]
                .reverse()
                .map((entry) => entry.message as AssistantMessage)
                .find((message) => message.role === "assistant");
            const category = deriveState({
                working: false,
                interruptedByRestart: false,
                lastAssistant: last,
            }).providerError;
            return {
                state: "working",
                error: `Waiting for provider retry ${generation.attempt}/${PROVIDER_RETRY_POLICY.maxRetries}${category ? ` (${category})` : ""} at ${new Date(generation.retry.at).toISOString()}.`,
                ...(category ? { providerError: category } : {}),
            };
        }
        if (
            live.view.value.docs["pi.live"]?.run === undefined &&
            live.turnId !== null &&
            live.outcome.value?.interruptedTurnId === live.turnId
        ) {
            return { state: "failed", error: "The turn was interrupted." };
        }
        const submission = live.submission;
        if (
            live.view.value.docs["pi.live"]?.run !== undefined ||
            submission?.status === "placed" ||
            submission?.status === "queued"
        ) {
            return { state: "working", error: null };
        }
        if (submission === undefined) return { state: "idle", error: null };
        if (submission.status === "done") {
            const answer = live.entries.find((entry) => entry.id === String(submission.answer));
            return deriveState({
                working: false,
                interruptedByRestart: false,
                lastAssistant: answer?.message as AssistantMessage | undefined,
            });
        }
        // Unanswered submissions include compaction/model/task failures with no assistant answer.
        // Only current-turn assistant evidence can supply the sanitized provider category.
        const last = live.entries
            .filter((entry) => Number(entry.id) > Number(submission.entry))
            .map((entry) => entry.message as AssistantMessage)
            .findLast((message) => message.role === "assistant");
        return deriveState({
            working: false,
            interruptedByRestart: false,
            lastAssistant: {
                ...(last?.errorMessage === submission.detail ? last : {}),
                stopReason: submission.reason === "aborted" ? "aborted" : "error",
                errorMessage:
                    typeof submission.detail === "string"
                        ? submission.detail
                        : `The turn failed: ${submission.reason}.`,
            },
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
        if (/claude/i.test(key) || key.startsWith("anthropic/")) {
            throw new PiServerError(
                422,
                "model.unavailable",
                "Claude is unavailable for task agents.",
            );
        }
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

function inputKey(entry: EntryRecord): string | undefined {
    if (
        entry.kind !== "orbit.input" ||
        typeof entry.data !== "object" ||
        entry.data === null ||
        Array.isArray(entry.data)
    )
        return undefined;
    return typeof entry.data.key === "string" ? entry.data.key : undefined;
}

function transcriptEntry(
    entry: EntryRecord,
    toolResults?: Record<string, string>,
): TranscriptEntry[] {
    if (!["orbit.input", "pi.user", "pi.assistant", "pi.tool-result"].includes(entry.kind))
        return [];
    return (entry.model ?? [])
        .filter((message) => message.role !== "system")
        .map((message, index) => ({
            id: String(entry.id) + (index === 0 ? "" : `:${index}`),
            timestamp: new Date(message.timestamp ?? 0).toISOString(),
            message:
                message.role === "toolResult" && toolResults?.[String(entry.byTaskId)] !== undefined
                    ? {
                          ...message,
                          content: [{ type: "text", text: toolResults[String(entry.byTaskId)] }],
                          isError: true,
                      }
                    : message,
        }));
}

function toolCallIds(message: unknown): string[] {
    const content = (message as { content?: unknown }).content;

    return (Array.isArray(content) ? content : []).flatMap((block: unknown) => {
        const call = block as { type?: unknown; id?: unknown };
        return call.type === "toolCall" && typeof call.id === "string" ? [call.id] : [];
    });
}

function latestTurn(live: LiveSession): string | null {
    return live.turnId;
}

/**
 * Cumulative usage over assistant messages with numeric `input`, `output`,
 * `cacheRead`, and `cacheWrite`. A message missing one of those is not a call,
 * and neither is a tool result, compaction, or other usage entry.
 */
function usage(entries: TranscriptEntry[]): Usage {
    const totals: Usage = {
        input: 0,
        output: 0,
        cacheRead: 0,
        cacheWrite: 0,
        total: 0,
        calls: 0,
        peakContext: 0,
        cost: 0,
    };
    for (const entry of entries) {
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
        // Pi prices a model it does not know at zero, so a zero cost on a real call is unknown.
        const priced = call.cost !== undefined && (call.cost > 0 || callTokens(call) === 0);
        totals.cost = totals.cost === null || !priced ? null : totals.cost + (call.cost ?? 0);
    }
    totals.total = totals.input + totals.output + totals.cacheRead + totals.cacheWrite;

    return totals;
}

interface CountedCall {
    input: number;
    output: number;
    cacheRead: number;
    cacheWrite: number;
    /** `cost.total` in US dollars, when the message carries a finite one. */
    cost: number | undefined;
}

function callTokens(call: CountedCall): number {
    return call.input + call.output + call.cacheRead + call.cacheWrite;
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

    const cost = (usage.cost as { total?: unknown } | null | undefined)?.total;

    return {
        input,
        output,
        cacheRead,
        cacheWrite,
        cost: typeof cost === "number" && Number.isFinite(cost) ? cost : undefined,
    };
}

function isTokenCount(value: unknown): value is number {
    return typeof value === "number" && Number.isFinite(value);
}

function stateKey(derived: DerivedState, tokens: Usage, turnId: string | null): string {
    return JSON.stringify([derived.state, derived.error, derived.providerError, tokens, turnId]);
}

function sameConfig(a: SessionConfig, b: SessionConfig): boolean {
    return (
        a.cwd === b.cwd &&
        a.model === b.model &&
        a.thinkingLevel === b.thinkingLevel &&
        a.appendSystemPrompt === b.appendSystemPrompt
    );
}
