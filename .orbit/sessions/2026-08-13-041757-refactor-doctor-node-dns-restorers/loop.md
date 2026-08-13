# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 70; approved design `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-13-doctor-node-dns-restorers-design.md`
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-doctor-node-dns-restorers
- Branch: refactor/doctor-node-dns-restorers

## Goal

Move normal node repair and private DNS projection repair behind focused restorers while preserving Doctor actions, remaining drift, and final fresh probe behavior.

## Scope

- Owned: normal node-family repair, node/proxy private DNS projection repair, shared issue-node resolution, direct tests, and Doctor runner delegation.
- Constraints: Preserve exact Doctor action fields and order, fresh node loads between convergence passes, issue-named target precedence, fallback behavior, family ownership, and all restore failure semantics. Do not run human-only E2E lanes.
- Out of scope: bounded restore convergence, outcome reconciliation, report shaping, adopt behavior, family probing, product docs behavior changes, and unrelated restorer internals.

## Proof

- Verification:
  - focused: passed - 120 affected tests, 971 assertions; scoped Mago format, lint, and analysis passed after review fixes
  - broader: passed - full monorepo `composer quality-check` passed with receipt `.orbit/quality-gates/quality-check-2026-08-13T021011Z-d37b4228a6ac.json`
  - runtime: passed - candidate=a89fa1001a871718e06ba073f610f5d0ecfba9d2; venue=retained-incus; environment=dev-fixture; target=gateway:node repair and node.dns_mapping_mismatch; expected=completed Doctor actions followed by healthy final fresh probes; observed=both controlled drifts fixed once with zero failures and converged in one pass; result=passed; evidence=`.orbit/evidence/doctor-node-dns-restorers-retained-incus-dev-f47c10.json`
- Blast radius: complete - evidence=Claude Opus repository-wide removed-method, instantiation, provider-binding, and baseline search; result=ownership move fully propagated with no unresolved affected surface
- Review: passed - Claude Opus; human-judgment=not-required
- Reviewed feature tip: a89fa1001a871718e06ba073f610f5d0ecfba9d2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a89fa1001a871718e06ba073f610f5d0ecfba9d2
- Accepted main tip: d9148f543f51c119718178f30ad625e3a4cf65d5

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
