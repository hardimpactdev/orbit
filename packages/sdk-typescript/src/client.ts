import createClient, { type Client, type Middleware } from 'openapi-fetch';
import type { paths } from './generated/schema.js';

export type OrbitGatewayHeaders = HeadersInit | (() => HeadersInit | Promise<HeadersInit>);

export interface OrbitGatewayClientOptions {
    /**
     * Gateway origin root (for example `https://gateway.orbit`) or the OpenAPI
     * `/api` base (`https://gateway.orbit/api`). Roots are resolved to the `/api`
     * server base so typed paths like `/processes/start` hit the gateway routes.
     * The same gateway-root value is accepted by `subscribeProcessStream`.
     */
    baseUrl: string;
    fetch?: typeof fetch;
    headers?: OrbitGatewayHeaders;
}

export type OrbitGatewayClient = Client<paths>;

/**
 * Resolve a gateway root or `/api` base to the OpenAPI server base used by
 * openapi-fetch. Trailing slashes are stripped. A base that already ends with
 * `/api` is left as-is so callers are not doubled onto `/api/api`.
 */
export function resolveOrbitGatewayApiBaseUrl(baseUrl: string): string {
    const normalized = baseUrl.replace(/\/+$/, '');

    if (normalized.endsWith('/api')) {
        return normalized;
    }

    return `${normalized}/api`;
}

export function createOrbitGatewayClient(options: OrbitGatewayClientOptions): OrbitGatewayClient {
    const clientOptions = {
        baseUrl: resolveOrbitGatewayApiBaseUrl(options.baseUrl),
        ...(options.fetch === undefined ? {} : { fetch: options.fetch }),
    };

    const client = createClient<paths>(clientOptions);

    if (options.headers !== undefined) {
        client.use(createHeadersMiddleware(options.headers));
    }

    return client;
}

function createHeadersMiddleware(headers: OrbitGatewayHeaders): Middleware {
    return {
        async onRequest({ request }) {
            const resolvedHeaders = typeof headers === 'function' ? await headers() : headers;

            new Headers(resolvedHeaders).forEach((value, key) => {
                request.headers.set(key, value);
            });

            return request;
        },
    };
}
