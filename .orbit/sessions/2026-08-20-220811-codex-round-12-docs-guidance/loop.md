# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/115/scratchpad/round-12-docs-guidan--510`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-round-12-docs-guidance`
- Branch: `codex/round-12-docs-guidance`

## Goal

Align the audit and command-design skills plus the Activity channel definition
with current product authority, removing all five reconciled Round 12 docs drift
findings without changing runtime behavior.

## Scope

- Owned: `.agents/skills/auditing-docs-drift/SKILL.md`, `.agents/skills/command-designer/references/command-documentation.md`, `apps/docs/content/domains/16_activity/activity-concepts.md`, and loop/evidence files.
- Constraints: Preserve the useful caller-role sweep, keep historical `cli` activity rows readable, never run or delegate `composer test:e2e*`, and preserve unrelated primary-checkout state.
- Out of scope: Runtime code, the dormant CLI activity writer cleanup, generated Librarian files, and unrelated documentation cleanup.

## Proof

- Verification:
  - focused: passed - RED/GREEN behavior check and focused docs lint; evidence `.orbit/evidence/round-12-docs-guidance-red-green.md`
  - broader: passed - `composer quality-check` passed all ten repository units on `366d502324e56a5d1924c14d0a8d9f9d963c2d84`; evidence `.orbit/quality-gates/profiles/2026-08-20T20-00-51Z-366d502324e5/gateway_pest.junit.xml`
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide searches for current/legacy node wording, removed Agent IDE domain, command-family prefixes, and Activity channels; result=no contradictory downstream surface remains
- Review: passed - human-judgment=not-required; Solo process 2577 found no actionable findings
- Reviewed feature tip: 366d502324e56a5d1924c14d0a8d9f9d963c2d84
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 366d502324e56a5d1924c14d0a8d9f9d963c2d84
- Accepted main tip: 66807a7c3c8967d9b975a88d69774fabc810087d

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
