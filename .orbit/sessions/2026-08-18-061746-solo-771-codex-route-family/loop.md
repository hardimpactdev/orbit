# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p22-b-align-the-codex--771`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-771-codex-route-family`
- Branch: `solo-771-codex-route-family`

## Goal

The Codex list endpoint the shipped CLI calls (`GET /api/codex/projects`) resolves again
instead of 404ing. Commit 39da694b1 regressed ONLY the gateway list route
`GET /codex/projects` → `GET /codex/apps`, while the CLI, CLI test, docs, and guard stayed
on `/codex/projects` — so `orbit codex:app … list` 404s. Option A (product-authority-
compliant revert): restore the gateway LIST route to `/codex/projects`; the add/remove
mutation routes stay on `/codex/apps/{project}` (the documented, guard-enforced split).
Per PRODUCT_DECISIONS.md (2026-08-05): Codex `codex:app` project routes/payloads "remain
unchanged" and use "project" vocabulary.

## Scope

- Owned: `apps/gateway/routes/api.php` line ~280 `Route::get('/codex/apps', [CodexAppController::class, 'list'])`
  → `Route::get('/codex/projects', [CodexAppController::class, 'list'])` (leave the
  `POST`/`DELETE /codex/apps/{project}` add/remove routes UNCHANGED). Update
  `apps/gateway/openapi-sdk-surface.json` `appCodex.list` entry `"operation": "GET /codex/apps"`
  → `"GET /codex/projects"` (770 already removed operation_id, so the entry has only
  `operation`/classification/… — just fix the operation string). Add a NEGATIVE test in
  `apps/gateway/tests/Feature/Http/Api/CodexAppControllerTest.php` asserting the obsolete
  `GET /api/codex/apps` list path returns 404. Strengthen
  `apps/gateway/tests/Feature/ExcludedExternalProjectVocabularyGuardTest.php` to PIN the GET
  list route line `Route::get('/codex/projects', …)` (the currently-unguarded line whose
  absence let the regression ship).
- Constraints: CLI (`apps/cli/app/Commands/Codex/CodexAppCommand.php`), CLI test, guard, and
  the technical doc are ALREADY on `/codex/projects` — confirm, do NOT change. Mutations stay
  on `/codex/apps/{project}` (guard-enforced). Keep OpenApiSdkSurfaceContractTest green (the
  manifest operation must match the actual route). declare(strict_types=1); Mago/Rector clean.
  Do NOT run composer test:e2e*.
- Out of scope: Option B (unifying list + mutations onto one noun) — that contradicts the
  guard + "routes remain unchanged" and needs separate product sign-off. No SDK changes
  (Codex is deferred_optional, excluded from generation). No dual routes / aliases.

## Proof

- Verification:
  - focused: passed - CodexAppControllerTest (incl. new GET /api/codex/apps 404 test) + ExcludedExternalProjectVocabularyGuardTest + OpenApiSdkSurfaceContractTest green; `php artisan route:list --path=codex` shows GET /codex/projects; CLI CodexAppCommandTest green
  - broader: passed - `composer quality-check` on clean commit `5ebbe301a29db8afb0026f6c09679ddc285b049d` exit 0, dirty false, 45/45 subgates zero, Mago/Rector clean (`.orbit/quality-gates/quality-check-2026-08-18T041016Z-3cf52488a38d.json`)
  - runtime: passed - candidate=5ebbe301a29db8afb0026f6c09679ddc285b049d; venue=retained-incus; environment=dev-fixture; expected=Codex list route restored to /codex/projects with mutations unchanged on /codex/apps/{project}, obsolete GET /api/codex/apps list path 404s, guard pins the list route, all green in retained operator VM; observed=11 passed 70 assertions 0 failures; result=passed; command=ssh beast incus exec orbit-e2e-dev-40dc61-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/771-test.sqlite && touch /tmp/771-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Feature/Http/Api/CodexAppControllerTest.php tests/Feature/ExcludedExternalProjectVocabularyGuardTest.php --compact'; evidence=`.orbit/evidence/solo-771-retained-incus-proof.md`
- Blast radius: complete - evidence=git archaeology of regression 39da694b1 + route/manifest/CLI cross-check + gateway & CLI Pest + quality-check; result=Codex list route restored to /codex/projects, mutations unchanged on /codex/apps/{project}, manifest operation aligned, obsolete GET /api/codex/apps list path 404s, guard pins the list route, CLI already aligned (`.orbit/evidence/solo-771-blast-radius-inventory.md`)
- Review: passed - independent Claude reviewer (2507) VERDICT PASS, all 5 checks + receipt confirmed against the worktree candidate; human-judgment=not-required
- Reviewed feature tip: 5ebbe301a29db8afb0026f6c09679ddc285b049d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 5ebbe301a29db8afb0026f6c09679ddc285b049d
- Accepted main tip: c594b889e92460c0dae20d984be8914092bb8e5b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
