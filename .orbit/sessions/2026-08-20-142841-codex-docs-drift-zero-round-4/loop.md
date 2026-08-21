# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/round-4-docs-drift-r--453`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-zero-round-4`
- Branch: `codex/docs-drift-zero-round-4`

## Goal

Eliminate all twelve reconciled round-4 Orbit product-documentation drift findings and align public proxy-list behavior, docs, generated contracts, and focused coverage.

## Scope

- Owned: Orbit product docs, generated command catalog, public proxy registry filtering, and focused contract coverage.
- Constraints: Preserve raw invalid proxy ownership for conflict and removal metadata; preserve unrelated user state; do not run `composer test:e2e*`.
- Out of scope: New product capabilities, UI work, destructive proxy cleanup, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - proxy query/controller (55 tests, 225 assertions), tool contract (8 tests, 72 assertions), workspace plan parity (57 tests, 452 assertions), and Librarian references (0 issues)
  - broader: passed - `composer quality-check` for candidate `a7d08f385c7a22d0b6a6c5ad031efa832c914b6e`; evidence=`.orbit/quality-gates/quality-check-2026-08-20T121531Z-8837eb6f12e7.json`
  - runtime: passed - candidate=a7d08f385c7a22d0b6a6c5ad031efa832c914b6e; venue=retained-incus; environment=dev-fixture; command=orbit proxy:list --json with one valid custom route and one invalid stored app tuple; expected=public inventory omits invalid instance-backed ownership while retaining valid routes and raw stored metadata; observed=default count one, instance count zero, custom count one, invalid tuple unchanged; result=passed; evidence=`.orbit/evidence/docs-drift-round4-retained-incus.md`
- Blast radius: complete - evidence=bounded repository-wide owner-type, local-storage, public renderer, error-code, and anchor searches plus docs/reference lint; result=no public owner.type=app bypass, stale non-gateway nodes-table claim, unmapped listed error code, or duplicate crash-history anchor remains
- Review: passed - `solo://proj/104/scratchpad/round-4-docs-drift-i--454`; human-judgment=not-required
- Reviewed feature tip: a7d08f385c7a22d0b6a6c5ad031efa832c914b6e
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a7d08f385c7a22d0b6a6c5ad031efa832c914b6e
- Accepted main tip: 83c5a2e2c2995bf2df24a35519edbfb94ee7aa3f

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
