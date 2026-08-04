# Orbit TypeScript SDK

This package generates a typed public gateway client from the gateway-owned OpenAPI contract.

## Generate

```bash
npm run generate
```

The command refreshes `.orbit/evidence/gateway-openapi.json` through `apps/gateway`, filters it with `apps/gateway/openapi-sdk-surface.json`, writes `openapi/public-gateway-openapi.json`, then regenerates `src/generated/schema.ts` with `openapi-typescript`.

The filter keeps existing PHP SDK-covered OpenAPI operations and schema-only operations classified as `public_sdk`. It removes `internal_only` and `deferred_optional` operations from the generated public client.

## Use

### Browser toolbar (process routes)

TypeScript SDK and browser toolbar callers use the **same auth model as the
CLI**:

- Reach the gateway over Orbit/WireGuard.
- Send **no** bearer token and **no** peer-IP identity header
  (`X-Orbit-WireGuard-Ip` / `X-Orbit-E2E-WireGuard-Ip`).
- The gateway derives the caller from the **actual WireGuard peer source IP**,
  then enforces the grant, the required process permission, and existing
  target-node authorization (`WireGuardIdentity` + `RequireGrantPermission` +
  node access authorizer).

CORS only admits a registered browser `Origin` that matches the requested
`app` hostname. CORS never establishes caller identity.

```bash
npm install @hardimpactdev/orbit-sdk-typescript
```

```ts
import { createOrbitGatewayClient } from '@hardimpactdev/orbit-sdk-typescript';

const orbit = createOrbitGatewayClient({
    baseUrl: 'https://gateway.orbit',
    // Optional non-identity client label only. Do not send bearer or peer-IP headers.
    headers: {
        'X-Orbit-Client': 'laravel-toolbar',
    },
});

const { data, error } = await orbit.GET('/processes', {
    params: {
        query: {
            app: window.location.hostname,
        },
    },
});

await orbit.POST('/processes/restart', {
    body: {
        app: window.location.hostname,
        name: 'vite',
    },
});
```

Process list items include a concrete `status` (`running` | `stopped` |
`crashed` | `unknown`) derived from durable gateway lifecycle events, plus
compatible `last_event` when present.

### Node / dashboard clients

Same peer-source-IP auth model as the CLI and browser toolbar: no bearer token
and no client-supplied peer-IP identity header.

```ts
import { createOrbitGatewayClient } from '@hardimpactdev/orbit-sdk-typescript';

const orbit = createOrbitGatewayClient({
    baseUrl: 'https://gateway.example.test',
    // Optional non-identity client label only.
    headers: {
        'X-Orbit-Client': 'macos-dashboard',
    },
});

const { data, error } = await orbit.GET('/nodes', {
    params: {
        query: {
            doctor: true,
        },
    },
});
```

The wrapper is intentionally thin around `openapi-fetch`: macOS/Tauri, TanStack Query, and browser toolbar code should compose request hooks around the typed `GET`, `POST`, `PUT`, `PATCH`, and `DELETE` methods rather than forking gateway route definitions by hand.

## Local package preparation

From the monorepo root, prepare a durable outside-monorepo package tree without publishing:

```bash
bin/orbit-prepare-release-package \
  --package=sdk-typescript \
  --version="$(bin/orbit-version)" \
  --output=/tmp/orbit-sdk-typescript

# Optional dry-run archive of the prepared package:
(cd packages/sdk-typescript && npm run build && npm pack --dry-run)
```

The prepared package keeps the exact `@hardimpactdev/orbit-sdk-typescript`
identity, stamps the monorepo root `VERSION` into both `package.json` and the
root `package-lock.json` identity fields, sets `private=false`, and preserves
public npm metadata (`publishConfig.access`, repository directory provenance for
`packages/sdk-typescript`). The generated split repository is
`hardimpactdev/orbit-sdk-typescript`; canonical source remains
`hardimpactdev/orbit`.

Release order: prepare/verify → push generated split repo/tag → `npm publish
--access public --provenance`. First npm publication may use `NPM_TOKEN`; after
the package exists, prefer npm Trusted Publisher for
`hardimpactdev/orbit` / `orbit-release.yml` and remove the long-lived token.
