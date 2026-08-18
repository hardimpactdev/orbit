# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i20-b-remove-unrefer--774`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-774-openapi-envelope-cleanup`
- Branch: `solo-774-openapi-envelope-cleanup`

## Goal

The gateway OpenAPI schema no longer defines the dead, unreferenced `OrbitSuccessEnvelope`
and `OrbitErrorEnvelope` components. No operation response references them, and the success
component omits the actual outer success key — so they generate unused SDK types and imply a
false response contract. Removing them (and regenerating the schema + TS SDK) leaves the
concrete per-operation responses as the sole shape authority, with no reference or generated
type remaining.

## Scope

- Owned: `apps/gateway/app/Support/OpenApi/GatewayOpenApi.php` — remove the
  `OrbitSuccessEnvelope` + `OrbitErrorEnvelope` component registrations and their builder
  helpers (declarations ~52-57, build block ~87-137 incl. the ~90/~95 add calls). Remove the
  two existence assertions in `apps/gateway/tests/Feature/OpenApiSchemaContractTest.php`
  (~160 OrbitSuccessEnvelope, ~163 OrbitErrorEnvelope). Regenerate the exported schema + TS
  SDK (`composer --working-dir=apps/gateway openapi:export`, then `npm run generate` from
  packages/sdk-typescript) so the envelope entries DISAPPEAR from
  `packages/sdk-typescript/openapi/public-gateway-openapi.json`, `src/generated/schema.ts`,
  and `dist/generated/schema.d.ts` — commit the regenerated artifacts + refreshed
  `.orbit/evidence/typescript-sdk-public-openapi-summary.json`. Prune the 2 now-stale
  `mago-linter-baseline.toml` entries naming the components only if Mago requires it.
- Constraints: keep ALL other schema components + concrete operation responses (shape
  authority) untouched; no dual definitions/aliases. `rg
  'OrbitSuccessEnvelope|OrbitErrorEnvelope' apps/gateway packages/sdk-typescript` must return
  nothing after regen. declare(strict_types=1); Mago/Rector clean. Do NOT run composer
  test:e2e*.
- Out of scope: any concrete operation response, other schema components, the OpenAPI export
  pipeline itself, and the SDK generation config.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/OpenApiSchemaContractTest.php` (1 test, 84 assertions); `rg 'OrbitSuccessEnvelope|OrbitErrorEnvelope' apps/gateway packages/sdk-typescript` empty
  - broader: passed - `composer quality-check` on clean commit `fc01b23d58b76a72d9da394a68727f3aab89ba0f` exit 0, dirty false, 45/45 subgates zero, Mago/Rector clean (`.orbit/quality-gates/quality-check-2026-08-18T050108Z-c07a9e6a5ad2.json`)
  - runtime: passed - candidate=fc01b23d58b76a72d9da394a68727f3aab89ba0f; venue=retained-incus; environment=dev-fixture; expected=OpenApiSchemaContractTest green in retained operator VM after removing the unreferenced OrbitSuccessEnvelope/OrbitErrorEnvelope components and their existence assertions, no envelope reference or generated type remaining; observed=1 passed 84 assertions no failures; result=passed; command=ssh beast incus exec orbit-e2e-dev-e8ab86-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/774-test.sqlite && touch /tmp/774-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Feature/OpenApiSchemaContractTest.php --compact'; evidence=`.orbit/evidence/solo-774-retained-incus-proof.md`
- Blast radius: complete - evidence=GatewayOpenApi read + rg sweep (default + -uu) + OpenAPI export + TS-SDK regen + full gateway Pest + quality-check; result=both envelope components + builders + existence assertions removed, SDK regenerated without the types, no reference or generated type remains, concrete operation responses unchanged (`.orbit/evidence/solo-774-blast-radius-inventory.md`)
- Review: passed - independent Claude reviewer (2511) VERDICT PASS, all 5 checks confirmed; human-judgment=not-required
- Reviewed feature tip: fc01b23d58b76a72d9da394a68727f3aab89ba0f
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: fc01b23d58b76a72d9da394a68727f3aab89ba0f
- Accepted main tip: 00d201c46c4a6f1731b502361d7abd80d5444040

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
