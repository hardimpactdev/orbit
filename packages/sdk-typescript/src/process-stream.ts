/**
 * Durable native EventSource subscriber for GET /processes/stream?app=…
 *
 * Browser toolbars subscribe directly to the gateway. Native EventSource cannot
 * set custom headers (including X-Orbit-Client); the gateway never requires them.
 * createOrbitGatewayClient remains for process list/lifecycle commands.
 */

/** Durable process_events.event values persisted by the gateway. */
export type ProcessLifecycleEventType =
    | 'starting'
    | 'started'
    | 'stopping'
    | 'stopped'
    | 'restarting'
    | 'crashed'
    | 'failed';

/** Normalized process status derived from the latest durable lifecycle event. */
export type ProcessRuntimeStatus =
    | 'starting'
    | 'running'
    | 'stopping'
    | 'stopped'
    | 'restarting'
    | 'crashed'
    | 'unknown';

export interface ProcessStreamLastEvent {
    id: number;
    type: ProcessLifecycleEventType;
}

export interface ProcessStreamContext {
    node: string;
    project: string | null;
    instance: string | null;
    workspace: string | null;
}

export interface ProcessStreamProcess {
    node: string;
    project: string | null;
    instance: string | null;
    workspace: string | null;
    name: string;
    command: string | null;
    restart_policy: string;
    crash_notification: string;
    runtime: string;
    tool: string | null;
    service: Record<string, unknown> | null;
    runtime_unit: string;
    status: ProcessRuntimeStatus;
    last_event: ProcessStreamLastEvent | null;
}

export interface ProcessStreamSnapshot {
    app: string;
    context: ProcessStreamContext;
    processes: ProcessStreamProcess[];
    cursor: {
        high_water_mark: number;
    };
}

export interface ProcessStreamUpdate {
    id: number;
    event: ProcessLifecycleEventType;
    status: ProcessRuntimeStatus;
    name: string | null;
    node: string | null;
    project: string | null;
    instance: string | null;
    workspace: string | null;
    unit_name: string | null;
    occurred_at: string | null;
    exit_code: number | null;
    exit_status: string | null;
}

export interface ProcessStreamError {
    code: string;
    message: string;
    meta: Record<string, unknown>;
}

export interface SubscribeProcessStreamOptions {
    baseUrl: string;
    /** Strict app hostname only (URL-encoded by the subscriber). */
    app: string;
    /**
     * Optional EventSource implementation (tests). Defaults to global EventSource.
     * Native EventSource cannot attach custom headers; do not require X-Orbit-Client.
     */
    EventSourceImpl?: typeof EventSource;
    onSnapshot?: (snapshot: ProcessStreamSnapshot, event: MessageEvent) => void;
    onUpdate?: (update: ProcessStreamUpdate, event: MessageEvent) => void;
    /**
     * Server `event: error` frames, protocol/parse failures, and transport
     * errors (Event). Protocol failures use code `process.stream_protocol_error`.
     */
    onError?: (error: ProcessStreamError | Event, event?: MessageEvent) => void;
    /**
     * Fired exactly once when the connection re-opens (onopen) after a prior
     * transport error / disconnect. Not fired on the initial open.
     */
    onReconnect?: () => void;
}

export interface ProcessStreamSubscription {
    close: () => void;
    /** Underlying EventSource when available. */
    eventSource: EventSource;
}

const LIFECYCLE_EVENTS = new Set<string>([
    'starting',
    'started',
    'stopping',
    'stopped',
    'restarting',
    'crashed',
    'failed',
]);

const RUNTIME_STATUSES = new Set<string>([
    'starting',
    'running',
    'stopping',
    'stopped',
    'restarting',
    'crashed',
    'unknown',
]);

export function buildProcessStreamUrl(baseUrl: string, app: string): string {
    const normalizedBase = baseUrl.replace(/\/+$/, '');
    const url = new URL('processes/stream', ensureTrailingSlash(normalizedBase));
    url.searchParams.set('app', app);

    return url.toString();
}

