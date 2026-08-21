# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/107/scratchpad/round-7-documentatio--477
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-7-docs
- Branch: codex/docs-drift-round-7-docs

## Goal

Resolve Round 7 documentation findings A1, B1, C1, C2, and C3 with every current canonical command technical contract declaring its activity behavior.

## Scope

- Owned: `apps/docs/content/**` files named in Round 7 reconciliation scratchpad 476.
- Constraints: Preserve product-authority order; do not run or delegate `composer test:e2e*`.
- Out of scope: `docs/superpowers/**`, `apps/docs/content/porting/**`, and Round 7 code follow-ups CF1 and CF2.

## Proof

- Verification:
  - focused: passed - inventory confirms 206 technical contracts have `## Activity Logging`; the sole canonical technical contract without it is non-command `process-event-stream`
  - broader: passed - `composer docs-lint` returned 0 errors; receipt `.orbit/quality-gates/docs-lint-2026-08-20T145009Z-fb51034e754d.json`
  - runtime: not applicable
- Blast radius: complete - evidence=205-command catalog inventory plus repository-wide activity-type format search; result=all commands have one Activity Logging section and 0 type tokens retain the noncanonical `/api/` prefix
- Review: passed - human-judgment=not-required; reviewer=`solo://proj/107/scratchpad/round-7-docs-drift-g--479`
- Reviewed feature tip: 0768be5e3f81371772b54e465e2bb8fe761f97c8
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0768be5e3f81371772b54e465e2bb8fe761f97c8
- Accepted main tip: 49e57bc2e8d09bf408e07415365361d2b852b140

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
