# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/round-13-final-synth--515`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-13`
- Branch: `codex-docs-drift-round-13`

## Goal

Correct every confirmed Round 13 product-documentation drift finding so the product docs and bundled Orbit references match current authority and command contracts.

## Scope

- Owned: Confirmed A1, B1, C1, C2, and C3 documentation/reference edits from the Round 13 final synthesis.
- Constraints: Preserve current product intent; keep WebSocket wording unchanged; do not run or trigger `composer test:e2e*`; preserve unrelated user state.
- Out of scope: PHP or command behavior changes, future dependency-audit product slices, and unrelated documentation cleanup.

## Proof

- Verification:
  - focused: passed - `git diff --check`; 32 gateway tests / 347 assertions; 1 docs test / 6 assertions; confirmed stale phrases and option omissions are absent from the owned references.
  - broader: passed - `composer quality-check` on `2feb55a3c18385ee3d1b5b48ab5f899d4afc6290`; `composer docs-lint` had 0 errors and existing warnings only.
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide inventory across active product docs, bundled references, and concrete CLI implementations; result=zero stale phrases or old signatures, all changed references matched code, zero WebSocket-line changes.
- Review: passed - Solo process 2584; human-judgment=not-required; no actionable findings.
- Reviewed feature tip: 2feb55a3c18385ee3d1b5b48ab5f899d4afc6290
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2feb55a3c18385ee3d1b5b48ab5f899d4afc6290
- Accepted main tip: 2944bf47828d5b381e6c3964a71127b2c463704d

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
