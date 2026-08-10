# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/2/scratchpad/orbit-stabilization--416 revision 3
- Worktree: /Users/nckrtl/orbit/.worktrees/stabilize-pre-tool-hook-output-contract
- Branch: stabilize-pre-tool-hook-output-contract

## Goal

Add exact stdout, stderr, and exit-code coverage for the pre-tool hook to the
normal quality gate before production code moves.

## Scope

- Owned: bin/orbit-codex-pre-tool-use-hook-test and the smallest gateway Pest
  bridge needed to run it in composer quality-check
- Constraints: preserve hook behavior; use the existing Bash test; keep both
  normal and --lint entry modes covered
- Out of scope: production hook changes, module extraction, policy changes,
  output changes, and composer test:e2e* commands

## Proof

- Verification:
  - focused: passed - exact Bash output contract and gateway Pest bridge
  - broader: passed - composer quality-check across all monorepo units
  - runtime: not applicable
- Blast radius: not-required - test-only characterization of an existing hook contract; bounded gate-wiring inventory confirmed it runs in the normal lane
- Review: passed - Claude Opus - human-judgment=not-required
- Reviewed feature tip: 12cdb1b9f51fc0ad65c22a7bd8155e769c8aa69b
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 12cdb1b9f51fc0ad65c22a7bd8155e769c8aa69b
- Accepted main tip: 3bbaa0c5fc9cd0b2fe78bcdcc539edd8f408de0b

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
