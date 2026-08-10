# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Operation Stream Contract Implementation Plan — 2026-08-09 (Solo scratchpad 423)
- Worktree: /Users/nckrtl/orbit/.worktrees/stabilize-operation-stream-contract
- Branch: stabilize-operation-stream-contract

## Goal

Make every gateway and CLI producer and consumer use one shared operation stream frame contract, while preserving the existing JSON exactly and storing each operation event journal cursor atomically with its event.

## Scope

- Owned: `packages/core/src/Operations/**`, `packages/core/tests/Operations/**`, `packages/core/tests/Fixtures/Operations/**`, gateway operation stream publishers/recorders/controllers and focused tests, CLI operation stream publishers/subscribers and focused tests, `apps/docs/content/architecture.md`, and `apps/docs/content/domains/11_operation/operation-concepts.md`; primitive=operation stream frame persistence; transitions=success:valid frame is persisted and published with its cursor|failure:invalid frame or failed cursor promotion leaves no incomplete journal event|retry:unique-cursor collisions retry as one transaction|stop-restart:subscriber resumes after the last durable cursor|stale:legacy cursorless frames are accepted only during journal replay
- Constraints: Preserve the existing wire fields and values exactly, including `source` for gateway frames, `source_node` for node frames, `operation.stream.frame` for live events, and `operation_stream.frame` in the operation event journal.
- Out of scope: Wire renames, new frame types, database migrations, transport replacement, unrelated large-file extraction, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - operation frame core 14 tests/26 assertions; forked ticker regressions 10 tests/42 assertions; gateway 32 tests/258 assertions; CLI 16 tests/48 assertions; docs lint passed
  - broader: passed - exact clean candidate passed all 45 repository quality subgates including concurrent gateway Pest CLI Pest and core Pest; evidence=`.orbit/quality-gates/quality-check-2026-08-10T083540Z-0853aace477a.json`
  - runtime: passed - candidate=49a120e5c3a8084d3c675d4a63696d6704d535d0; venue=retained-incus; environment=dev-fixture; target=dev-607eb3; expected=app-prod fixture deploy streams to completion and every saved frame has a complete matching journal cursor; observed=app-prod fixture deploy returned frame-contract-ok with exit code 0 and all 10 operation event journal frames had matching operation UUID journal sequence and journal row ID; result=passed; evidence=`.orbit/evidence/operation-stream-retained-incus-2026-08-10-49a120e5c3a8.json`
- Blast radius: complete - evidence=repository-wide operation stream producer consumer journal and pcntl_fork inventories plus shared-contract and ticker regression tests; result=no unmigrated operation stream path and no other production fork site shares the fixed parent-stop race
- Review: passed - Claude Opus independently confirmed the signal-mask fix closes the inherited-handler race, the regression tests bind to it, the operation stream contract remains correct, and both exact-candidate proofs pass; human-judgment=not-required; evidence=Solo scratchpad 424 revision 7
- Reviewed feature tip: 49a120e5c3a8084d3c675d4a63696d6704d535d0
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 49a120e5c3a8084d3c675d4a63696d6704d535d0
- Accepted main tip: 9d75ac0f9e2179795b78f63b9b301ae88531ca4a

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
