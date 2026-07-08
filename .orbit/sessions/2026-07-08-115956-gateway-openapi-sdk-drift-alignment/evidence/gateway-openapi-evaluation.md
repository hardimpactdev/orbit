# Gateway OpenAPI Evaluation

## Commands

- `composer require dedoc/scramble --dev` from `apps/gateway`: passed; installed `dedoc/scramble v0.13.31`.
- `php apps/gateway/artisan package:discover --no-ansi`: passed; `dedoc/scramble` discovered.
- `php apps/gateway/artisan scramble:export --path=.orbit/evidence/gateway-openapi.json --no-ansi`: failed with missing SQLite database before local app state was prepared.
- `touch apps/gateway/database/database.sqlite && php apps/gateway/artisan migrate --force --no-ansi`: passed; local ignored gateway database prepared.
- `php apps/gateway/artisan scramble:export --path=.orbit/evidence/gateway-openapi.json --no-ansi`: exited 255 with no output, consistent with PHP memory exhaustion during full route inference.
- `php -d memory_limit=1G apps/gateway/artisan scramble:export --path=.orbit/evidence/gateway-openapi.json --no-ansi && jq empty .orbit/evidence/gateway-openapi.json`: passed.

## Schema Summary

- OpenAPI version: 3.1.0.
- Server URL: `http://localhost/api`; schema paths omit the `/api` prefix.
- Paths: 126.
- Operations: 159.
- Component schemas: 12.
- Operation ids: 159 generated, with one duplicate/collision family: `toolLifecycle_0` for tool lifecycle start/stop/restart.
- Responses: Scramble inferred JSON object responses for 157 operations and no JSON schema for 2 operations.
- Coverage: default Scramble routing covered the gateway API route file and did not emit obvious non-API route noise.

## SDK Comparison

Comparison artifact: `.orbit/evidence/sdk-openapi-comparison.json`.

- Parsed SDK static requests: 92 requests across 91 method/path keys.
- Dynamic SDK request files excluded from static comparison: `CloudflareRequest.php`, `SchemaDatabaseConnectionRequest.php`, `GenericGatewayStreamRequest.php`.
- SDK keys missing from schema: 3, all node-role placeholder-name differences (`{node}` in SDK vs `{name}` in gateway routes), not missing route behavior.
- Schema operations without static SDK request classes: 71. This includes analytics, Codex, app instances, app mounts/setup/websocket, extensions, manifest, metrics, node self-manage, operation event streams, profile resolve, S3, Solo proxy, tool lifecycle aliases, update start/artifact download, and workspace resolve/history/show helpers.
- Query drift rows: 12. Scramble sees query params on apps/database/nodes/php/tools/workspaces deletes/lists where SDK either has no default query method or puts destructive options in request bodies; SDK also exposes deploy/firewall query options not inferred by Scramble.

## Evaluation

Scramble is useful immediately as a gateway-owned route inventory and rough response-shape snapshot. It is not yet a durable SDK-generation contract without follow-up work because important request/query conventions, operation-id stability, reusable envelopes, and SDK coverage need explicit design.

Recommended follow-ups:

1. Add gateway-local Scramble config/customization if OpenAPI becomes durable output: title/version, route scope, auth/security scheme, and stable operation ids for multi-action controllers.
2. Decide whether destructive consent and target filters are query params or body fields in the SDK/gateway contract, then align SDK request classes or controller validation in a separate SDK-contract slice.
3. Add annotations or response resources for common Orbit success/error envelopes so schemas are reusable instead of mostly inline per operation.
4. Triage the 71 schema-only operations into intended SDK coverage vs intentionally internal gateway endpoints.
