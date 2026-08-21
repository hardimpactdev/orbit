# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/round-5-docs-drift-r--458`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-zero-round-5`
- Branch: `codex/docs-drift-zero-round-5`

## Goal

Align the Proxy Remove ownership boundary, Tool Doctor batch-probe contract,
and node PHP toolchain summaries with current product authority.

## Scope

- Owned: `apps/docs/content/domains/{1_node,3_tool,8_proxy}/**`
- Constraints: docs plus one aligned CLI renderer fixture; preserve current code behavior; do not run `composer test:e2e*`
- Out of scope: `docs/superpowers/**`, `apps/docs/content/porting/**`, product behavior changes

## Proof

- Verification:
  - focused: passed - Librarian reference lint has 0 issues; targeted stale-term searches have 0 matches; `ProxyWriteCommandTest.php` has 14 passing tests and 64 assertions
  - broader: passed - `composer quality-check` passed all repository units for candidate `20055a0c94add53c458aea017ea2b6e8e1330649`; receipt `.orbit/quality-gates/quality-check-2026-08-20T131719Z-153b667cc658.json`; `composer docs-lint` has 0 errors and no warning increase (228/5/0 baseline groups)
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide ownership-language search plus code-path inventory; result=zero residual broad missing-owner removal claims and the removable path remains structurally complete missing tool ownership only
- Review: passed - human-judgment=not-required; reviewer=`solo://proj/105/scratchpad/round-5-docs-drift-r--461`
- Reviewed feature tip: 20055a0c94add53c458aea017ea2b6e8e1330649
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 20055a0c94add53c458aea017ea2b6e8e1330649
- Accepted main tip: c4119b76018b177420a04cee7f500ecd5ed0327e

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
