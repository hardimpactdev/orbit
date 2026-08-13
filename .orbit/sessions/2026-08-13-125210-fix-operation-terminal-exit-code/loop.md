# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/orbit-stabilization--416`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-operation-terminal-exit-code`
- Branch: `fix/operation-terminal-exit-code`

## Goal

Terminal operation results use `exit_code` consistently, so a completed operation with failed targets cannot be reported or stored as success.

## Scope

- Owned: shared terminal success classification; CLI human, JSON, and stream-JSON output; gateway `OperationRun` persistence; focused tests; operation docs; primitive=operation terminal outcome; transitions=success:`complete` with zero or absent exit code|failure:`error` or `complete` with nonzero exit code|retry:unchanged journal replay|stop-restart:unchanged cursor resume|stale:unchanged duplicate suppression
- Constraints: Preserve the terminal frame type and full result payload. Treat a missing `exit_code` as zero for compatibility. Treat every `error` frame as failure.
- Out of scope: emit-side frame changes, follower retry or timeout behavior, node removal, and human-only E2E suites.

## Proof

- Verification:
  - focused: passed - Core terminal contract, SDK transport, CLI stream rendering, `update:all`, Doctor, gateway stream persistence, Mago, and scoped Librarian checks
  - broader: passed - `composer quality-check` candidate `f76603756c75b743f72e133f9ad0713b0d908508`, artifact `.orbit/quality-gates/quality-check-2026-08-13T104811Z-c6db2d17eedd.json`
  - runtime: passed - candidate=f76603756c75b743f72e133f9ad0713b0d908508; venue=retained-incus; environment=dev-fixture; command=orbit tool:update in human and stream-json modes plus durable operation processing; expected=complete exit 1 stays a complete event, exits 1, preserves partial result and footer, and stores a non-success terminal state; observed=human output showed the gateway footer, stream JSON emitted operation_failed with the full target result, and the operation run stored a non-success status with exit 1 and intact result; result=passed; evidence=`.orbit/evidence/operation-terminal-exit-code-retained-incus.json`
- Blast radius: complete - evidence=repository-wide search for terminal `complete` classification and `exit_code` consumers; result=shared rule applied to Core, SDK transport, CLI shared and command-specific renderers, and gateway persistence
- Review: passed - Claude Opus Solo process 2387 found no findings and confirmed uniform terminal classification, preserved result data, complete blast radius, and sufficient exact-candidate proof; human-judgment=not-required
- Reviewed feature tip: f76603756c75b743f72e133f9ad0713b0d908508
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f76603756c75b743f72e133f9ad0713b0d908508
- Accepted main tip: 5d5346dfa956fc6916f2a7222e0bdb9e8bc088b7

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
