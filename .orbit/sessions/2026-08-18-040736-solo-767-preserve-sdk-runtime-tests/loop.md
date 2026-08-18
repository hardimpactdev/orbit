# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i16-a-preserve-types--767`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-767-preserve-sdk-runtime-tests`
- Branch: `solo-767-preserve-sdk-runtime-tests`

## Goal

The prepared TypeScript SDK package no longer silently weakens its own release validation: `bin/orbit-prepare-release-package` stops hard-replacing the whole `scripts` map with a typecheck-only `test`, and instead preserves the source scripts minus the generation-only commands — so the copied runtime tests (`tests/*.test.ts`) actually run when the publish workflow executes `npm test`. `test` regains `npm run typecheck && npm run test:runtime`; `build`, `typecheck`, `test:runtime` (and harmless `pack:dry-run`) are preserved; only `generate`/`generate:openapi`/`generate:types` are dropped.

## Scope

- Owned: `bin/orbit-prepare-release-package` (the `rewrite_npm_package_json` scripts-map construction ~lines 305-309 — replace the 3-entry hard literal with a preserve-source-minus-generation approach: `$scripts = $json['scripts'] ?? []; unset($scripts['generate'], $scripts['generate:openapi'], $scripts['generate:types']); $json['scripts'] = $scripts;`), and `apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php` (~lines 562-565 — change the prepared-scripts assertion from the typecheck-only 3-key map to the preserved map incl. test:runtime and `test = 'npm run typecheck && npm run test:runtime'`, and assert the generate* keys are ABSENT; optionally strengthen the publish-workflow assertion ~373-374 that runtime tests are exercised).
- Constraints: keep `build` (publish.yml needs dist) and `typecheck`; DROP only the three generation-only commands (generate/generate:openapi/generate:types — they need the gateway/composer checkout or regenerate source). The publish workflow `packages/sdk-typescript/.github/workflows/publish.yml` needs NO change (npm test now runs typecheck AND runtime automatically); optionally rename the ":32 Typecheck" step label to "Test" for accuracy. Preserve the copy step + should_skip dir filter (tests/src still copied). declare(strict_types=1) if any PHP; Mago/Rector clean. Do NOT run composer test:e2e*. TS SDK = packages/sdk-typescript (NOT packages/sdk which is the Laravel PHP SDK).
- Out of scope: the runtime test files themselves; the SDK's actual source/generated types; npm publish credentials/workflow beyond the scripts map; any packages/sdk (PHP) change.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Release` (40 passed, 761 assertions)
  - broader: passed - `composer quality-check` on the clean candidate eb08a40d exit 0, all 45 subgates zero, git.dirty false, receipt `.orbit/quality-gates/quality-check-2026-08-18T020438Z-2c00867225ea.json`
  - runtime: not applicable
- Blast radius: complete - evidence=Explore trace of the scripts-map rewrite + diff review (2 files); result=bin/orbit-prepare-release-package preserves source scripts minus the three generation-only commands so the prepared package's runtime tests run under `npm test`, OrbitReleaseWorkflowTest assertion updated; publish.yml + packages/sdk (PHP) untouched; Grok proved the prepared-package runtime tests execute (npm run test:runtime 17/17); see `.orbit/evidence/solo-767-blast-radius-inventory.md`
- Review: passed - human-judgment=not-required; independent Claude reviewer VERDICT PASS (bin/orbit-prepare-release-package preserves source scripts minus only generate/generate:openapi/generate:types so the prepared package's runtime tests run under npm test; test regains npm run typecheck && npm run test:runtime; OrbitReleaseWorkflowTest assertion updated non-hollow; publish.yml + packages/sdk PHP untouched; build/typecheck preserved; quality eb08a40d 45/45 zero dirty false)
- Reviewed feature tip: eb08a40d83676739cfb3d526edb134bc7ba82559
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: eb08a40d83676739cfb3d526edb134bc7ba82559
- Accepted main tip: 9ba473cb613f3f06bf1941ace148073e59fd3362

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
