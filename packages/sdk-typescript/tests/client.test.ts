import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createOrbitGatewayClient, resolveOrbitGatewayApiBaseUrl } from '../src/client.ts';

function requestUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') {
        return input;
    }

    if (input instanceof URL) {
        return input.toString();
    }

    return input.url;
}

function requestHeaders(input: RequestInfo | URL, init?: RequestInit): Headers {
    if (input instanceof Request) {
        return new Headers(input.headers);
    }

    return new Headers(init?.headers);
}

function jsonOk(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('resolveOrbitGatewayApiBaseUrl', () => {
    it('appends /api to a gateway root base URL', () => {
        assert.equal(resolveOrbitGatewayApiBaseUrl('https://gateway.orbit'), 'https://gateway.orbit/api');
    });

    it('strips trailing slashes before appending /api', () => {
        assert.equal(resolveOrbitGatewayApiBaseUrl('https://gateway.orbit/'), 'https://gateway.orbit/api');
        assert.equal(resolveOrbitGatewayApiBaseUrl('https://gateway.orbit///'), 'https://gateway.orbit/api');
    });

    it('does not double /api when the base already ends with /api', () => {
        assert.equal(resolveOrbitGatewayApiBaseUrl('https://gateway.orbit/api'), 'https://gateway.orbit/api');
        assert.equal(resolveOrbitGatewayApiBaseUrl('https://gateway.orbit/api/'), 'https://gateway.orbit/api');
    });
});

describe('createOrbitGatewayClient', () => {
    it('targets /api/processes/start from a gateway root base URL', async () => {
        const requests: string[] = [];

        const client = createOrbitGatewayClient({
            baseUrl: 'https://gateway.orbit',
            fetch: async (input) => {
                requests.push(requestUrl(input));

                return jsonOk({ success: { data: { runtimes: [] } } });
            },
        });

        await client.POST('/processes/start', {
            body: {
                app: 'app.example.test',
                name: 'vite',
            },
        });

        assert.equal(requests.length, 1);
        assert.equal(requests[0], 'https://gateway.orbit/api/processes/start');
    });

    it('does not double /api when baseUrl already ends with /api', async () => {
        const requests: string[] = [];

        const client = createOrbitGatewayClient({
            baseUrl: 'https://gateway.orbit/api',
            fetch: async (input) => {
                requests.push(requestUrl(input));

                return jsonOk({ success: { data: { runtimes: [] } } });
            },
        });

        await client.POST('/processes/start', {
            body: {
                app: 'app.example.test',
                name: 'vite',
            },
        });

        assert.equal(requests[0], 'https://gateway.orbit/api/processes/start');
    });

    it('normalizes trailing slashes on gateway root and /api bases', async () => {
        const roots: string[] = [];
        const apis: string[] = [];

        const rootClient = createOrbitGatewayClient({
            baseUrl: 'https://gateway.orbit/',
            fetch: async (input) => {
                roots.push(requestUrl(input));

                return jsonOk({ success: { data: { runtimes: [] } } });
            },
        });
        const apiClient = createOrbitGatewayClient({
            baseUrl: 'https://gateway.orbit/api/',
            fetch: async (input) => {
                apis.push(requestUrl(input));

                return jsonOk({ success: { data: { runtimes: [] } } });
            },
        });

        await rootClient.POST('/processes/start', {
            body: { app: 'app.example.test', name: 'vite' },
        });
        await apiClient.POST('/processes/start', {
            body: { app: 'app.example.test', name: 'vite' },
        });

        assert.equal(roots[0], 'https://gateway.orbit/api/processes/start');
        assert.equal(apis[0], 'https://gateway.orbit/api/processes/start');
    });

    it('preserves custom headers middleware on the resolved /api base', async () => {
        let seenClientHeader: string | null = null;
        let seenUrl: string | null = null;

        const client = createOrbitGatewayClient({
            baseUrl: 'https://gateway.orbit',
            headers: {
                'X-Orbit-Client': 'laravel-toolbar',
            },
            fetch: async (input, init) => {
                seenUrl = requestUrl(input);
                seenClientHeader = requestHeaders(input, init).get('X-Orbit-Client');

                return jsonOk({ success: { data: { processes: [] } } });
            },
        });

        await client.GET('/processes', {
            params: {
                query: {
                    app: 'app.example.test',
                },
            },
        });

        assert.equal(seenUrl, 'https://gateway.orbit/api/processes?app=app.example.test');
        assert.equal(seenClientHeader, 'laravel-toolbar');
    });
});
