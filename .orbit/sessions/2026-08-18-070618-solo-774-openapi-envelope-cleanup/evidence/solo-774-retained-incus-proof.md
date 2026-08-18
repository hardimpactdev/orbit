# Todo 774 — Retained-Incus Runtime Proof

## Identity
- Candidate: `fc01b23d58b76a72d9da394a68727f3aab89ba0f`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `orbit-e2e-dev-e8ab86` (operator_gateway_app-dev; roles operator, gateway, dev)
- Source binding: operator VM `/home/orbit/orbit` verified bound to the candidate by
  file-content sha256 (worktree == VM):
  - `apps/gateway/app/Support/OpenApi/GatewayOpenApi.php`
    `21ba45a3691741bc307998457079e532c0c13feee2d4d47ad987ca0cb4745aa3`
  - VM `grep -rc OrbitSuccessEnvelope|OrbitErrorEnvelope apps/gateway/app packages/sdk-typescript/src`
    → **0** (no reference or generated type remains).

## What was exercised
`OpenApiSchemaContractTest` executed inside the retained operator VM against the
candidate-bound source (freshly migrated SQLite DB). The test exports the gateway OpenAPI
schema and asserts contract metadata; with the envelope components removed and the two
existence assertions dropped, it verifies the exported schema is well-formed and the
concrete operation responses remain the shape authority.

## Observed
```
PASS Tests\Feature\OpenApiSchemaContractTest
Tests: 1 passed (84 assertions)
Duration: 7.91s
```

## Receipt (structured)
- candidate=`fc01b23d58b76a72d9da394a68727f3aab89ba0f`
- venue=retained-incus
- environment=dev-fixture
- expected=OpenApiSchemaContractTest green in the retained operator VM after removing the
  unreferenced OrbitSuccessEnvelope/OrbitErrorEnvelope components and their existence
  assertions; no envelope reference or generated type remains
- observed=1 passed / 84 assertions, no failures
- result=passed
- command=`ssh beast incus exec orbit-e2e-dev-e8ab86-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/774-test.sqlite && touch /tmp/774-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Feature/OpenApiSchemaContractTest.php --compact'`

Release: `composer e2e:incus -- --stop --id=dev-e8ab86`
