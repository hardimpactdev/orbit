# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-10-pre-tool-hook-modularization-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/extract-pre-tool-hook-archive-checks`
- Branch: `extract-pre-tool-hook-archive-checks`

## Goal

Move compact archive receipt validation into `bin/orbit-session-archive-receipt.php` without changing hook behavior.

## Scope

- Owned: `bin/orbit-codex-pre-tool-use-hook`, `bin/orbit-session-archive-receipt.php`, and the existing hook contract test.
- Constraints: Keep receipt schema rules, output, exit codes, normal mode, and `--lint` behavior unchanged.
- Out of scope: Archive discovery, capture health, proof checks, cleanup checks, and further module extraction.

## Proof

- Verification:
  - focused: passed - hook exact-output contract, module boundary, PHP syntax, Bash syntax, and gateway hook bridge
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/quality-check-2026-08-10T154235Z-5d893c146037.json`
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide search for the new module and all moved receipt functions; result=one definition per function and only the expected hook and test references
- Review: passed - Claude Opus Solo process 2266; no blocking findings; human-judgment=not-required
- Reviewed feature tip: 94dc9ac6a5072cd0a323e49bd7c90367596596a9
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 94dc9ac6a5072cd0a323e49bd7c90367596596a9
- Accepted main tip: 8d57e21e4eecc755ff7fc978e0d8149a7e13fe64

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
