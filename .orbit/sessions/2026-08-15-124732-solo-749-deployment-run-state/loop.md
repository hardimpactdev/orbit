# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p10-a-make-deploymen--749`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-749-deployment-run-state`
- Branch: `solo-749-deployment-run-state`

## Goal

DeploymentRun is the sole durable source for each instance's latest deployment state, with deterministic latest-run reads, stale completion protection, no Instance shadow columns, and unchanged API output semantics.

## Scope

- Owned: `apps/gateway` Instance and DeploymentRun relationships, deployment lifecycle service, instance API payload reader, forward migration, focused gateway tests, and matching `apps/docs/content` deployment/app authority; primitive=latest deployment run; transitions=success:newest run becomes completed|failure:newest run becomes failed|retry:terminal completion is idempotent|stop-restart:durable run remains queryable|stale:older run completion cannot replace the newest run
- Constraints: preserve deterministic `created_at` then `id` ordering, current public `latest_deployment_*` payload keys, failed-run visibility, query-efficient eager loading, and safe migration equivalence; never run `composer test:e2e*`
- Out of scope: deploy CLI behavior, operation streaming, deployment step execution, release/deployment work, and unrelated instance state

## Proof

- Verification:
  - focused: passed - TDD red captured; focused gateway slice 53 tests / 279 assertions passed; full gateway suite 6,263 tests / 51,536 assertions passed with 4 skipped; docs lint passed with 0 errors; changed PHP files pass Mago format; forward migration applied successfully
  - broader: passed - `composer quality-check` passed all routed subgates for candidate 1eedb03995e7054c9ec8c5ee34360952be930963; evidence=`.orbit/quality-gates/quality-check-2026-08-15T104053Z-81b9756c238e.json`
  - runtime: passed - candidate=1eedb03995e7054c9ec8c5ee34360952be930963; venue=retained-incus; environment=dev-fixture; target=beast/orbit-e2e-dev-b6ede4-gateway:/home/orbit/orbit-run; expected=latest-run ownership behavior passes and Instance shadow columns are absent; observed=53 tests with 279 assertions passed and the migrated gateway schema reported both shadow columns absent; result=passed; evidence=`.orbit/evidence/solo-749-retained-incus.txt`
- Blast radius: complete - evidence=bounded repository-wide inventory of removed column names, latestDeploymentRun, lifecycle usage, and latest-deployment authority prose; result=no active shadow-column reader or contradictory authority text remains
- Review: passed - human-judgment=not-required - no actionable findings
- Reviewed feature tip: 1eedb03995e7054c9ec8c5ee34360952be930963
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 1eedb03995e7054c9ec8c5ee34360952be930963
- Accepted main tip: 1c8a97214e576fe6d89c35cb54582a04272a83d0

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
