# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/80/scratchpad/tool-capability-prob--431`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-tool-capability-probe-contract`
- Branch: `refactor/tool-capability-probe-contract`

## Goal

Give Orbit's single-tool Doctor observation and batched node-convergence observation one typed capability-probe contract without changing current behavior.

## Scope

- Owned: capability probe input normalization, the single-tool shell/reply contract, and the batched shell/reply contract.
- Constraints: Preserve generated scripts, marker strings, dispatcher action names, current TSV and JSONL formats, installed-state rules, snapshot shapes, tool order, failure behavior, and managed-file probe order.
- Out of scope: Unifying reply formats, malformed-output behavior changes, tool ownership, issue codes, restore behavior, public command output, and product documentation.

## Proof

- Verification:
  - focused: passed - 85 tests, 422 assertions; scoped Mago format, lint, and analysis passed
  - broader: passed - 436 Doctor/tool tests, 2,869 assertions; full gateway 6,205 passed, 51,233 assertions, 4 skipped
  - runtime: passed - candidate=162a7ac31381c052b7d966bdd81c9989d2885537; venue=retained-incus; environment=dev-fixture; expected=single Doctor and batch convergence observations use the candidate contract; observed=healthy Doctor tool report and seven ordered batch snapshots; result=passed; target=dev-dca9de/operator_gateway_app-dev/app-dev-1; evidence=`.orbit/evidence/tool-capability-probe-retained-incus.json`
- Blast radius: complete - evidence=repository-wide marker and method-name architecture assertions plus full gateway suite; result=wire contract moved out of ToolsProbe and all callers remain green
- Review: passed - Claude Opus compared base and candidate scripts byte-for-byte, reviewed parsing, callers, fallback injection, and related tests; human-judgment=not-required
- Reviewed feature tip: 162a7ac31381c052b7d966bdd81c9989d2885537
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 162a7ac31381c052b7d966bdd81c9989d2885537
- Accepted main tip: cf7d4940acfddb90609c92658cdfa7226c18f15d

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
