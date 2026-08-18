# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i20-a-remove-duplicat--770`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-770-openapi-surface-operation-id`
- Branch: `solo-770-openapi-surface-operation-id`

## Goal

The OpenAPI SDK surface manifest (`apps/gateway/openapi-sdk-surface.json`) no longer carries
the dead, unvalidated `operation_id` field: `operation` (method+path) is the sole entry
identity. Nothing reads the manifest's `operation_id` (the filter + contract test key on
`operation`; the `operationId` they read is from the EXPORTED spec, never the manifest), and
four `appInstance.*` values had already drifted stale precisely because nothing validates the
field — so removing it disposes of dead metadata with no downstream effect.

## Scope

- Owned: delete the `"operation_id": …` line from ALL 74 entries in `schema_only_operations`
  of `apps/gateway/openapi-sdk-surface.json` (leave `operation`, `classification`, `group`,
  `reason`, `follow_up`, and top-level metadata untouched). In
  `apps/gateway/tests/Feature/OpenApiSdkSurfaceContractTest.php` Test 1 only, remove the three
  fixed cardinality pins (schemaOperations count 174, schemaOnlyOperations count 74, and the
  `['internal_only'=>14,'deferred_optional'=>37,'public_sdk'=>23]` aggregate-count assertion).
- Constraints: KEEP duplicate detection (`->duplicates()`), bidirectional coverage (every
  schema-only op classified + every classified op still in the schema), the internal
  classification set, public follow-up checks, and ALL of Test 2 (asserts the EXPORTED
  schema's operationId, not the manifest). NO filter change
  (`packages/sdk-typescript/scripts/filter-public-openapi.mjs` keys on `operation`, reads
  only the spec's operationId). Regenerated public TS schema + summary must stay byte-
  identical (`git diff` clean for public-gateway-openapi.json, schema.ts, and the summary) —
  removing operation_id changes nothing downstream. declare(strict_types=1); Mago/Rector
  clean. Do NOT run composer test:e2e*.
- Out of scope: any change to `operation`, classification sets, the filter, the exported
  schema contract, or Test 2; the dangling `$schema` line 2 (leave unless removal is
  confirmed clean).

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/OpenApiSdkSurfaceContractTest.php` 2 passed 395 assertions; openapi:export + `npm run generate` leave public-gateway-openapi.json, schema.ts, and typescript-sdk-public-openapi-summary.json byte-identical (classified set unchanged 123/123)
  - broader: passed - `composer quality-check` on clean commit `50ab6aa8aaf4cdf13e5e38d686af5c9144bc77f1` exit 0, dirty false, 45/45 subgates zero, Mago/Rector clean (`.orbit/quality-gates/quality-check-2026-08-18T034826Z-6d07802afa62.json`)
  - runtime: passed - candidate=50ab6aa8aaf4cdf13e5e38d686af5c9144bc77f1; venue=retained-incus; environment=dev-fixture; expected=OpenApiSdkSurfaceContractTest green in retained operator VM after removing dead manifest operation_id + cardinality pins with classification/coverage/duplicate + exported-schema operationId checks intact; observed=2 passed 395 assertions 0 failures; result=passed; command=ssh beast incus exec orbit-e2e-dev-fb7708-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/770-test.sqlite && touch /tmp/770-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Feature/OpenApiSdkSurfaceContractTest.php --compact'; evidence=`.orbit/evidence/solo-770-retained-incus-proof.md`
- Blast radius: complete - evidence=grep/rg sweep + downstream-reader audit + scoped 2-file diff + full gateway Pest + quality-check; result=operation_id removed from all 74 entries, no reader affected, TS SDK regen byte-identical, cardinality pins removed with duplicate/coverage/internal checks intact (`.orbit/evidence/solo-770-blast-radius-inventory.md`)
- Review: passed - independent Claude reviewer (2505) VERDICT PASS, all 5 checks confirmed; human-judgment=not-required
- Reviewed feature tip: 50ab6aa8aaf4cdf13e5e38d686af5c9144bc77f1
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 50ab6aa8aaf4cdf13e5e38d686af5c9144bc77f1
- Accepted main tip: 0a48a5782fc245d0ba3b71d743701fff7ccc2511

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