export function subscribeProcessStream(options: SubscribeProcessStreamOptions): ProcessStreamSubscription {
    const EventSourceImpl = options.EventSourceImpl ?? globalThis.EventSource;

    if (typeof EventSourceImpl !== 'function') {
        throw new Error('EventSource is not available in this environment.');
    }

    const streamUrl = buildProcessStreamUrl(options.baseUrl, options.app);
    const source = new EventSourceImpl(streamUrl);
    /** True after a transport-level error until a successful onopen. */
    let disconnected = false;

    source.addEventListener('snapshot', (event: Event) => {
        if (!(event instanceof MessageEvent)) {
            return;
        }

        const parsed = parseJson(event.data);

        if (parsed === null) {
            emitProtocolError(options, 'Invalid snapshot JSON payload.', event);

            return;
        }

        const snapshot = asSnapshot(parsed);

        if (snapshot === null) {
            emitProtocolError(options, 'Snapshot payload failed schema validation.', event);

            return;
        }

        options.onSnapshot?.(snapshot, event);
    });

    source.addEventListener('update', (event: Event) => {
        if (!(event instanceof MessageEvent)) {
            return;
        }

        const parsed = parseJson(event.data);

        if (parsed === null) {
            emitProtocolError(options, 'Invalid update JSON payload.', event);

            return;
        }

        const update = asUpdate(parsed);

        if (update === null) {
            emitProtocolError(options, 'Update payload failed schema validation.', event);

            return;
        }

        options.onUpdate?.(update, event);
    });

    source.addEventListener('error', (event: Event) => {
        // Server-sent `event: error` frames arrive as MessageEvent with JSON data.
        if (event instanceof MessageEvent && typeof event.data === 'string' && event.data !== '') {
            const parsed = parseJson(event.data);

            if (parsed === null) {
                emitProtocolError(options, 'Invalid error JSON payload.', event);

                return;
            }

            const streamError = asStreamError(parsed);

            if (streamError === null) {
                emitProtocolError(options, 'Error payload failed schema validation.', event);

                return;
            }

            options.onError?.(streamError, event);

            return;
        }

        // Transport-level EventSource error (network, reconnect attempt, close).
        disconnected = true;
        options.onError?.(event);
    });

    source.onopen = () => {
        if (disconnected) {
            disconnected = false;
            options.onReconnect?.();
        }
    };

    return {
        eventSource: source,
        close: () => {
            source.close();
        },
    };
}

function ensureTrailingSlash(baseUrl: string): string {
    return baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
}

function emitProtocolError(
    options: SubscribeProcessStreamOptions,
    message: string,
    event: MessageEvent,
): void {
    options.onError?.(
        {
            code: 'process.stream_protocol_error',
            message,
            meta: {},
        },
        event,
    );
}

function parseJson(raw: unknown): unknown {
    if (typeof raw !== 'string' || raw.trim() === '') {
        return null;
    }

    try {
        return JSON.parse(raw) as unknown;
    } catch {
        return null;
    }
}

function asSnapshot(value: unknown): ProcessStreamSnapshot | null {
    if (! isRecord(value)) {
        return null;
    }

    if (typeof value.app !== 'string') {
        return null;
    }

    const context = asContext(value.context);

    if (context === null) {
        return null;
    }

    if (! Array.isArray(value.processes)) {
        return null;
    }

    const processes: ProcessStreamProcess[] = [];

    for (const item of value.processes) {
        const process = asProcess(item);

        if (process === null) {
            return null;
        }

        processes.push(process);
    }

    if (! isRecord(value.cursor) || typeof value.cursor.high_water_mark !== 'number') {
        return null;
    }

    return {
        app: value.app,
        context,
        processes,
        cursor: {
            high_water_mark: value.cursor.high_water_mark,
        },
    };
}

function asContext(value: unknown): ProcessStreamContext | null {
    if (! isRecord(value) || typeof value.node !== 'string') {
        return null;
    }

    const project = asNullableString(value.project);
    const instance = asNullableString(value.instance);
    const workspace = asNullableString(value.workspace);

    if (project === false || instance === false || workspace === false) {
        return null;
    }

    return {
        node: value.node,
        project,
        instance,
        workspace,
    };
}

