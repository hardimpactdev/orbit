# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/2/scratchpad/orbit-documentation--444
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-docs-drift-zero-round-2
- Branch: codex/docs-drift-zero-round-2

## Goal

Eliminate every verified round-2 Orbit product-documentation drift finding and
land one docs, code, test, and generated-catalog candidate whose fresh audit can
reach A=0, B=0, C=0.

## Scope

- Owned: Cloudflare cache instance resolution, app:show CWD and renderer contracts, generated error vocabulary, and database/schedule error-code docs.
- Constraints: Preserve current product authority, use instance-owned placement, keep generated artifacts fresh, and never run `composer test:e2e*`.
- Out of scope: Historical ledger prose, porting docs, Librarian style warnings, and unrelated pre-existing quality warnings.

## Proof

- Verification:
  - focused: passed - CLI app:show 6 tests/36 assertions; gateway Cloudflare 7 tests/25 assertions; docs catalog 24 tests/322 assertions
  - broader: passed - exact candidate `5a9a9fb1a8ad5f04c247ac06ea0b9e04350bfcdf`; `composer quality-check` passed all 10 units with candidate-bound receipt `.orbit/quality-gates/quality-check-2026-08-20T102750Z-4c6fe9bd3729.json`; refreshed `composer docs-lint` passed with 0 errors
  - runtime: passed - candidate=5a9a9fb1a8ad5f04c247ac06ea0b9e04350bfcdf; venue=retained-incus; environment=dev-fixture; target=dev-53da11/operator-and-gateway:/home/orbit/orbit-run; expected=marker-based app:show and successful dotted-instance Cloudflare cache-rule operation; observed=marker resolved both instances, PTY rendered flat table, and isolated provider fixture returned ready rule for docs.staging after three provider requests; result=passed; evidence=`.orbit/evidence/docs-drift-runtime-0ea92c5.md`
- Blast radius: complete - evidence=repository-wide retired-code and stale-contract searches, generated-catalog check, docs lint, exact-candidate 10-unit quality profile, and retained topology; result=all reconciled surfaces closed with no residual drift
- Review: passed - human-judgment=not-required; solo://proj/102/scratchpad/round-2-terminal-re--446
- Reviewed feature tip: 5a9a9fb1a8ad5f04c247ac06ea0b9e04350bfcdf
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 5a9a9fb1a8ad5f04c247ac06ea0b9e04350bfcdf
- Accepted main tip: 93bc2f3840e6836bf1caea5bf27cabd4f2d81f9a

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
