# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://todo/747`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-747-instance-bindings`
- Branch: `solo-747-instance-bindings`

## Goal

Make Instance the sole analytics and WebSocket binding authority so sibling instances can hold different bindings, legacy rows backfill only for an unambiguous sole instance, and API reads and writes serialize the selected instance binding.

## Scope

- Owned: `apps/gateway` binding migrations, models, factories, services, controllers, resources, and focused Pest coverage; binding references in `apps/docs/content` only if current product docs require correction.
- Constraints: Add `instance_id`; fail migration closed for zero or multiple legacy instance candidates; enforce one binding per instance; remove App binding ownership without a dual-read end state; preserve unrelated parent checkout state; use no `composer test:e2e*` command.
- Out of scope: CLI command redesign, analytics or WebSocket runtime provisioning changes, releases, deployments, origin pushes, and live-node verification.

## Proof

- Verification:
  - focused: passed — candidate `9794fbd4e79edd64336cff13394a8e64d56e8ebd`; binding suite 82 tests, 443 assertions; E2E runtime-user regression 1 test, 4 assertions; readiness file 14 tests, 113 assertions.
  - broader: passed — `composer quality-check`; candidate `9794fbd4e79edd64336cff13394a8e64d56e8ebd`; exit 0 in 130 seconds; evidence `.orbit/quality-gates/quality-check-2026-08-15T134029Z-5079232c1f89.json`.
  - runtime: pending — the exact prepared websocket bake passed with transient GHCR auth and scrubbed snapshots prove zero credentials, but retained acquisition rebakes websocket on an unauthenticated clone and fails at `ensureManifestRuntimeImage`; todo #775 owns the release-artifact blocker and #747 is linked to it; evidence `.orbit/evidence/747-incus-artifact-plan.txt`.
- Blast radius: pending
- Review: pending
- Reviewed feature tip: none
- Acceptance venue: retained-incus
- Acceptance: pending
- Accepted feature tip: none
- Accepted main tip: none

## Status

- State: blocked
- Blocker: Todo #775 owns the linked release-artifact defect: retained acquisition always reconverges websocket after cloning the scrubbed app-dev snapshot, and `image:ensure` performs a fresh pull from private GHCR after credentials are correctly removed. Candidate `9794fbd4e79edd64336cff13394a8e64d56e8ebd` is clean and fully quality-checked. Todo #747 still owns cleanup of candidate namespace `solo-747-12de6e0f`, its staged gateway image, and the repository-defined `incusbr0` FORWARD rules after retained proof. No reviewer, acceptance, LAND, release, deployment, or push has run.

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
