# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-10-pre-tool-hook-modularization-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/extract-pre-tool-hook-command-classify`
- Branch: `extract-pre-tool-hook-command-classify`

## Goal

Move command reading and command type detection into `bin/orbit-command-classify.php` without changing hook behavior.

## Scope

- Owned: `bin/orbit-codex-pre-tool-use-hook`, `bin/orbit-command-classify.php`, the hook contract test, and the gateway safety test that verifies the E2E guard.
- Constraints: Keep all function names, result shapes, output, exit codes, normal mode, and `--lint` behavior unchanged.
- Out of scope: Policy checks, archive checks, capture checks, proof checks, cleanup checks, and further module extraction.

## Proof

- Verification:
  - focused: passed - hook exact-output contract, module boundary, PHP syntax, Bash syntax, and gateway E2E safety test
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/quality-check-2026-08-10T152637Z-de1b16220ffe.json`
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide search for the module and all moved function definitions; result=one definition per function, expected hook and test consumers only, and the known Solo helper use of `shell_words`
- Review: passed - Claude Opus Solo process 2265; no blocking findings; human-judgment=not-required
- Reviewed feature tip: 4d8b4badae094b89f145e15dfcf220f613af2f0c
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 4d8b4badae094b89f145e15dfcf220f613af2f0c
- Accepted main tip: c8e05e124141f20e7103a4e19cc11b5c7e36d762

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
