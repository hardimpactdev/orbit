---
title: "API reference generation"
description: "Contract, inputs, and commands for bin/docs-openapi and API artifact checks."
covers:
  - bin/docs-openapi
---

# API reference generation

`bin/docs-openapi` generates `docs/openapi.json` for the Gateway API (rendered on the [API tab](/api/overview)) and updates the generated API navigation groups in `docs/docs.json`.

## Commands and outputs

Run the API generators and fixture verification from the repository root:

```bash
bin/build-api           # runs bin/docs-openapi, then bin/mcp-tools
composer api-fixtures   # runs bin/api-fixtures --check
```

`composer docs-openapi` runs `bin/docs-openapi` directly; `bin/docs-openapi --check` validates without writing. In `apps/web`, `bun run types` (or `bun run check`) regenerates `apps/web/src/api/schema.d.ts` from `docs/openapi.json`.

All generated files stay ignored by Git: `docs/openapi.json`, `docs/docs.json`, `apps/gateway/resources/mcp/tools.json`, and `apps/web/src/api/schema.d.ts`. Both `composer check` (`bin/review-check`) and CI run `bin/build-api` and `bin/api-fixtures --check`.

## Determinism and external inputs

The same checkout must produce the exact same `docs/openapi.json`. Three inputs live outside tracked source files and must stay pinned:

- `apps/gateway/.env` (development app key and config)
- `apps/gateway/vendor` (locked Composer dependencies)
- PHP 8.5 (matching CI)

## Generator inputs

`bin/docs-openapi` inspects four sources without invoking `orbit list`:

| Source | What it extracts |
| --- | --- |
| `php apps/gateway/artisan route:list --json --path=api` | Every Gateway API route, HTTP method, URI, and controller action |
| `apps/gateway/app/**` and `extensions/*/src/**` | Controllers, FormRequests, Spatie Data DTOs, and backed enums |
| `packages/php-sdk/src/**` | Shared SDK response/request DTOs |
| `apps/cli/app/Commands/**` | CLI command names and descriptions mapped to API routes |

## Contract rules

The generator enforces these schema rules and fails when source code disagrees:

- **Operations (`#[StartsOperation]`):** Marks routes that return an `Operation` receipt, including `keyed: false`, `requiresKey` (`Idempotency-Key` required), and `bindCaller` metadata.
- **Destructive routes:** Marks every `DELETE` route and `DESTRUCTIVE_POSTS` (`instance:unregister`, `ai-router:task-guard`) with `x-orbit-destructive`, copied by `bin/mcp-tools` to `destructive`.
- **Internal callbacks:** Routes in `INTERNAL` (such as Node callbacks like `operation:direct:executor-sign`) stay excluded from the operator MCP catalogue.
- **Validation mapping:** Laravel `present` maps to required; `strictBoolean`/`strictTrue` map to strict boolean/true schemas; `Rule::in(...)` resolves class constants; partial patch bodies enforce `minProperties: 1`.
- **Hand-written schemas:** Complex conditional bodies (such as `conn_settings_body()`, `ConnLaunchSnapshot`, `TaskAgentSnapshot`, and `agent:facts` `kind` enum `host`, `units`, `checkout`, `checkouts`, `packages`) are defined explicitly in `bin/docs-openapi` and verified by Gateway contract tests.

For example, `DELETE /api/v1/conn/environments/{environment}` generates the bodyless destructive `conn-environment-unregister` tool returning 200 `{environment_id, removed}` and documenting `node_access.required` (403), `conn.environment_is_instance` (409), and `operation.target_busy` (409) ([Unregister a host server](/reference/conn#unregister-a-host-server)).
