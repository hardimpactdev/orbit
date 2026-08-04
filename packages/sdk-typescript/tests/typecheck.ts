import { createOrbitGatewayClient, type OrbitGatewayClient } from '../src/index.js';
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

// Invalid: status is a concrete enum, not free-form text.
// @ts-expect-error process status must be running|stopped|crashed|unknown
const invalidStatus: ProcessStatus = 'booting';
void invalidStatus;

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

// Restart runtimes expose plural events; start keeps singular event.
type RestartEvents = ProcessRestartRuntime extends { events: infer E } ? E : never;
type StartEvent = ProcessStartRuntime extends { event?: infer E } ? E : never;
const restartEventsOk: RestartEvents = [];
void restartEventsOk;
const startEventOk: StartEvent = null;
void startEventOk;
type RestartHasEvent = ProcessRestartRuntime extends { event?: unknown } ? true : false;
type StartHasEvents = ProcessStartRuntime extends { events?: unknown } ? true : false;
const restartDoesNotUseEvent: RestartHasEvent = false;
void restartDoesNotUseEvent;
const startDoesNotUseEvents: StartHasEvents = false;
void startDoesNotUseEvents;

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
