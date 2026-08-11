# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-11-gateway-node-provisioning-contract-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-gateway-node-provisioning-contract`
- Branch: `refactor/gateway-node-provisioning-contract`

## Goal

Gateway-owned `node:new` uses explicit create, prepare, resume, and complete
services with typed input and direct results, while preserving the documented
authorization, validation, lock, transaction, retry, Agent-push, and output
contracts.

## Scope

- Owned: `apps/gateway/app/Services/Nodes/**`, mapped gateway node provisioning tests, and stale `node:new` technical test mapping; primitive=managed workload bootstrap; transitions=success:pending bootstrap becomes one active node and completed bootstrap|failure:validation or convergence failure returns the documented error without an invalid terminal state|retry:compatible prepare and completion reuse the same durable bootstrap identity|stop-restart:interrupted pending work remains resumable through prepare or completion|stale:incompatible caller or request fails before mutation
- Constraints: Client-owned SSH only before prepare; gateway completion uses Agent push only; preserve reservation and completion locks, atomic database transitions, secret handling, JSON envelopes, progress updates, ordering, and one success activity.
- Out of scope: First-gateway CLI bootstrap, new `node:new` behavior, schema changes, release artifacts, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 30 gateway bootstrap and architecture tests, 222 assertions
  - broader: passed - `composer quality-check`; all apps and packages passed; gateway profile `.orbit/quality-gates/profiles/2026-08-11T19-20-54Z-96546744ca15/gateway_pest.junit.xml`
  - runtime: passed - candidate=96546744ca156ad12e2caef61260cfc2ab383ea7; venue=retained-incus; environment=dev-fixture; command=orbit node:new proof-invalid-app with invalid custom self-grant; expected=repeatable rejection before reservation; observed=both calls exited 1 with the same validation error and gateway counts nodes=0 bootstraps=0 peers=0; result=passed; evidence=`.orbit/evidence/gateway-node-provisioning-retained-incus-96546744.md`
- Blast radius: complete - evidence=independent repository-wide search of the reservation call site and grant-option mappings plus the full monorepo quality check; result=no unresolved provisioning surface
- Review: passed - independent reviewer found no critical or important issues after the grant-preflight fix - human-judgment=not-required
- Reviewed feature tip: 96546744ca156ad12e2caef61260cfc2ab383ea7
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 96546744ca156ad12e2caef61260cfc2ab383ea7
- Accepted main tip: 887789b4d61db322965ce85323f851aea7fe909f

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
