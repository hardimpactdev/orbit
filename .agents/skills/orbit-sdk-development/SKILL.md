---
name: orbit-sdk-development
description: Use when changing the Orbit gateway API SDK, request objects, or Laravel SDK bindings in packages/sdk.
---

# Orbit SDK Development

`packages/sdk` is the Laravel SDK for consuming the Orbit gateway API. Gateway
request classes and client bindings live here and are consumed by `apps/gateway`
and `apps/cli`.

## When To Use

- Editing code under `packages/sdk/src/`.
- Adding or changing gateway API request objects, connectors, mocks, or Laravel
  service bindings.
- Aligning SDK request shapes with gateway API contracts.

## Boundaries

- Request classes belong in `packages/sdk/src/Requests/`.
- Gateway HTTP handlers and business logic stay in `apps/gateway`.
- Product API contracts are authoritative in `apps/docs/content/` and gateway
  architecture tests.

## Verification

From the repo root:

```bash
bin/orbit-gateway-pest --compact tests/Feature/Architecture/GatewayApiContractTest.php
cd packages/sdk && vendor/bin/pint --dirty --format agent
composer quality-check
```