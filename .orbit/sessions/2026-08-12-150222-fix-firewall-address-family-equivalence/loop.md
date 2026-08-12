# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-firewall-address-family-equivalence-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-firewall-address-family-equivalence`
- Branch: `fix/firewall-address-family-equivalence`

## Goal

Doctor and firewall convergence recognize equivalent UFW address-family rules, so an enacted Orbit firewall rule does not report false drift or enter a replace loop.

## Scope

- Owned: Firewall address-family canonicalization, Doctor and convergence comparison, focused coverage, and the firewall Doctor contract.
- Constraints: Preserve the public firewall command and JSON contracts; prove the operator flow on retained Incus; use Beast only through `192.168.6.20`.
- Out of scope: Firewall inventory SQL ordering, firewall command input changes, unrelated Doctor family extraction, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - 124 gateway firewall/Doctor tests, 623 assertions; focused Mago format and lint exit 0; docs-lint exit 0
  - broader: passed - `composer quality-check`; all apps and packages passed; evidence `.orbit/quality-gates/quality-check-2026-08-12T124402Z-8d25b23eb2b1.json`
  - runtime: passed - candidate=54367677b9cc2f78cd303d9da1ed5ee812aafec4; venue=retained-incus; environment=dev-fixture; target=app-dev-1 firewall rule from 10.6.0.0/24; expected=Doctor reports the enacted CIDR rule healthy and repeated apply stays converged; observed=Doctor reported healthy with zero issues twice and cleanup removed backend plus gateway state; result=passed; evidence=`.orbit/evidence/firewall-address-family-retained-incus.md`
- Blast radius: complete - evidence=repository-wide search for `FirewallRuleShapeCanonicalizer`, `concreteExpectedShapes`, and `effectiveAddressFamily`; result=the only product consumers are Doctor and UFW convergence, and both use the shared rule
- Review: passed - Claude Opus found no actionable issues; human-judgment=not-required
- Reviewed feature tip: 54367677b9cc2f78cd303d9da1ed5ee812aafec4
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 54367677b9cc2f78cd303d9da1ed5ee812aafec4
- Accepted main tip: 1fc21a0fde36663680598e1161cf281c4174a0c9

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
