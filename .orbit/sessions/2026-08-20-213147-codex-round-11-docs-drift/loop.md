# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/114/scratchpad/round-11-documentati--504`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-round-11-docs-drift`
- Branch: `codex/round-11-docs-drift`

## Goal

Align the shipped Orbit skill and two tracked schema-1 archive reports with
current product authority, while preserving the historical source paths, so
the five reconciled Round 11 drift findings are removed.

## Scope

- Owned: `.agents/skills/orbit/SKILL.md`, `.agents/skills/orbit/references/concepts.md`, `.agents/skills/orbit/references/node.md`, the two Round 11-cited `.orbit/sessions/**/evidence/*.md` reports, and loop evidence.
- Constraints: Preserve every original absolute worktree path as historical text; add separate verified archive links; never run or delegate `composer test:e2e*`; preserve unrelated primary-checkout state.
- Out of scope: Product authority, runtime code, tests, generated Librarian files, and unrelated archive cleanup.

## Proof

- Verification:
  - focused: passed - skill RED/GREEN retrieval, modified-report link resolution, `git diff --check`, and docs Mago format check; evidence `.orbit/evidence/round-11-skill-reference-tdd.md`
  - broader: passed - `composer quality-check` passed all 10 monorepo units at candidate `b12df44102324e77b74711123d60f841801498a6`; evidence `.orbit/quality-gates/profiles/2026-08-20T19-23-11Z-b12df4410232/gateway_pest.junit.xml`
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide searches for `Redis` and `hosted role`, plus archive-target inventory; result=no unresolved shipped-skill or product-authority vocabulary drift and all nine relative archive targets resolve
- Review: passed - human-judgment=not-required
- Reviewed feature tip: b12df44102324e77b74711123d60f841801498a6
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b12df44102324e77b74711123d60f841801498a6
- Accepted main tip: be7e3a1f62fb45c4f196750f4269d34ad5849cbc

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
