# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo goal 435; reconciled audit `solo://proj/2/scratchpad/docs-audit-final-r1--438`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-zero-round-1`
- Branch: `codex/docs-drift-zero-round-1`

## Goal

Eliminate every in-scope Orbit product-documentation drift finding and reach a fresh independent audit result of A=0, B=0, C=0.

## Scope

- Owned: `apps/docs/content/**` except exclusions, and the CLI, gateway, SDK, tests, and generated command catalog needed to align implementation with current product authority.
- Constraints: Follow `PRODUCT_DECISIONS.md`; use FRAME, iterate BUILD and PROVE, then ACCEPT and LAND; do not run `composer test:e2e*`; preserve unrelated work.
- Out of scope: `apps/docs/content/porting/**`, `docs/superpowers/**`, explicit historical or third-party terminology exemptions.

## Proof

- Verification:
  - focused: passed - docs lint, generated inventories, stale-contract searches, Solo catalog coverage, and 86 targeted gateway plus 48 targeted CLI tests
  - broader: passed - `composer test` and `composer quality-check`
  - runtime: passed - candidate=49a0f876a7bfae1dc64b456887f436f3bc045162; venue=retained-incus; environment=dev-fixture; target=dev-2773e6/operator:/home/orbit/orbit-run; expected=the synchronized source documents and implements concrete PHP snapshots, rejects the removed inherit option, preserves empty operator roles and malformed stored proxy ownership, and omits the removed SDK input; observed=the final documentation hash matched the synchronized candidate and runtime plus deterministic contract probes matched every corrected contract; result=passed; evidence=`.orbit/evidence/docs-drift-runtime-49a0f876.md`
- Blast radius: complete - evidence=repository-wide stale-contract searches, 54-of-54 Solo catalog inventory, docs lint, changed-test review, and full quality gate; result=no unresolved live contract term or affected surface remains
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 49a0f876a7bfae1dc64b456887f436f3bc045162
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 49a0f876a7bfae1dc64b456887f436f3bc045162
- Accepted main tip: 24ed52295ceb23b68b5082bbdd4880bf0629d04a

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
