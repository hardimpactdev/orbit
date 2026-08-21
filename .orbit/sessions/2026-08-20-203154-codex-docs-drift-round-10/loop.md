# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/2/scratchpad/round-10-docs-drift--496
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-10
- Branch: codex/docs-drift-round-10

## Goal

Apply every reconciled Round 10 docs finding B1-B17 and C1-C14 exactly, with no PHP/test edits and no CF1 Metrics code follow-up.

## Scope

- Owned: root README.md, current `.agents/skills/**` Markdown, `apps/docs/content/**` leaf contracts named by the reconciled scope
- Constraints: preserve unrelated worktree state; do not edit generated files by hand; never run `composer test:e2e*`
- Out of scope: CF1 Metrics Ubuntu-only code/tests; PHP/tests; commit/LAND

## Proof

- Verification:
  - focused: passed - `git diff --check`; reconciled-cluster stale-term searches; changed `.agents/**/*.md` relative-link resolution check
  - broader: passed - exact-candidate `composer docs-lint` (domain errors=0; testing errors=0; references issues=0; generated command catalog, monorepo unit map, and transitional SSH inventory current)
  - runtime: not applicable
- Blast radius: complete - evidence=full 57-file base-to-candidate diff review plus repository-wide reconciled-cluster searches and generated-inventory checks; result=all 31 docs groups covered, no PHP/tests/generated-file edits, separate Metrics code slice retained
- Review: passed - human-judgment=not-required; independent Solo review `solo://proj/112/scratchpad/round-10-documentati--498`; findings blocking=0 major=0 minor=0; code follow-ups beyond separate Metrics slice=0
- Reviewed feature tip: 7b1dbc7e5ef33d2f99d3e9e67098f4ffb7b0f397
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7b1dbc7e5ef33d2f99d3e9e67098f4ffb7b0f397
- Accepted main tip: 3f4b32475cfa2c31e31c661d841caedd96412535

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
