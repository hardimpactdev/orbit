# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-node-family-resolver-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-node-family-resolver`
- Branch: `refactor/doctor-node-family-resolver`

## Goal

Move the rules that decide which Doctor checks apply to each node out of
DoctorReportRunner without changing Doctor behavior.

## Scope

- Owned: Doctor node family resolution, expected schedule targeting, runner delegation, and scope validation.
- Constraints: Preserve public Doctor request, response, family, and ordering contracts.
- Out of scope: Probe execution, report shaping, node placement, controllers, and product behavior changes.

## Proof

- Verification:
  - focused: passed - 244 Doctor and API tests, 1,979 assertions; scoped Mago format and analysis passed
  - broader: passed - composer quality-check at 05032b16047e0794bc7ed07c20657b5653996d85
  - runtime: passed - candidate=05032b16047e0794bc7ed07c20657b5653996d85; venue=retained-incus; environment=dev-fixture; command=Orbit Doctor node and fleet family scope; expected=preserved family acceptance rejection and fleet selection; observed=process and schedule scopes resolved correctly with stable errors and target order; result=passed; evidence=`.orbit/evidence/doctor-node-family-incus-proof.json`
- Blast radius: complete - evidence=repository-wide search for DoctorScopeValidator and node-family decision callsites; result=all callers use the resolver or runner delegators
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 05032b16047e0794bc7ed07c20657b5653996d85
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 05032b16047e0794bc7ed07c20657b5653996d85
- Accepted main tip: f9135a337e265c1a0f391351079efe5c15d71f40

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