function asProcess(value: unknown): ProcessStreamProcess | null {
    if (! isRecord(value)) {
        return null;
    }

    if (typeof value.node !== 'string' || typeof value.name !== 'string' || typeof value.runtime_unit !== 'string') {
        return null;
    }

    if (! isRuntimeStatus(value.status)) {
        return null;
    }

    if (typeof value.restart_policy !== 'string' || typeof value.crash_notification !== 'string' || typeof value.runtime !== 'string') {
        return null;
    }

    const project = asNullableString(value.project);
    const instance = asNullableString(value.instance);
    const workspace = asNullableString(value.workspace);
    const command = asNullableString(value.command);
    const tool = asNullableString(value.tool);

    if (project === false || instance === false || workspace === false || command === false || tool === false) {
        return null;
    }

    if (! ('last_event' in value)) {
        return null;
    }

    const lastEvent = value.last_event === null
        ? null
        : asLastEvent(value.last_event);

    if (value.last_event !== null && lastEvent === null) {
        return null;
    }

    if (! ('service' in value)) {
        return null;
    }

    const service = value.service === null
        ? null
        : isRecord(value.service)
            ? value.service
            : null;

    if (value.service !== null && service === null) {
        return null;
    }

    return {
        node: value.node,
        project,
        instance,
        workspace,
        name: value.name,
        command,
        restart_policy: value.restart_policy,
        crash_notification: value.crash_notification,
        runtime: value.runtime,
        tool,
        service,
        runtime_unit: value.runtime_unit,
        status: value.status,
        last_event: lastEvent,
    };
}

function asLastEvent(value: unknown): ProcessStreamLastEvent | null {
    if (! isRecord(value) || typeof value.id !== 'number' || ! isLifecycleEvent(value.type)) {
        return null;
    }

    return {
        id: value.id,
        type: value.type,
    };
}

function asUpdate(value: unknown): ProcessStreamUpdate | null {
    if (! isRecord(value)) {
        return null;
    }

    if (typeof value.id !== 'number' || ! isLifecycleEvent(value.event) || ! isRuntimeStatus(value.status)) {
        return null;
    }

    const name = asNullableString(value.name);
    const node = asNullableString(value.node);
    const project = asNullableString(value.project);
    const instance = asNullableString(value.instance);
    const workspace = asNullableString(value.workspace);
    const unitName = asNullableString(value.unit_name);
    const occurredAt = asNullableString(value.occurred_at);
    const exitStatus = asNullableString(value.exit_status);
    const exitCode = asNullableNumber(value.exit_code);

    if (
        name === false
        || node === false
        || project === false
        || instance === false
        || workspace === false
        || unitName === false
        || occurredAt === false
        || exitStatus === false
        || exitCode === false
    ) {
        return null;
    }

    return {
        id: value.id,
        event: value.event,
        status: value.status,
        name,
        node,
        project,
        instance,
        workspace,
        unit_name: unitName,
        occurred_at: occurredAt,
        exit_code: exitCode,
        exit_status: exitStatus,
    };
}

function asStreamError(value: unknown): ProcessStreamError | null {
    if (! isRecord(value) || typeof value.code !== 'string' || typeof value.message !== 'string') {
        return null;
    }

    const meta = value.meta === undefined || value.meta === null
        ? {}
        : isRecord(value.meta)
            ? value.meta
            : null;

    if (meta === null) {
        return null;
    }

    return {
        code: value.code,
        message: value.message,
        meta,
    };
}

function isLifecycleEvent(value: unknown): value is ProcessLifecycleEventType {
    return typeof value === 'string' && LIFECYCLE_EVENTS.has(value);
}

function isRuntimeStatus(value: unknown): value is ProcessRuntimeStatus {
    return typeof value === 'string' && RUNTIME_STATUSES.has(value);
}

/**
 * @returns string | null for valid values; false when the JSON type is wrong.
 */
function asNullableString(value: unknown): string | null | false {
    if (value === null || value === undefined) {
        return null;
    }

    return typeof value === 'string' ? value : false;
}

/**
 * @returns number | null for valid values; false when the JSON type is wrong.
 */
function asNullableNumber(value: unknown): number | null | false {
    if (value === null || value === undefined) {
        return null;
    }

    return typeof value === 'number' ? value : false;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && ! Array.isArray(value);
}
