# Orbit TypeScript SDK

This package generates a typed public gateway client from the gateway-owned OpenAPI contract.

## Generate

```bash
npm run generate
```

The command refreshes `.orbit/evidence/gateway-openapi.json` through `apps/gateway`, filters it with `apps/gateway/openapi-sdk-surface.json`, writes `openapi/public-gateway-openapi.json`, then regenerates `src/generated/schema.ts` with `openapi-typescript`.

The filter keeps existing PHP SDK-covered OpenAPI operations and schema-only operations classified as `public_sdk`. It removes `internal_only` and `deferred_optional` operations from the generated public client.

## Use

```ts
import { createOrbitGatewayClient } from '@orbit/sdk-typescript';

const orbit = createOrbitGatewayClient({
    baseUrl: 'https://gateway.example.test',
    headers: async () => ({
        Authorization: `Bearer ${await loadToken()}`,
    }),
});

const { data, error } = await orbit.GET('/nodes', {
    params: {
        query: {
            doctor: true,
        },
    },
});
```

The wrapper is intentionally thin around `openapi-fetch`: macOS/Tauri and TanStack Query code should compose request hooks around the typed `GET`, `POST`, `PUT`, `PATCH`, and `DELETE` methods rather than forking gateway route definitions by hand.
