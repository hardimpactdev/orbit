# Todo 770 — Retained-Incus Runtime Proof

## Identity
- Candidate: `50ab6aa8aaf4cdf13e5e38d686af5c9144bc77f1`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `orbit-e2e-dev-fb7708` (operator_gateway_app-dev; roles operator, gateway, dev)
- Source binding: operator VM `/home/orbit/orbit` verified bound to the candidate by
  file-content sha256 (worktree == VM):
  - `apps/gateway/openapi-sdk-surface.json`
    `145e7670005169628136c044080b88c2a50b341d56a695d13f8dd1e1f4e4fd20`
  - `grep -c operation_id apps/gateway/openapi-sdk-surface.json` in VM → **0**.

## What was exercised
`OpenApiSdkSurfaceContractTest` executed inside the retained operator VM against the
candidate-bound source (against a freshly migrated SQLite DB):

- Test 1 "gateway openapi schema-only operations are classified for sdk generation" — the
  manifest-reading test directly affected by the operation_id removal + cardinality-pin
  removal (duplicate detection, bidirectional coverage, internal set, follow-up all intact).
- Test 2 "application log openapi documents lines node and required workspace…" — asserts
  the EXPORTED schema's operationId (unchanged by this todo).

## Observed
```
PASS Tests\Feature\OpenApiSdkSurfaceContractTest
Tests: 2 passed (395 assertions)
Duration: 13.73s
```

## Receipt (structured)
- candidate=`50ab6aa8aaf4cdf13e5e38d686af5c9144bc77f1`
- venue=retained-incus
- environment=dev-fixture
- expected=OpenApiSdkSurfaceContractTest green in the retained operator VM after removing the
  dead manifest operation_id + cardinality pins; classification/coverage/duplicate checks and
  the exported-schema operationId assertions intact
- observed=2 passed / 395 assertions, 0 failures
- result=passed
- command=`ssh beast incus exec orbit-e2e-dev-fb7708-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/770-test.sqlite && touch /tmp/770-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Feature/OpenApiSdkSurfaceContractTest.php --compact'`

Release: `composer e2e:incus -- --stop --id=dev-fb7708`
