---
name: orbit-gateway-development
description: Use when working in apps/gateway on gateway HTTP/API routes, control-plane provisioning, database.sqlite, operation events, E2E harness support, frontend build, or gateway-local quality tooling.
---

# Orbit Gateway Development

`apps/gateway` is the Laravel gateway/control-plane application. It owns the
gateway HTTP/API surface, gateway database, provisioning logic, operation
events, E2E harness support, deployed assets, frontend build, and
gateway-local quality tooling.

## When To Use

- Editing routes, controllers, models, migrations, services, tests, config, or
  frontend assets under `apps/gateway/`.
- Changing gateway API contracts, provisioning flows, operation events,
  node/app/workspace state, gateway persistence, or gateway-local quality
  tooling.
- Using Laravel Boost tools. Boost is installed in `apps/gateway` and its MCP
  application context is gateway-scoped.

## Boundaries

- Do not add a root Laravel app or root Boost install.
- From the repo root, run gateway Artisan through `bin/orbit-gateway-artisan`
  or `php apps/gateway/artisan`; do not assume a root `artisan` exists.
- The gateway database is SQLite at
  `apps/gateway/database/database.sqlite`.
- CLI operator behavior belongs in `apps/cli`; shared contracts belong in
  `packages/core`; SDK request objects belong in `packages/sdk`; product docs
  belong in `apps/docs/content`.

## Required Skills

- Read `.agents/skills/spatie-laravel-php/SKILL.md` for Laravel/PHP edits.
- Read `.agents/skills/pest-testing/SKILL.md` before changing gateway tests.
- Read `.agents/skills/spatie-security/SKILL.md` for auth, credentials,
  firewall, SSH, WireGuard, or server configuration.

## Verification

From the repo root:

```bash
bin/orbit-gateway-pest --compact tests/Feature/<TestFile>.php
bin/orbit-gateway-pest --compact
bin/orbit-gateway-vendor-bin mago format --check
composer quality-check
```

Before adding, changing, debugging, or running E2E tests, read
`apps/docs/content/testing/README.md`. Agents do not run `composer test:e2e*`
unless the user explicitly invokes that lane from a shell.
