# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/round-6-docs-drift-r--468`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-zero-round-6`
- Branch: `codex/docs-drift-zero-round-6`

## Goal

Align workspace Instance ownership, PHP runtime-unit ownership, and node
installation guidance with current product authority.

## Scope

- Owned: `apps/docs/content/domains/{1_node,6_workspace,14_php}/**`
- Constraints: documentation-only; preserve current behavior; do not run `composer test:e2e*`
- Out of scope: `docs/superpowers/**`, `apps/docs/content/porting/**`, product code changes, unrelated cleanup

## Proof

- Verification:
  - focused: passed - Librarian reference lint has 0 issues; targeted stale ownership, inheritance, launcher, and cross-reference searches have 0 matches
  - broader: passed - `composer docs-lint` has 0 errors and no warning increase (228/5/0 baseline groups); receipt `.orbit/quality-gates/docs-lint-2026-08-20T135319Z-c0e75b9188fe.json`
  - runtime: not applicable
- Blast radius: complete - evidence=full docs lint plus repository-wide searches across workspace, PHP, node, and installation contracts; result=no remaining Round 6 stale ownership or routing wording and generated catalogs remain current
- Review: passed - human-judgment=not-required; reviewer=`solo://proj/106/scratchpad/general-review-docs--469`
- Reviewed feature tip: 6bdff303f718a69546b8687df44ae5829e5bfe94
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6bdff303f718a69546b8687df44ae5829e5bfe94
- Accepted main tip: 8bfac42bffddcab74d66d8b696250c0496721791

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
