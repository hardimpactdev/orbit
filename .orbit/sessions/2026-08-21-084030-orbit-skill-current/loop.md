# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-orbit-skill-current`
- Branch: `codex/orbit-skill-current`

## Goal

Keep the repository-owned Orbit skill aligned with current product authority and the public command catalog, then install the exact corrected skill on the local operator node through `orbit skill:install` and verify byte-for-byte parity.

## Scope

- Owned: `.agents/skills/orbit/`, command-catalog and product-authority verification, installer regression coverage, and local Codex skill synchronization through the dedicated Orbit command.
- Constraints: preserve unrelated primary-checkout changes; do not change proxy behavior or product authority; do not publish or deploy the pending 0.1.196 release until artifact credentials are available.
- Out of scope: other provider skill targets, unrelated application repair, and GitHub release publication.

## Proof

- Verification:
  - focused: passed - skill installer tests: 24 tests, 102 assertions; failed architecture contract reproduced and fixed: 1 test, 14 assertions
  - broader: passed - `composer quality-check` at `01dfe663a61085eae548e0d14eef513e0f366de6`
  - runtime: not applicable
- Blast radius: complete - evidence=205-command generated catalog inventory plus recursive installed-tree comparison; result=only intentional grouped extension and Solo command matches remain, and 25 installed files match the repository candidate
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 01dfe663a61085eae548e0d14eef513e0f366de6
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 01dfe663a61085eae548e0d14eef513e0f366de6
- Accepted main tip: 53613cedbffa3b8cc4c9af61b0fde8df33fffba9

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
