# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none - behavior-preserving Doctor refactor
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-fleet-probe`
- Branch: `refactor/doctor-fleet-probe`

## Goal

Move the single-node fleet probe and its failure report shaping out of DoctorReportRunner without changing the Doctor report contract.

## Scope

- Owned: One DoctorFleetTargetProbe service, the DoctorReportRunner compatibility delegate, and focused contract proof.
- Constraints: Keep the internal fleet worker command unchanged. Preserve selected families, exact key filtering, failure issue keys, dispositions, and report shape.
- Out of scope: Multi-node fleet coordination, bounded fan-out, Doctor restore, adopt, repair handlers, public command changes, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - Doctor target, fleet worker, batching, node coordinator, report section, and runner suites: 127 tests, 992 assertions; scoped Mago format and lint passed
  - broader: passed - `composer quality-check`; evidence=`.orbit/quality-gates/profiles/2026-08-12T16-02-24Z-01f598f5b4ce/gateway_pest.junit.xml`
  - runtime: passed - candidate=01f598f5b4ce33d74a63384776158c43b9f0b1d5; venue=retained-incus; environment=dev-fixture; command=`orbit doctor --all --json` twice on `dev-263f9f` through Beast LAN `192.168.6.20`; expected=identical ordered fleet reports with fixture drift allowed; observed=both runs exited 1 with drift_detected and byte-identical 6915-byte output; result=passed; evidence=`.orbit/evidence/doctor-fleet-target-retained-incus.md`
- Blast radius: complete - evidence=repository-wide search of DoctorFleetTargetProbe, probeFleetTargetReport, removed helper names, and constructor dependencies; result=all callers keep the compatibility method, no old helper consumer remains, and the new dependency chain has no DoctorReportRunner cycle
- Review: passed - Claude Opus verified report parity, caller coverage, selected-family and key filtering, transport failure shape, and the acyclic dependency boundary; human-judgment=not-required
- Reviewed feature tip: 01f598f5b4ce33d74a63384776158c43b9f0b1d5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 01f598f5b4ce33d74a63384776158c43b9f0b1d5
- Accepted main tip: 07cd2b6cd9bd402d7bcdc2ef4f705121aab99fe8

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
