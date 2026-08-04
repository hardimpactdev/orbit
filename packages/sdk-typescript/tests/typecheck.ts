import {
    createOrbitGatewayClient,
    subscribeProcessStream,
    type OrbitGatewayClient,
    type ProcessLifecycleEventType,
    type ProcessRuntimeStatus,
    type ProcessStreamLastEvent,
    type ProcessStreamSnapshot,
    type ProcessStreamUpdate,
} from '../src/index.js';
import type { paths } from '../src/generated/schema.js';

type ProcessListQuery = NonNullable<
    paths['/processes']['get']['parameters'] extends { query?: infer Q } ? Q : never
>;
type ProcessRestartBody = NonNullable<
    paths['/processes/restart']['post'] extends {
        requestBody?: { content: { 'application/json': infer B } };
    }
        ? B
        : never
>;
type ProcessListSuccess = NonNullable<
    paths['/processes']['get']['responses'][200]['content']['application/json']
>;
type ProcessStartSuccess = NonNullable<
    paths['/processes/start']['post']['responses'][200]['content']['application/json']
>;
type ProcessRestartSuccess = NonNullable<
    paths['/processes/restart']['post']['responses'][200]['content']['application/json']
>;
type ProcessStartRuntime = ProcessStartSuccess extends {
    success?: { data?: { runtimes?: Array<infer Item> } };
}
    ? Item
    : never;
type ProcessRestartRuntime = ProcessRestartSuccess extends {
    success?: { data?: { runtimes?: Array<infer Item> } };
}
    ? Item
    : never;

// Same auth model as CLI: no bearer, no peer-IP identity header.
// Gateway maps actual WireGuard peer source IP; optional X-Orbit-Client is non-identity.
const client: OrbitGatewayClient = createOrbitGatewayClient({
    baseUrl: 'https://gateway.orbit.test',
    headers: {
        'X-Orbit-Client': 'laravel-toolbar',
    },
    fetch: globalThis.fetch,
});

await client.GET('/nodes', {
    params: {
        query: {
            doctor: 'true',
            environment: 'production',
            role: 'app',
        },
    },
});

await client.GET('/projects', {});

// App hostname selector for toolbar process list.
const appQuery = {
    app: 'test.app.example',
} satisfies ProcessListQuery;

await client.GET('/processes', {
    params: {
        query: appQuery,
    },
});

await client.GET('/processes', {
    params: {
        query: {
            instance: 'docs.production',
            node: 'node-a',
            workspace: 'orbit',
        },
    },
});

// Concrete mutation body with app selector.
const restartBody = {
    app: 'test.app.example',
    name: 'vite',
} satisfies ProcessRestartBody;

await client.POST('/processes/restart', {
    body: restartBody,
});

await client.POST('/processes/start', {
    body: {
        app: 'test.app.example',
        name: 'vite',
    },
});

await client.POST('/processes/stop', {
    body: {
        app: 'test.app.example',
        name: 'vite',
    },
});

// Prove list item status union is concrete when present in schema.
type ProcessListItem = ProcessListSuccess extends {
    success?: { data?: { processes?: Array<infer Item> } };
}
    ? Item
    : never;

type ProcessStatus = ProcessListItem extends { status?: infer S } ? S : never;

const statusOk: ProcessStatus = 'running';
void statusOk;
const transitionalOk: ProcessRuntimeStatus = 'starting';
void transitionalOk;
const lifecycleOk: ProcessLifecycleEventType = 'failed';
void lifecycleOk;
const lastEventOk: ProcessStreamLastEvent = { id: 1, type: 'started' };
void lastEventOk;

// Invalid: status is a concrete enum, not free-form text.
// @ts-expect-error process status must be the durable gateway union
const invalidStatus: ProcessRuntimeStatus = 'booting';
void invalidStatus;

// @ts-expect-error lifecycle event type is a closed union
const invalidLifecycle: ProcessLifecycleEventType = 'booting';
void invalidLifecycle;

// Snapshot required shape (no optional collapse to string).
const snapshotOk: ProcessStreamSnapshot = {
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
            name: 'vite',
            command: null,
            restart_policy: 'never',
            crash_notification: 'none',
            runtime: 'systemd',
            tool: null,
            service: null,
            runtime_unit: 'orbit_docs_development_main_vite',
            status: 'running',
            last_event: { id: 1, type: 'started' },
        },
    ],
    cursor: { high_water_mark: 1 },
};
void snapshotOk;

const updateOk: ProcessStreamUpdate = {
    id: 2,
    event: 'stopping',
    status: 'stopping',
    name: 'vite',
    node: 'app-1',
    project: 'docs',
    instance: 'development',
    workspace: null,
    unit_name: 'orbit_docs_development_main_vite',
    occurred_at: null,
    exit_code: null,
    exit_status: null,
};
void updateOk;

// subscribeProcessStream is the durable EventSource surface (commands stay on createOrbitGatewayClient).
void subscribeProcessStream;

// Invalid: url is never a process list query key.
const invalidQuery = {
    // @ts-expect-error url is not a valid process list query key
    url: 'https://test.app.example',
} satisfies ProcessListQuery;
void invalidQuery;

// Invalid: url is never a process mutation body key.
const invalidRestartBody = {
    // @ts-expect-error url is not a valid process mutation body key
    url: 'https://test.app.example',
    name: 'vite',
} satisfies ProcessRestartBody;
void invalidRestartBody;

// Required-field proof: app/node/instance/workspace/name are the only known keys.
const knownRestartKeys: Array<keyof ProcessRestartBody> = ['app', 'node', 'instance', 'workspace', 'name'];
void knownRestartKeys;

// Restart and start both expose ordered durable events; start keeps singular terminal event.
type RestartEvents = ProcessRestartRuntime extends { events: infer E } ? E : never;
type StartEvent = ProcessStartRuntime extends { event?: infer E } ? E : never;
type StartEvents = ProcessStartRuntime extends { events?: infer E } ? E : never;
const restartEventsOk: RestartEvents = [];
void restartEventsOk;
const startEventOk: StartEvent = null;
void startEventOk;
const startEventsOk: StartEvents = [];
void startEventsOk;
type RestartHasEvent = ProcessRestartRuntime extends { event?: unknown } ? true : false;
const restartDoesNotUseEvent: RestartHasEvent = false;
void restartDoesNotUseEvent;

await client.GET('/tools', {
    params: {
        query: {
            instance: 'docs.production',
            node: 'node-a',
            self: true,
        },
    },
});

await client.GET('/projects/{project}/instances', {
    params: {
        path: {
            project: 'docs',
        },
    },
});

await client.POST('/instances/{instance}/websocket/enable', {
    params: {
        path: {
            instance: 'docs.production',
        },
    },
});

// @ts-expect-error internal routes are excluded from the public generated client.
await client.POST('/internal-executor/token/verify', {});

// @ts-expect-error deferred optional routes are excluded from the public generated client.
await client.GET('/metrics/status', {});
