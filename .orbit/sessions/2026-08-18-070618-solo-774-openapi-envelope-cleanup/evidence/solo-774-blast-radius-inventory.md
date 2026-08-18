# Todo 774 — Blast Radius Inventory

Candidate: `fc01b23d58b76a72d9da394a68727f3aab89ba0f`
Base: `main` @ `00d201c46c4a6f1731b502361d7abd80d5444040`
Diff: 5 files changed, +1 / −123.

## Method

Read `GatewayOpenApi.php` component registration, repository-wide `rg` sweep (default +
`-uu`), OpenAPI export + TS-SDK regeneration, full gateway Pest, `composer quality-check`.

## Result — dead envelope components fully removed

- `apps/gateway/app/Support/OpenApi/GatewayOpenApi.php` (−45) — the `OrbitSuccessEnvelope`
  and `OrbitErrorEnvelope` component registrations + builder helpers removed. No operation
  response referenced them; `OrbitSuccessEnvelope` also omitted the outer success key
  (inaccurate). All other components + concrete operation responses untouched.
- `apps/gateway/tests/Feature/OpenApiSchemaContractTest.php` (−5) — the two existence
  assertions (`components.schemas.OrbitSuccessEnvelope…`, `…OrbitErrorEnvelope…`) removed;
  every other assertion kept (concrete responses remain shape authority).
- `apps/gateway/mago-linter-baseline.toml` (−12) — the now-stale baseline entries naming the
  removed component paths pruned.
- Regenerated TS SDK: `packages/sdk-typescript/openapi/public-gateway-openapi.json` (−45) and
  `packages/sdk-typescript/src/generated/schema.ts` (−17) — the `OrbitSuccessEnvelope`/
  `OrbitErrorEnvelope` entries are gone. The gitignored `dist/generated/schema.d.ts` is
  regenerated on disk without them.

## Core acceptance — no reference or generated type remains

- Default `rg 'OrbitSuccessEnvelope|OrbitErrorEnvelope' apps/gateway packages/sdk-typescript`
  → **nothing** (source + tracked SDK clean).
- The only `-uu` (gitignore-including) hits were ephemeral Pest-worker scratch under
  `apps/gateway/storage/framework/testing/worker-*/` — regenerated each test run, gitignored,
  cleared. Not source, not the candidate.

## Verdict

BLAST_RADIUS: complete — evidence = `GatewayOpenApi` read + `rg` sweep (default + `-uu`) +
OpenAPI export + TS-SDK regen + full gateway Pest + `composer quality-check`; result = both
unreferenced envelope components + builders + existence assertions removed, SDK regenerated
without the types, no reference or generated type remains, concrete operation responses
unchanged.
