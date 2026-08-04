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

## Versioning and publication

This package is **independently versioned** from Orbit monorepo root `VERSION`.
The version in this `package.json` is authoritative (public stream starts at
`0.1.0`). Canonical source remains `packages/sdk-typescript` in
`hardimpactdev/orbit` (`private: true` in-tree). Durable consumers install from
npm and/or the generated repository `hardimpactdev/orbit-sdk-typescript`.

Publication path (Craft-style, package-repo OIDC only):

1. Prepare a durable split tree from the monorepo (does not publish):

   ```bash
   bin/orbit-prepare-release-package \
     --package=sdk-typescript \
     --version="$(node -p "require('./packages/sdk-typescript/package.json').version")" \
     --output=/tmp/orbit-sdk-typescript
   ```

2. Push the prepared tree to `hardimpactdev/orbit-sdk-typescript` `main`.
3. Create and **publish** a GitHub Release tag `v0.1.0` (or later) on that
   repository. The package’s `.github/workflows/publish.yml` runs on
   `release: published` with Node 24, `id-token: write`, `npm ci`, `npm test`,
   `npm run build`, `npm version` from the tag, and
   `npm publish --provenance --access public`.

Monorepo `.github/workflows/orbit-release.yml` does **not** publish this package
to npm and does not stamp root Orbit VERSION into it.

```bash
# Optional dry-run from monorepo source package:
(cd packages/sdk-typescript && npm ci && npm test && npm run build && npm pack --dry-run)
```
