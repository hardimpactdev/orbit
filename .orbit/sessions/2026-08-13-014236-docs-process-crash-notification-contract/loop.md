# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: User-approved Orbit stabilization sequence in this Codex thread
- Worktree: /Users/nckrtl/orbit/.worktrees/docs-process-crash-notification-contract
- Branch: docs/process-crash-notification-contract

## Goal

Active process documentation says that `crash_notification=none` is a retained
compatibility field and no longer claims that Orbit installs or repairs process
crash notifier material.

## Scope

- Owned: Active process-domain documentation, its generated command catalog/indexes, and a focused documentation regression check.
- Constraints: Preserve the public `--crash-notification=none` field and durable process-event/status compatibility; align with the existing 2026-08-04 product decision; do not add a new product decision.
- Out of scope: Runtime code, API/CLI schema changes, deleting the compatibility field, or restoring crash hooks and external notification delivery.

## Proof

- Verification:
  - focused: passed - docs suite 179 tests / 11,689 assertions; focused documentation contract 1 test / 10 assertions; `composer docs-lint` passed with no errors
  - broader: passed - `composer quality-check` at exact candidate; evidence `.orbit/quality-gates/quality-check-2026-08-12T233938Z-4bee26df3676.json`
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide active-doc and non-vendor code search; result=no remaining crash-hook install/repair claim, no production crashed-event writer, the crashed read mapping remains, and generated docs plus PRODUCT_DECISIONS are unchanged
- Review: passed - Claude Opus Solo project 65/process 2343; no actionable findings; human-judgment=not-required; VERDICT=PASS
- Reviewed feature tip: 7a3fe0940c56d2931af333cd95b8cd4a71f17855
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7a3fe0940c56d2931af333cd95b8cd4a71f17855
- Accepted main tip: 3fa7ce0b59f6e6c59d698bc8c7581a4213bd2be8

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
