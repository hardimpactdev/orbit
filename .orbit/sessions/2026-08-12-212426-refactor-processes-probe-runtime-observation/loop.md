# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 56
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-processes-probe-runtime-observation`
- Branch: `refactor/processes-probe-runtime-observation`

## Goal

Move Docker, Docker Swarm, systemd, and launchd observation out of `ProcessesProbe` without changing `ProbeSnapshot` data, command order, hibernation handling, or Doctor issues.

## Scope

- Owned: runtime selection, current runtime observation, generated probe scripts, Docker hibernation annotation, unrenderable runtime snapshots, direct tests, and `ProcessesProbe` delegation.
- Constraints: preserve each command and its order, preserve missing-unit and backend-failure behavior, use constructor injection, and keep one clear owner for each observation rule.
- Out of scope: expected runtime-unit construction, drift comparison rules, WireGuard self-route drift, restore, adopt, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 59 process observation and architecture tests, 295 assertions
  - broader: passed - full monorepo quality check; gateway Pest receipt=`.orbit/quality-gates/profiles/2026-08-12T19-17-54Z-9644168e724d/gateway_pest.junit.xml`
  - runtime: passed - candidate=9644168e724d1e1364a1d72edf312adf4c9aaf02; venue=retained-incus; environment=dev-fixture; expected=Doctor reads current process runtime state and reports healthy; observed=app-dev-1 process family healthy with zero issues; result=passed; evidence=`.orbit/evidence/process-runtime-observation-retained-incus.json`; command=orbit doctor --node=app-dev-1 --family=process --json
- Blast radius: complete - evidence=repository-wide search; result=all four `ProcessRuntime` cases are mapped, old observation methods remain only as forbidden architecture-test strings, and no new observer uses `app()`
- Review: passed - Claude Opus found no actionable issues; human-judgment=not-required
- Reviewed feature tip: 9644168e724d1e1364a1d72edf312adf4c9aaf02
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9644168e724d1e1364a1d72edf312adf4c9aaf02
- Accepted main tip: 03ee4415ad22b3211ff3cea200c22e82e6bb63b9

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
