# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-inventory-ordering-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-inventory-ordering`
- Branch: `refactor/doctor-inventory-ordering`

## Goal

Doctor reads gateway-backed family inventories in stable database row order, so identical state produces identical issue order.

## Scope

- Owned: SQL ordering for Doctor instances, workspaces, processes, proxy routes, firewall rules, tools, and schedules; focused proof; Doctor output-order contract.
- Constraints: Preserve family order, issue-kind order, placement filtering, public payloads, and restore behavior.
- Out of scope: Further family extraction, inventory snapshot caching, issue severity changes, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - 118 Doctor tests, 986 assertions; focused Mago format, lint, and analysis passed
  - broader: passed - composer quality-check in 196 seconds; composer docs-lint and quality-gate final check passed with no final warnings
  - runtime: passed - candidate=528f4bb8e9318b566b1e86b471d21d3ab2e2e1b7; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=tool --json twice; expected=both runs preserve ascending database row ID issue order; observed=both runs returned zzz proof issues before aaa proof issues and the extracted sequences matched; result=passed; evidence=`.orbit/evidence/doctor-inventory-ordering-retained-incus.md`
- Blast radius: complete - evidence=repository-wide Doctor inventory search, all NodeProcessResolver caller inspection, and 111 Doctor/process regression tests; result=all seven owned inventories are ordered and no affected caller or remaining issue-order path has a gap
- Review: passed - Claude Opus; findings=none; human-judgment=not-required
- Reviewed feature tip: 528f4bb8e9318b566b1e86b471d21d3ab2e2e1b7
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 528f4bb8e9318b566b1e86b471d21d3ab2e2e1b7
- Accepted main tip: 6bc5c1a754a6229541b6dedfcd0e8702f60fe599

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
