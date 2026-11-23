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

## The document each release serves

Each Gateway release serves its own document at `GET /api/v1/openapi.json` (`gateway-openapi`), to any active WireGuard peer. The `X-Orbit-OpenAPI-SHA256` header is the SHA-256 of the body, so a client such as Conn can pin the document it generated its types from. The step "Build the release" (`swamp/scripts/gateway-release-build.sh`) runs `composer install` in `apps/gateway`; its `post-install-cmd` runs `bin/build-api` in the release checkout. That one run writes both `docs/openapi.json`, which the route serves, and the MCP tool manifest that the release loads. A release from before this route has none, and a rollback to it removes the route. Without a generated document the route answers 503 `openapi.unavailable`. `bin/mcp-tools` excludes the route.

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

The generator applies these schema rules:

- **Operations (`#[StartsOperation]`):** Marks routes that return an `Operation` receipt, including `keyed: false`, `requiresKey` (`Idempotency-Key` required), and `bindCaller` metadata.
- **Destructive routes:** Marks every `DELETE` route and `DESTRUCTIVE_POSTS` (`instance:unregister`, `ai-router:task-guard`) with `x-orbit-destructive`, copied by `bin/mcp-tools` to `destructive`.
- **Internal callbacks:** Routes in `INTERNAL` (such as Node callbacks like `operation:direct:executor-sign`) stay excluded from the operator MCP catalogue.
- **Validation mapping:** Laravel `present` maps to required; `strictBoolean`/`strictTrue` map to strict boolean/true schemas; partial patch bodies enforce `minProperties: 1`.
- **Enum rules:** `Rule::in(...)` resolves class constants, also a whole array constant such as `Rule::in(ConnPush::EventTypes)`.
- **Hand-written schemas:** Complex conditional bodies (`conn_settings_body()`, `ConnLaunchSnapshot`, `TaskAgentSnapshot`, and `agent:facts`) are maintained manually in `bin/docs-openapi` rather than derived from runtime validators.

Manual schemas can lag behind runtime validators: for example, `agent:facts` currently hard-codes five `kind` values (`host`, `units`, `checkout`, `checkouts`, `packages`), while `NodeFactKind` also accepts `metrics`, `ufw`, `env_keys`, and `accounts`.

For example, `DELETE /api/v1/conn/environments/{environment}` generates the bodyless destructive `conn-environment-unregister` tool returning 200 `{environment_id, removed}` and documenting `node_access.required` (403), `conn.environment_is_instance` (409), and `operation.target_busy` (409) ([Unregister a host server](/reference/conn#unregister-a-host-server)).

## Typed client contract rules

`bin/docs-openapi` generates exact request, response, error, and authentication schemas for every route under `/api/v1/conn` and the six operations Conn calls outside it (`operation-show`, `instance-wake`, `realtime-show`, `realtime-auth`, `task-insights`, and `project-list`).

When a Form Request switches on `$this->route()?->getName()` with a PHP `match` expression (as `ConnManagedTargetRequest::rules()` does), the generator selects only the arm matching the route name. It resolves standalone `self::` rule constants and `self::strictInteger()` or `self::time()` helpers, and emits each route's real body into both `docs/openapi.json` and the MCP manifest. Form Requests that inherit `rules()` from a parent request, such as `FinalizeConnReservationRequest`, inherit their parent's rule set. Routes behind `AuthenticateConnManagedTarget` prepend required `environment_id` and `generation` fields to the JSON request body. Empty-body POST routes in this set (`conn:environment:renew`, `conn:environment:cleanup`, `conn:instance:complete`, `conn:operation:cancel`, `conn:reservation:create`, `conn:launch:cancel`, and `instance:wake`) omit `requestBody` rather than publishing a bare object. `POST /api/v1/broadcasting/auth` is documented as `POST` with its JSON body and bare `{auth, channel_data?}` response.

`components.securitySchemes` declares `wireguardPeer` (`mutualTLS`, active WireGuard peer identity verified by `RequireActiveWireGuardPeer`), `managedTargetBearer` (`http` bearer service credential verified by `AuthenticateConnManagedTarget`), and `taskKeyBearer` (`http` bearer task copy key verified by `RequireTaskKey`). Top-level `security` defaults to `wireguardPeer`. Public trust bootstrap routes (`gateway:status` and `gateway:trust`) override `security` to `[]`. Managed target routes under `/api/v1/conn/managed/*` require both `wireguardPeer` and `managedTargetBearer`. Task copy routes behind `RequireTaskKey` (`copy:whoami`, `copy:handoff`, and `copy:pull-request`) require both `wireguardPeer` and `taskKeyBearer`.

`components.schemas.Error` matches every error body the Gateway sends on a documented operation: a closed top-level object with required `error` and optional `status` (sent as `409` by `RequireEnabledExtension` for `extension.disabled`), where `error` is a closed object with required `code` and `message` and optional `details` (omitted by `GuardBrowserOrigins` for `api.origin_refused`). When present, `details` uses `oneOf` to accept either an open object or an empty array `[]`, matching PHP's encoding of empty arrays before or after `NormalizeErrorDetails`. `error.request_id` is not a body field; the request ID travels in the `X-Orbit-Request-Id` response header.

Every Data schema in this set emits a `required` list containing every non-`Optional` constructor property that the Gateway always sends. Closed value sets are declared as JSON Schema `enum`s (`ConnEnvironment.status`, `ConnEnvironment.power_state`, `ConnReservation.state`, `ConnThread.state`, `ConnHeldSession.token_type`, `Operation.status`, `OperationStep.status`, `TaskInsights.period`, and the Conn task, work, and managed schemas). Bare Pusher auth routes (`realtime:auth`, `conn:realtime:auth`, and `conn:managed:broadcasting-auth`) document their bare `{auth}` envelope without a `{data, meta}` wrapper. Under the accepted limits, `Operation.input`, `Operation.result`, `Operation.error`, `Operation.assistance`, `ConnProfileSettings.settings`, and `ConnProfileSettings.sections` remain open objects (`additionalProperties: true`); PHP-array fields that can serialize as `[]` when empty (`Operation.input`, `Operation.result`, `Operation.error`, `Operation.assistance`, and `ConnProfileSettings.settings`) use the same object-or-empty-array `oneOf` as `Error.details` (with `null` added for nullable fields), while `ConnProfileSettings.sections` always casts empty arrays to `{}`.
