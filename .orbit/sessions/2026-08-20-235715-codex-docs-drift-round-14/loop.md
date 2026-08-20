# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/round-14-final-synth--518`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-14`
- Branch: `codex/docs-drift-round-14`

## Goal

Correct the two Round 14 documentation findings so the bundled gateway reference matches caller-local storage behavior and the update technical contract names only a live command surface.

## Scope

- Owned: `.agents/skills/orbit/references/gateway.md`; `apps/docs/content/domains/11_operation/1_update/technical/1_update.md`; `.orbit/loop.md`
- Constraints: Docs-only correction; preserve current product behavior; do not edit generated command catalog; do not run any `composer test:e2e*` command.
- Out of scope: CLI or gateway behavior changes; new product decisions; unrelated documentation drift; the primary checkout's `.codex/config.toml` change.

## Proof

- Verification:
  - focused: passed - command catalog check, docs-app Mago format check, `git diff --check`, and repository-wide stale-claim searches
  - broader: passed - `composer docs-lint` and `composer quality-check` at `3daa7f6d104a2e94b3d76b02a47fb4fb49883da0`
  - runtime: not applicable
- Blast radius: complete - evidence=bounded tracked-file search across current Markdown, PHP, and generated JSON plus command-catalog and final-check reads; result=no live stale claim remains and all retained candidate gates pass.
- Review: passed - Solo process 2587 and `solo://proj/2/scratchpad/round-14-remediation--519`; human-judgment=not-required; no actionable findings.
- Reviewed feature tip: 3daa7f6d104a2e94b3d76b02a47fb4fb49883da0
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3daa7f6d104a2e94b3d76b02a47fb4fb49883da0
- Accepted main tip: 97d274b21a5d66dda5edeff52690e814b1152cd4

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
