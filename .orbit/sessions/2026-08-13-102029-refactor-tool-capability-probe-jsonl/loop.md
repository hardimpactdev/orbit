# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/81/scratchpad/tool-capability-prob--432`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-tool-capability-probe-jsonl`
- Branch: `refactor/tool-capability-probe-jsonl`

## Goal

Make ordinary tool capability observations use one canonical JSONL contract, and report invalid probe evidence as blocked inspection instead of restorable missing capability.

## Scope

- Owned: single and batch ordinary capability shell/reply contract, invalid-reply classification, Tool Doctor issue catalog/docs, and contract tests.
- Constraints: Keep missing binaries as genuine capability absence; keep failed or malformed observations unverifiable; preserve dispatcher actions, ordered tool identity, special php-cli/docker-images probes, and managed-file probe ordering.
- Out of scope: tool installation behavior, special probe formats, public command shape, node transport, and unrelated Tool Doctor issue classification.

## Proof

- Verification:
  - focused: passed - contract, ToolsProbe, Doctor, API, convergence, and service-role suites
  - broader: passed - gateway 6221 tests, 51265 assertions, 4 skipped; docs lint passed
  - runtime: passed - candidate=2cfed577078d242cd2306d67ebd115f3ae4f5ca2; venue=retained-incus; environment=dev-fixture; target=app-dev-1 tool family; expected=single and batch observations use JSONL and malformed evidence stays distinct from verified absence; observed=Doctor healthy, six ordinary tools verified, malformed maps to blocked inspection, verified absence maps to missing; result=passed; evidence=`.orbit/evidence/tool-capability-jsonl-dev-485ef0.json`
- Blast radius: complete - evidence=repository-wide search and full gateway suite; result=ordinary single/batch consumers and stale probe fixtures covered
- Review: passed - Claude Opus general reviewer; human-judgment=not-required
- Reviewed feature tip: 2cfed577078d242cd2306d67ebd115f3ae4f5ca2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2cfed577078d242cd2306d67ebd115f3ae4f5ca2
- Accepted main tip: ed6ddce32c11a331cf302022daaf478b8711045f

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
