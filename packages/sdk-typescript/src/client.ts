import createClient, { type Client, type Middleware } from 'openapi-fetch';
import type { paths } from './generated/schema.js';

export type OrbitGatewayHeaders = HeadersInit | (() => HeadersInit | Promise<HeadersInit>);

export interface OrbitGatewayClientOptions {
    baseUrl: string;
    fetch?: typeof fetch;
    headers?: OrbitGatewayHeaders;
}

export type OrbitGatewayClient = Client<paths>;

export function createOrbitGatewayClient(options: OrbitGatewayClientOptions): OrbitGatewayClient {
    const clientOptions = {
        baseUrl: options.baseUrl,
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
