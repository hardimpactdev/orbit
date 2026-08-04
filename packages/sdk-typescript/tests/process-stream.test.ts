import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    buildProcessStreamUrl,
    subscribeProcessStream,
    type ProcessStreamError,
    type ProcessStreamSnapshot,
    type ProcessStreamUpdate,
} from '../src/process-stream.ts';

type Listener = (event: Event) => void;

class FakeEventSource {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSED = 2;

    readonly url: string;
    readyState = FakeEventSource.CONNECTING;
    onopen: ((this: FakeEventSource, ev: Event) => unknown) | null = null;
    private readonly listeners = new Map<string, Set<Listener>>();

    constructor(url: string) {
        this.url = url;
        FakeEventSource.instances.push(this);
    }

    static instances: FakeEventSource[] = [];

    static reset(): void {
        FakeEventSource.instances = [];
    }

    addEventListener(type: string, listener: Listener): void {
        const set = this.listeners.get(type) ?? new Set<Listener>();
        set.add(listener);
        this.listeners.set(type, set);
    }

    close(): void {
        this.readyState = FakeEventSource.CLOSED;
    }

    emit(type: string, data?: string): void {
        const event = new MessageEvent(type, { data });
        for (const listener of this.listeners.get(type) ?? []) {
            listener(event);
        }
    }

    emitTransportError(): void {
        const event = new Event('error');
        for (const listener of this.listeners.get('error') ?? []) {
            listener(event);
        }
    }

    open(): void {
        this.readyState = FakeEventSource.OPEN;
        this.onopen?.call(this, new Event('open'));
    }
}

describe('buildProcessStreamUrl', () => {
    it('URL-encodes the app hostname and uses exact /api/processes/stream', () => {
        const url = buildProcessStreamUrl('https://gateway.orbit', 'app.example.test');

        assert.equal(url, 'https://gateway.orbit/api/processes/stream?app=app.example.test');
    });

    it('encodes special characters in app', () => {
        const url = buildProcessStreamUrl('https://gateway.orbit/', 'my app.example');

        assert.ok(url.includes('app=my+app.example') || url.includes('app=my%20app.example'));
        assert.ok(url.startsWith('https://gateway.orbit/api/processes/stream?'));
    });
});

