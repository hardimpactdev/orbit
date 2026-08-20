# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/110/scratchpad/round-8-docs-drift-f--487`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-8`
- Branch: `codex/docs-drift-round-8`

## Goal

Resolve the four reconciled Round 8 documentation findings so activity ownership, Workspace Doctor behavior, gateway service baselines, and the update cross-link match current authority.

## Scope

- Owned: the exact documentation files cited by reconciliation scratchpad 486 for A1, A2, A3, and C1
- Constraints: docs-only; preserve Librarian structure; never run `composer test:e2e*`
- Out of scope: code behavior, generated Librarian files, porting docs, and unrelated cleanup

## Proof

- Verification:
  - focused: passed - stale activity and Workspace repair claims absent; all required hibernator baseline references present; update link uses the existing heading anchor; `git diff --check` passed
  - broader: passed - `composer docs-lint` completed with zero errors and confirmed generated catalogs and inventories are current
  - runtime: not applicable
- Blast radius: complete - evidence=full Round 8 paired audit plus focused repository-wide inventories and docs lint; result=all four reconciled findings are covered by the 12-file docs-only diff
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 6d664a0419907761fc876c33bc4053b00787b5f7
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6d664a0419907761fc876c33bc4053b00787b5f7
- Accepted main tip: 45b57fae5daaa339d267ea9f686dbe041d11e5b2

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
