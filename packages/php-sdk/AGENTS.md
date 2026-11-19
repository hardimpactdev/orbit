# Orbit PHP SDK

Saloon 4 client for the Orbit Gateway API, used by the CLI. This file holds every SDK rule. Read no other instruction file before a change; the root `AGENTS.md` owns the workflow, checks and review.

## Boundaries

- Keep the SDK framework-neutral: no Laravel, Boost, container, facade, framework helper, application config or environment read.
- Transport only. Each request is a typed Saloon request with the exact HTTP method and endpoint of the Gateway API, and a typed response DTO. Business logic, validation policy, presentation and remote execution belong to the Gateway or the CLI.
- Model only operations the Gateway API exposes. Change a request together with its Gateway route and CLI command, in one branch.
- Preserve every caller value. Omit a field only when it is `null` and the API treats null as absence; keep explicit empty strings, empty arrays, `false` and zero. Keep bodyless requests bodyless.
- Parse the success and error envelopes in the shared `GatewayRequest` base. Expose no raw response body and no generic escape hatch.

## Security

- Preserve the Gateway's error code, safe message, safe details and request ID. Generate and validate `X-Orbit-Request-Id` through `Support\GatewayRequestId`.
- Redact credentials from URLs, nested payloads, exception text, debug output and serialization. Treat every environment value as sensitive. Mark credential parameters with `#[SensitiveParameter]`.
- Accept only a safe HTTPS Gateway origin and keep redirects disabled. `verify=false` exists only inside the one-shot `GatewayRootCaClient` bootstrap; a normal connector never lowers TLS verification.

## Code and tests

- Start every PHP file with `declare(strict_types=1)`.
- From `packages/php-sdk`, run the tests that cover the change: `vendor/bin/pest --compact tests/Unit/...Test.php`, or `--filter=<name>`. `composer test` runs the whole suite.
- Use Saloon's mock client; contact no live Gateway or Node. Assert the method, URL, query, body, headers, DTO and error envelope. Use unique credential-shaped sentinels and assert their absence from messages, arrays, debug output and serialization.
