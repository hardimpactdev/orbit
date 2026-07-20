import { createOrbitGatewayClient, type OrbitGatewayClient } from '../src/index.js';

const client: OrbitGatewayClient = createOrbitGatewayClient({
    baseUrl: 'https://gateway.orbit.test',
    headers: async () => ({
        Authorization: 'Bearer test-token',
        'X-Orbit-Client': 'macos-dashboard',
    }),
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

await client.GET('/processes', {
    params: {
        query: {
            instance: 'docs.production',
            node: 'node-a',
            workspace: 'orbit',
        },
    },
});

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
