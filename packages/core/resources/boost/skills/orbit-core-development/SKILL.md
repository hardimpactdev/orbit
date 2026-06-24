---
name: orbit-core-development
description: Use when changing shared Orbit contracts, helpers, or cross-application primitives in packages/core.
---

# Orbit Core Development

`packages/core` is the shared Orbit package for contracts, helpers, and
cross-application primitives consumed by the gateway, CLI, and SDK.

## When To Use

- Editing code under `packages/core/src/`.
- Adding or changing shared enums, DTOs, security helpers, progress streaming,
  or HTTP envelope utilities used by multiple apps.
- Updating core Pest coverage under `packages/core/tests/`.

## Boundaries

- Keep `packages/core` framework-light. It must not depend on gateway-only or
  CLI-only application code.
- Do not add Laravel Boost, gateway routes, or CLI commands here.
- Product behavior contracts live in `apps/docs/content/`; update docs when
  shared contracts change.

## Verification

From the repo root:

```bash
cd packages/core && vendor/bin/pest --compact
cd packages/core && vendor/bin/pint --dirty --format agent
cd packages/core && vendor/bin/phpstan analyse --memory-limit=512M
```

For broader safety after core contract changes:

```bash
composer quality-check
```
