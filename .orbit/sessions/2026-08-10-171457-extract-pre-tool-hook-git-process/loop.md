# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/2/scratchpad/orbit-stabilization--416 revision 3
- Worktree: /Users/nckrtl/orbit/.worktrees/extract-pre-tool-hook-git-process
- Branch: extract-pre-tool-hook-git-process

## Goal

Move the shared Git and process helpers from the pre-tool hook into one
procedural module without changing hook behavior.

## Scope

- Owned: bin/orbit-codex-pre-tool-use-hook, bin/orbit-git-process.php, and the
  existing hook contract test
- Constraints: preserve function names, signatures, result shapes, output,
  exit codes, normal mode, --lint mode, and fail-closed behavior
- Out of scope: command classification, policy changes, loop-contract logic,
  archive checks, capture health, and cleanup rule extraction

## Proof

- Verification:
  - focused: passed - exact Bash hook contract, Pest bridge, PHP and Bash syntax, and diff check
  - broader: passed - composer quality-check across all monorepo units
  - runtime: not applicable
- Blast radius: not-required - internal behavior-preserving helper extraction; bounded repository search found no external helper consumer or bin module manifest
- Review: passed - Claude Opus - human-judgment=not-required
- Reviewed feature tip: 01148db6c644970483f395f8cb8cf17a4bf2fa5a
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 01148db6c644970483f395f8cb8cf17a4bf2fa5a
- Accepted main tip: ea9102d996ca57cc4fce0cd5efb96d2ff21da087

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