describe('subscribeProcessStream', () => {
    it('throws when EventSource is missing', () => {
        const previous = globalThis.EventSource;
        // @ts-expect-error intentional delete for test
        delete globalThis.EventSource;

        try {
            assert.throws(
                () =>
                    subscribeProcessStream({
                        baseUrl: 'https://gateway.orbit',
                        app: 'app.example',
                    }),
                /EventSource is not available/,
            );
        } finally {
            if (previous !== undefined) {
                globalThis.EventSource = previous;
            }
        }
    });

    it('parses snapshot and update frames with durable unions', () => {
        FakeEventSource.reset();
        const snapshots: ProcessStreamSnapshot[] = [];
        const updates: ProcessStreamUpdate[] = [];

        const sub = subscribeProcessStream({
            baseUrl: 'https://gateway.orbit',
            app: 'test.app.example',
            EventSourceImpl: FakeEventSource as unknown as typeof EventSource,
            onSnapshot: (snapshot) => {
                snapshots.push(snapshot);
            },
            onUpdate: (update) => {
                updates.push(update);
            },
        });

        const source = FakeEventSource.instances[0];
        assert.ok(source);
        assert.equal(
            source.url,
            'https://gateway.orbit/api/processes/stream?app=test.app.example',
        );

        source.emit(
            'snapshot',
            JSON.stringify({
                app: 'test.app.example',
                context: {
                    node: 'app-1',
                    project: 'docs',
                    instance: 'development',
                    workspace: null,
                },
                processes: [
                    {
                        node: 'app-1',
                        project: 'docs',
                        instance: 'development',
                        workspace: null,
                        key: 'vite',
                        label: 'Vite Dev Server',
                        name: 'vite',
                        command: 'npm run dev',
                        restart_policy: 'never',
                        crash_notification: 'none',
                        runtime: 'systemd',
                        tool: null,
                        service: null,
                        runtime_unit: 'orbit_docs_development_main_vite',
                        status: 'running',
                        last_event: { id: 3, type: 'started' },
                    },
                ],
                cursor: { high_water_mark: 3 },
            }),
        );

        source.emit(
            'update',
            JSON.stringify({
                id: 4,
                event: 'stopping',
                status: 'stopping',
                key: 'vite',
                name: 'vite',
                label: 'Vite Dev Server',
                node: 'app-1',
                project: 'docs',
                instance: 'development',
                workspace: null,
                unit_name: 'orbit_docs_development_main_vite',
                occurred_at: '2026-08-04T12:00:00+00:00',
                exit_code: null,
                exit_status: null,
            }),
        );

        assert.equal(snapshots.length, 1);
        assert.equal(snapshots[0]?.cursor.high_water_mark, 3);
        assert.equal(snapshots[0]?.processes[0]?.status, 'running');
        assert.equal(snapshots[0]?.processes[0]?.key, 'vite');
        assert.equal(snapshots[0]?.processes[0]?.label, 'Vite Dev Server');
        assert.equal(snapshots[0]?.processes[0]?.name, 'vite');
        assert.equal(snapshots[0]?.processes[0]?.last_event?.type, 'started');
        assert.equal(updates.length, 1);
        assert.equal(updates[0]?.event, 'stopping');
        assert.equal(updates[0]?.status, 'stopping');
        assert.equal(updates[0]?.key, 'vite');
        assert.equal(updates[0]?.label, 'Vite Dev Server');
        assert.equal(updates[0]?.name, 'vite');

        sub.close();
        assert.equal(source.readyState, FakeEventSource.CLOSED);
    });

    it('surfaces invalid snapshot JSON as a typed protocol error', () => {
        FakeEventSource.reset();
        const errors: Array<ProcessStreamError | Event> = [];

        subscribeProcessStream({
            baseUrl: 'https://gateway.orbit',
            app: 'test.app.example',
            EventSourceImpl: FakeEventSource as unknown as typeof EventSource,
            onError: (error) => {
                errors.push(error);
            },
        });

        FakeEventSource.instances[0]?.emit('snapshot', '{not-json');

        assert.equal(errors.length, 1);
        const error = errors[0] as ProcessStreamError;
        assert.equal(error.code, 'process.stream_protocol_error');
        assert.match(error.message, /Invalid snapshot JSON/);
    });

    it('surfaces invalid update schema as a typed protocol error', () => {
        FakeEventSource.reset();
        const errors: Array<ProcessStreamError | Event> = [];

        subscribeProcessStream({
            baseUrl: 'https://gateway.orbit',
            app: 'test.app.example',
            EventSourceImpl: FakeEventSource as unknown as typeof EventSource,
            onError: (error) => {
                errors.push(error);
            },
        });

        FakeEventSource.instances[0]?.emit(
            'update',
            JSON.stringify({ id: 1, event: 'booting', status: 'running', name: 'vite' }),
        );

        assert.equal(errors.length, 1);
        const error = errors[0] as ProcessStreamError;
        assert.equal(error.code, 'process.stream_protocol_error');
        assert.match(error.message, /Update payload failed schema/);
    });

    it('rejects non-safe-integer cursor and event ids as protocol errors', () => {
        const cases: Array<{ type: 'snapshot' | 'update'; payload: unknown }> = [
            {
                type: 'snapshot',
                payload: {
                    app: 'test.app.example',
                    context: { node: 'app-1', project: null, instance: null, workspace: null },
                    processes: [],
                    cursor: { high_water_mark: 1.5 },
                },
            },
            {
                type: 'snapshot',
                payload: {
                    app: 'test.app.example',
                    context: { node: 'app-1', project: null, instance: null, workspace: null },
                    processes: [],
                    cursor: { high_water_mark: -1 },
                },
            },
            {
                type: 'snapshot',
                payload: {
                    app: 'test.app.example',
                    context: { node: 'app-1', project: null, instance: null, workspace: null },
                    processes: [],
                    cursor: { high_water_mark: Number.NaN },
                },
            },
            {
                type: 'snapshot',
                payload: {
                    app: 'test.app.example',
                    context: { node: 'app-1', project: null, instance: null, workspace: null },
                    processes: [],
                    cursor: { high_water_mark: Number.POSITIVE_INFINITY },
                },
            },
            {
                type: 'snapshot',
                payload: {
                    app: 'test.app.example',
                    context: { node: 'app-1', project: null, instance: null, workspace: null },
                    processes: [],
                    cursor: { high_water_mark: Number.MAX_SAFE_INTEGER + 1 },
                },
            },
            {
                type: 'snapshot',
                payload: {
                    app: 'test.app.example',
                    context: { node: 'app-1', project: null, instance: null, workspace: null },
                    processes: [
                        {
                            node: 'app-1',
                            project: null,
                            instance: null,
                            workspace: null,
                            name: 'vite',
                            command: null,
                            restart_policy: 'never',
                            crash_notification: 'none',
                            runtime: 'systemd',
                            tool: null,
                            service: null,
                            runtime_unit: 'orbit_docs_vite',
                            status: 'running',
                            last_event: { id: -3, type: 'started' },
                        },
                    ],
                    cursor: { high_water_mark: 0 },
                },
            },
            {
                type: 'update',
                payload: {
                    id: 2.5,
                    event: 'started',
                    status: 'running',
                    name: 'vite',
                    node: null,
                    project: null,
                    instance: null,
                    workspace: null,
                    unit_name: null,
                    occurred_at: null,
                    exit_code: null,
                    exit_status: null,
                },
            },
            {
                type: 'update',
                payload: {
                    id: -1,
                    event: 'started',
                    status: 'running',
                    name: 'vite',
                    node: null,
                    project: null,
                    instance: null,
                    workspace: null,
                    unit_name: null,
                    occurred_at: null,
                    exit_code: null,
                    exit_status: null,
                },
            },
            {
                type: 'update',
                payload: {
                    id: Number.MAX_SAFE_INTEGER + 1,
                    event: 'started',
                    status: 'running',
                    name: 'vite',
                    node: null,
                    project: null,
                    instance: null,
                    workspace: null,
                    unit_name: null,
                    occurred_at: null,
                    exit_code: null,
                    exit_status: null,
                },
            },
        ];

        for (const testCase of cases) {
            FakeEventSource.reset();
            const errors: Array<ProcessStreamError | Event> = [];
            const snapshots: ProcessStreamSnapshot[] = [];
            const updates: ProcessStreamUpdate[] = [];

            subscribeProcessStream({
                baseUrl: 'https://gateway.orbit',
                app: 'test.app.example',
                EventSourceImpl: FakeEventSource as unknown as typeof EventSource,
                onSnapshot: (snapshot) => {
                    snapshots.push(snapshot);
                },
                onUpdate: (update) => {
                    updates.push(update);
                },
                onError: (error) => {
                    errors.push(error);
                },
            });

            FakeEventSource.instances[0]?.emit(testCase.type, JSON.stringify(testCase.payload));

            assert.equal(errors.length, 1, `expected protocol error for ${JSON.stringify(testCase.payload)}`);
            assert.equal(snapshots.length, 0);
            assert.equal(updates.length, 0);
            const error = errors[0] as ProcessStreamError;
            assert.equal(error.code, 'process.stream_protocol_error');
            assert.match(error.message, /schema validation/);
        }
    });

    it('delivers server error events as ProcessStreamError', () => {
        FakeEventSource.reset();
        const errors: Array<ProcessStreamError | Event> = [];

        subscribeProcessStream({
            baseUrl: 'https://gateway.orbit',
            app: 'test.app.example',
            EventSourceImpl: FakeEventSource as unknown as typeof EventSource,
            onError: (error) => {
                errors.push(error);
            },
        });

        FakeEventSource.instances[0]?.emit(
            'error',
            JSON.stringify({
                code: 'process.event_stream_failed',
                message: 'stream crashed',
                meta: {},
            }),
        );

        assert.equal(errors.length, 1);
        const error = errors[0] as ProcessStreamError;
        assert.equal(error.code, 'process.event_stream_failed');
        assert.equal(error.message, 'stream crashed');
    });

    it('forwards transport errors and fires onReconnect once on subsequent open', () => {
        FakeEventSource.reset();
        const transportErrors: Event[] = [];
        let reconnects = 0;

        subscribeProcessStream({
            baseUrl: 'https://gateway.orbit',
            app: 'test.app.example',
            EventSourceImpl: FakeEventSource as unknown as typeof EventSource,
            onError: (error) => {
                if (error instanceof Event) {
                    transportErrors.push(error);
                }
            },
            onReconnect: () => {
                reconnects += 1;
            },
        });

        const source = FakeEventSource.instances[0];
        assert.ok(source);

        // Initial open must not count as reconnect.
        source.open();
        assert.equal(reconnects, 0);

        source.emitTransportError();
        assert.equal(transportErrors.length, 1);
        assert.equal(reconnects, 0);

        source.open();
        assert.equal(reconnects, 1);

        // A second open without an intervening error must not re-fire.
        source.open();
        assert.equal(reconnects, 1);

        source.emitTransportError();
        source.open();
        assert.equal(reconnects, 2);
    });

    it('close stops further use by marking the source closed', () => {
        FakeEventSource.reset();

        const sub = subscribeProcessStream({
            baseUrl: 'https://gateway.orbit',
            app: 'test.app.example',
            EventSourceImpl: FakeEventSource as unknown as typeof EventSource,
        });

        sub.close();
        assert.equal(FakeEventSource.instances[0]?.readyState, FakeEventSource.CLOSED);
    });
});
