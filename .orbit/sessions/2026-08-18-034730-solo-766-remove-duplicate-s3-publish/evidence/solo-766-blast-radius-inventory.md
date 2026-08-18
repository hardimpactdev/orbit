# Todo 766 — Blast Radius Inventory

Candidate: `896400dc7428b749ce88fd48e23cddf5276c66ce`
Base: `01d862206` (main incl. todo 765)

## Method

Explore duplication sweep + diff review of the S3 route-publishing engine. NOTE:
`S3PublishAction` is an S3 ROUTE-publishing engine (proxy-route/ingress registration),
NOT file upload/delete/checksum — the todo's generic wording does not map; the real
duplication was the route-publishing algorithm.

## Result — complete

Diff `01d862206..896400dc` — 4 files, +188 / −224 (net removal):

- **apps/gateway/app/Services/S3/S3PublishAction.php** (−164 net): DELETED the unreachable
  duplicate `publish()` (incl. its docblock and the dead
  `$action = $alreadyPublished ? 'published' : 'published'` ternary). Kept
  `publishWithProgress()` as the single engine and the shared private helpers
  (resolveS3Node, intendedPublicHosts, storedPublicHosts, error). Added a no-progress
  path so non-streaming callers use the single engine, keeping the `publishWithProgress`
  signature so `S3PublishController` needs no change. `grep 'function publish('` now
  returns 0; `publishWithProgress` remains.
- **apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php** (+154 net): migrated
  the 2 direct `publish()` callers to the single engine; added no-progress-path,
  per-RuntimeException-source `s3.publish_failed`, and engine-layer coverage; extracted a
  `s3PublishParseCompleteFrame` helper to keep test closures within the complexity gate.
- **apps/gateway/mago-analyzer-baseline.toml** / **mago-linter-baseline.toml** (net
  reduction): removed baseline entries for the now-deleted `publish()` code (baselines
  SHRANK — not new suppressions).

## Unreachability proof (re-verified on the candidate)

- Only production caller: `S3PublishController::__invoke` → `publishWithProgress()`
  (routes/api.php `POST /s3/public-hosts`, invokable class reference).
- `publish()` was called only by the 2 migrated tests.
- No interface, no `call_user_func` / variable method / `->call` / `dispatch`, no route
  method-string, no config `'publish'` binding to this action.

## Preserved invariants (todo: progress event order + error mapping must stay stable)

- Step order: resolve_node → check_router_ingress → ensure_credentials →
  ensure_private_route → ensure_backend_pool → publish_ingress → verify_intent.
- Frame types tree/step/complete/error; status running/done/failed.
- Error code→status: validation_failed 422, authorization_failed 403,
  proxy.domain_conflict 409, s3.publish_failed 500.
- Preflight-before-mutation and idempotent re-publish preserved. Controller + routes
  untouched. S3UnpublishAction untouched (separate).

Result: complete — evidence=Explore duplication sweep + diff review; publish() deleted
with unreachability re-verified; publishWithProgress is the single engine; 113 S3 tests
green.
