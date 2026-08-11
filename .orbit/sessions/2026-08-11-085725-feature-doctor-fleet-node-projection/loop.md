# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-11-doctor-fleet-node-projection-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/feature-doctor-fleet-node-projection`
- Branch: `feature/doctor-fleet-node-projection`

## Goal

Extract fleet node and issue projection from `DoctorReportRunner` while preserving the existing Doctor fleet report shape and stable target ordering.

## Scope

- Owned: `apps/gateway/app/Services/Doctor/DoctorFleetNodeProjection.php`, `apps/gateway/app/Services/Doctor/DoctorReportRunner.php`, focused Doctor Pest tests, and this loop packet.
- Constraints: Preserve verify-only fleet scope, exact node summary fields and defaults, complete active roles, issue filtering, target-index ordering, and all public runner methods. Use test-first development. Product docs already describe the required behavior and need no change.
- Out of scope: Fleet worker orchestration, subprocess contracts, progress report shaping, family selection, issue catalog payload construction, summary consolidation, restore convergence, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Doctor` returned 150 tests and 1,548 assertions; scoped Mago format, lint, and analysis passed
  - broader: passed - `composer quality-check` passed every monorepo lane after `npm ci` restored the worktree's missing locked TypeScript toolchain; the first run had only `tsc: command not found`
  - runtime: passed - candidate=ef8959d474e6b5fe740c7455e71c9ed5dbd3abf9; venue=retained-incus; environment=dev-fixture; command=./apps/cli/orbit doctor --all --family=node --json twice on topology dev-f7e6b7; expected=exact node summary fields complete ordered roles array-only issues and stable node and issue ordering; observed=both reports returned gateway with roles gateway router vpn exact fields and the same node and issue-key order; result=passed; evidence=`.orbit/evidence/doctor-fleet-node-projection-retained-incus.md`
- Blast radius: not-required - pure internal extraction; bounded repository search found no positional DoctorReportRunner construction, no stale removed-helper references, and no consumer beyond the runner and focused tests; the Doctor report schema and public runner boundary are unchanged
- Review: passed - Claude Opus in Solo process 2276; no actionable findings; behavioral equivalence, DI safety, tests, scope, and retained proof confirmed; human-judgment=not-required
- Reviewed feature tip: ef8959d474e6b5fe740c7455e71c9ed5dbd3abf9
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ef8959d474e6b5fe740c7455e71c9ed5dbd3abf9
- Accepted main tip: 5ac44d9c223af1472fbd9006bafa140113a06ad8

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
