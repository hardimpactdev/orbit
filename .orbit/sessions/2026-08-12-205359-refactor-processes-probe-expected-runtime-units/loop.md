# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-processes-probe-expected-runtime-units
- Branch: refactor/processes-probe-expected-runtime-units

## Goal

Give process probing one source of truth for runtime context and expected runtime units without changing rendered units, ordering, placement, or Doctor issues.

## Scope

- Owned: shared process execution-node, runtime-app, and workspace-context resolution; expected runtime unit names and specifications for Docker, Docker Swarm, systemd, and launchd; focused direct tests; ProcessesProbe delegation.
- Constraints: preserve runtime selection, owner and workspace context order, placement rules, rendered content hashes, Docker configured-hash fallback, launchd platform error, and every current caller position.
- Out of scope: live runtime observation, drift comparison rules, process restore behavior, node-wide container inventory, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 171 tests, 1216 assertions; scoped Mago lint and analyze passed
  - broader: passed - composer quality-check; evidence `.orbit/quality-gates/profiles/2026-08-12T18-47-01Z-2d905f7ebc92/gateway_pest.junit.xml`
  - runtime: passed - candidate=2d905f7ebc92738bb75801957b13cd5ae8c34797; venue=retained-incus; environment=dev-fixture; target=app-dev-1 process family; expected=exit 0 healthy true zero issues; observed=exit 0 healthy true zero issues; result=passed; evidence=`.orbit/evidence/process-runtime-units-retained-incus.json`
- Blast radius: not-required - internal process-probe extraction; repository search found no production direct construction outside Laravel container wiring
- Review: passed - human-judgment=not-required - Claude Opus exact-commit general review found no blocking issues
- Reviewed feature tip: 2d905f7ebc92738bb75801957b13cd5ae8c34797
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2d905f7ebc92738bb75801957b13cd5ae8c34797
- Accepted main tip: a0c19d169b712d79c8098d81718754945d083d06

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
