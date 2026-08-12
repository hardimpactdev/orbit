# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-tool-family-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-tool-family`
- Branch: `refactor/doctor-tool-family`

## Goal

Doctor delegates tool observation to a focused family service while preserving tool scope, role checks, DNS checks, issue details, order, and progress.

## Scope

- Owned: tool family probe orchestration, node tool inventory, role-owned tool checks, DNS runtime verification, direct coverage, and runner delegation architecture checks.
- Constraints: Preserve public output, check order, progress counts, failure diagnostics, issue order, and restore behavior.
- Out of scope: Tool or DNS repair extraction, node family extraction, other Doctor families, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 115 tests, 963 assertions; scoped Mago format, lint, and analyze; diff and secret checks passed
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/profiles/2026-08-12T14-00-53Z-577ff62df64e/gateway_pest.junit.xml`
  - runtime: passed - candidate=577ff62df64e7e8c47adb394744c4c73a9764128; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=tool --json; expected=two successful runs with identical healthy JSON and no issues or actions; observed=both exits 0, exact byte match, SHA-256 a86ee0ee2bc90011325b1f6e625fdd6b2f7b2b60052419b608d557f1f9a4c2a6; result=passed; evidence=`.orbit/evidence/doctor-tool-family-retained-incus.md`
- Blast radius: complete - evidence=repository-wide search, focused runner and family tests, full quality check, and retained Incus proof; result=no orphaned tool-probe ownership, restore remains in the runner, and public Doctor output is unchanged
- Review: passed - Claude Opus found no actionable findings; human-judgment=not-required
- Reviewed feature tip: 577ff62df64e7e8c47adb394744c4c73a9764128
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 577ff62df64e7e8c47adb394744c4c73a9764128
- Accepted main tip: dbf1783baa8017895ef06962850249bc8f6d3837

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
