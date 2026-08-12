# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-node-family-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-node-family`
- Branch: `refactor/doctor-node-family`

## Goal

Doctor delegates node observation to a focused family service while preserving issue order, progress, DNS scope, role checks, restore, and adopt behavior.

## Scope

- Owned: node family observation dispatch, its ordered checks and progress, direct coverage, and runner delegation architecture checks.
- Constraints: Preserve public output, issue order, key filtering, progress totals, transport failure diagnostics, DNS consumer scope, restore, and adopt behavior.
- Out of scope: Probe internals, node repair or adopt extraction, family selection, fleet coordination, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - Doctor unit suite: 208 tests, 1795 assertions; scoped Mago format, lint, and analyze passed
  - broader: passed - `composer quality-check`; evidence=`.orbit/quality-gates/profiles/2026-08-12T14-47-54Z-a738645b63c3/gateway_pest.junit.xml`
  - runtime: passed - candidate=a738645b63c3ceb5f1d13bf795ec0111f11d24ce; venue=retained-incus; environment=dev-fixture; command=`orbit doctor --node=gateway --family=node --json` twice on `dev-187668` through Beast LAN `192.168.6.20`; expected=identical ordered reports with fixture drift allowed; observed=both runs exited 1 with the same six issues in the same order; result=passed; evidence=`.orbit/evidence/doctor-node-family-retained-incus.md`
- Blast radius: not-required - internal Doctor verification extraction; repository-wide caller and dependency search found no external consumers or contract changes
- Review: passed - Claude Opus verified behavior parity, the DNS invariant test, and all prior findings; human-judgment=not-required
- Reviewed feature tip: a738645b63c3ceb5f1d13bf795ec0111f11d24ce
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a738645b63c3ceb5f1d13bf795ec0111f11d24ce
- Accepted main tip: e96a8a97fb54d187f48e7112d81ebf53cbae86ff

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
