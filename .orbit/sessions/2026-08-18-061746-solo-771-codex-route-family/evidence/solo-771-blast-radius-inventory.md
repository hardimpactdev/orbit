# Todo 771 — Blast Radius Inventory

Candidate: `5ebbe301a29db8afb0026f6c09679ddc285b049d`
Base: `main` @ `c594b889e92460c0dae20d984be8914092bb8e5b`
Diff: 4 files changed, +11 / −4.

## Method

Git archaeology (regression source `39da694b1`), route/manifest/CLI cross-check, gateway +
CLI Pest, `composer quality-check`, plus `PRODUCT_DECISIONS.md` authority check.

## Result — Option A revert (product-authority-compliant)

- `apps/gateway/routes/api.php:280` — `Route::get('/codex/apps', …'list')` → `Route::get('/codex/projects', …'list')`.
  The `POST`/`DELETE /codex/apps/{project}` add/remove routes (281-282) are **unchanged**.
- `apps/gateway/openapi-sdk-surface.json` — `appCodex.list` `"operation"` `GET /codex/apps` →
  `GET /codex/projects` (mutations `POST`/`DELETE /codex/apps/{project}` operations unchanged).
- `apps/gateway/tests/Feature/Http/Api/CodexAppControllerTest.php:53` — NEW negative test:
  `GET /api/codex/apps` (the obsolete list path) → 404 (`assertNotFound()`).
- `apps/gateway/tests/Feature/ExcludedExternalProjectVocabularyGuardTest.php:50` — now PINS
  the GET list route line `Route::get('/codex/projects', …)` (the previously-unguarded line
  whose absence let the regression ship); :94 confirms CLI `gatewayGet('/api/codex/projects')`.

## Already aligned (confirmed, no change)

- CLI `apps/cli/app/Commands/Codex/CodexAppCommand.php` list call → `/api/codex/projects`;
  add/remove → `/api/codex/apps/{project}` (matches the gateway split). CLI test, guard, and
  technical doc already on `/codex/projects`.

## Product authority

- `PRODUCT_DECISIONS.md` (2026-08-05): Codex `codex:app` and its "project argument/routes/
  payloads … remain unchanged" and use "project" vocabulary. The list-on-`/codex/projects` +
  mutations-on-`/codex/apps/{project}` split is the DOCUMENTED, guard-enforced status quo.
  Reverting the regressed list route restores that contract. Full single-noun unification
  (Option B) would contradict the guard + "routes remain unchanged" and needs separate
  product sign-off — deliberately NOT done here.

## Verdict

BLAST_RADIUS: complete — evidence = git archaeology + route/manifest/CLI cross-check +
gateway & CLI Pest + `composer quality-check`; result = the regressed Codex list route
restored to `/codex/projects`, mutations unchanged on `/codex/apps/{project}`, manifest
operation aligned, obsolete `GET /api/codex/apps` list path now 404s, guard pins the list
route, CLI already aligned. The list/mutations noun split is intentional and guard-enforced.
