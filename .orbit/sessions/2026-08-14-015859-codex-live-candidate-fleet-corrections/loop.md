# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: codex://threads/019ffabc-3aae-7081-9c86-3cb5ae4642c1
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-live-candidate-fleet-corrections
- Branch: codex/live-candidate-fleet-corrections

## Goal

Candidate update:all accepts archive-backed runtime image aliases and excludes the caller-local node from every remote workload phase.

## Scope

- Owned: update:all target selection, archive-backed runtime image aliases, tests, and product docs; primitive=fleet candidate update; transitions=success:all gateway local and remote targets converge|failure:the operation records the failing target and stops before trust|retry:a new operation can safely retry the same candidate|stop-restart:the persisted plan and operation state remain authoritative|stale:the caller-local node is never selected as a remote target
- Constraints: preserve candidate digest validation, gateway-first order, caller-local self-update, and retained proof; never run human-only E2E lanes.
- Out of scope: Doctor extraction, release version changes, and unrelated fleet drift.

## Proof

- Verification:
  - focused: passed - CLI payload 2 tests/4 assertions; gateway update target and payload 28 tests/262 assertions; related CLI update suites 70 tests/433 assertions; related gateway suites 28 tests/181 assertions; gateway Mago analyze/lint passed.
  - broader: passed - committed `composer quality-check` exited 0 for candidate 7f7d65dbb88f8961b10bca5f1b5fe4617b5e3152; artifact `.orbit/quality-gates/quality-check-2026-08-13T232424Z-2f9d04fe24d4.json`.
  - runtime: passed - candidate=7f7d65dbb88f8961b10bca5f1b5fe4617b5e3152; venue=retained-incus; environment=dev-fixture; target=update:all caller omission and archive-backed role aliases; expected=calling node omitted from remote work and archive-loaded candidate receives the stable runtime alias; observed=app-dev-1 was absent from its own remote targets and operator-initiated operation 628809f3-23cf-4177-bdb7-06a0669ba2d4 succeeded after all fleet verifications; result=passed; evidence=`.orbit/evidence/retained-incus-candidate-fleet-7f7d65dbb.json`
- Blast radius: complete - evidence=repository-wide selector-call inventory plus gateway and CLI update-suite coverage; result=all modern update phases pass OperationRun caller identity and legacy update excludes its activity subject while candidate digest validation remains unchanged
- Review: passed - Fable process 2389 found no blockers and confirmed both objectives, contract preservation, discriminating tests, and candidate-bound runtime proof; human-judgment=not-required
- Reviewed feature tip: 7f7d65dbb88f8961b10bca5f1b5fe4617b5e3152
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7f7d65dbb88f8961b10bca5f1b5fe4617b5e3152
- Accepted main tip: afd0e399ce268980e23327b130ba7f5ba52b2468

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
