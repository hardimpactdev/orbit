# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/round-3-docs-drift-r--449`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-zero-round-3`
- Branch: `codex/docs-drift-zero-round-3`

## Goal

Eliminate all nine reconciled round-3 Orbit product-documentation drift findings and keep docs, generated schemas, code, and tests aligned.

## Scope

- Owned: Orbit product docs, Librarian entity schemas and generated command catalog, workspace placement vocabulary in gateway output, and focused tests.
- Constraints: Preserve compatibility identifiers unless current authority explicitly supersedes them; preserve unrelated user state; do not run `composer test:e2e*`.
- Out of scope: New product behavior, proxy conflict behavior changes, UI work, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - gateway workspace placement tests (43 tests, 112 assertions) and Librarian command-doc rules (88 tests, 660 assertions)
  - broader: passed - `composer quality-check` for candidate `5b465b40829b06c1144936044ef1145017139758`; evidence=`.orbit/quality-gates/quality-check-2026-08-20T111654Z-00fd660a34d1.json`
  - runtime: passed - candidate=5b465b40829b06c1144936044ef1145017139758; venue=retained-incus; environment=dev-fixture; command=orbit workspace:setup proof-should-not-exist --instance=proof-round3-5b465b4.development --path=/home/orbit/apps/proof-round3-5b465b4 --json; expected=Instance source path rejected with stable dotted metadata and no workspace side effect; observed=workspace.path_is_instance_root with dotted meta.instance and workspace.not_found afterward; result=passed; evidence=`.orbit/evidence/docs-drift-round3-retained-incus.md`
- Blast radius: complete - evidence=repository-wide searches, serializer inventory, and docs lint; result=no unresolved in-scope vocabulary or schema drift, and generated catalogs are current
- Review: passed - `solo://proj/2/scratchpad/round-3-general-revi--450`; human-judgment=not-required
- Reviewed feature tip: 5b465b40829b06c1144936044ef1145017139758
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 5b465b40829b06c1144936044ef1145017139758
- Accepted main tip: 6ebd95263a76bfed8b39dbe18294a62f3471bf1b

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
