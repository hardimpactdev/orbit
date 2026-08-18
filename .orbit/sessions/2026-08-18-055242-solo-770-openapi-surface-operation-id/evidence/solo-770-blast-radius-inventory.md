# Todo 770 — Blast Radius Inventory

Candidate: `50ab6aa8aaf4cdf13e5e38d686af5c9144bc77f1`
Base: `main` @ `0a48a5782fc245d0ba3b71d743701fff7ccc2511`
Diff: 2 files changed, 91 deletions(-), 0 insertions.

## Method

Repository-wide `rg`/`grep` sweep for the removed field + downstream-reader check +
TS-SDK regeneration diff + full gateway Pest + `composer quality-check`.

## Result — operation_id is dead metadata, fully removed

- `apps/gateway/openapi-sdk-surface.json` — the `"operation_id"` line deleted from ALL
  74 `schema_only_operations` entries; `grep -c operation_id` → **0**. `operation`
  (method+path) is now the sole entry identity. `operation`, `classification`, `group`,
  `reason`, `follow_up`, and top-level metadata untouched.
- `apps/gateway/tests/Feature/OpenApiSdkSurfaceContractTest.php` — Test 1 only: the three
  fixed cardinality pins removed (schemaOperations count 174, schemaOnlyOperations count
  74, and the `['internal_only'=>14,'deferred_optional'=>37,'public_sdk'=>23]` aggregate
  map). `->duplicates()` detection, bidirectional coverage, the internal set, and public
  follow-up checks retained. Test 2 (asserts the EXPORTED schema's operationId, not the
  manifest) untouched.

## Nothing reads the manifest operation_id

- The TS filter `packages/sdk-typescript/scripts/filter-public-openapi.mjs` keys on
  `operation` and reads only the EXPORTED spec's operationId — never the manifest field.
- The contract test keys on `operation`; the operationId it asserts (Test 2) is from the
  exported schema.
- Four `appInstance.*` manifest values had already drifted stale (real `instance.*`)
  precisely because nothing validates the field — confirming it is pure dead metadata.

## Downstream regeneration byte-identical

- `git diff main...HEAD` touches **only** the manifest + contract test. **No** change to
  `packages/sdk-typescript/openapi/public-gateway-openapi.json`,
  `packages/sdk-typescript/src/generated/schema.ts`, or the
  `.orbit/evidence/typescript-sdk-public-openapi-summary.json` — removing operation_id
  changes nothing in the exported spec or generated SDK.

## Verdict

BLAST_RADIUS: complete — evidence = `grep`/`rg` sweep + downstream-reader audit + scoped
2-file diff + full gateway Pest + `composer quality-check`; result = dead `operation_id`
removed from all 74 entries, no reader affected, TS SDK regeneration byte-identical,
cardinality pins removed with duplicate/coverage/internal checks intact.
