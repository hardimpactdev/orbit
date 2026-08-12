# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project `62`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-tool-recovery-contract`
- Branch: `refactor/doctor-tool-recovery-contract`

## Goal

Remove the unreachable Doctor runner tool-repair path so `NodeConverger` remains the only normal tool repair owner.

## Scope

- Owned: runner imports, dependency, dead repair methods, gateway-side interactive mode remnants, and a focused architecture guard
- Constraints: preserve CLI `doctor --fix` forwarding of selected issues as `restore` or `adopt`, bulk restore ordering and re-probe, action modes, and missing-tool behavior
- Out of scope: `NodeConverger`, `ToolsFixer`, DNS runtime repair, tool probing, tool ordering policy, tool commands, and new product behavior

## Proof

- Verification:
  - focused: passed - 118 tests and 1000 assertions across the architecture guard, NodeConverger, DoctorReportRunner, and CLI interactive forwarding; scoped Mago format, lint, and analyze passed
  - broader: passed - `composer quality-check`
  - runtime: passed - candidate=33c93735d4100c5d22006f4d177bab9f74e55e20; venue=retained-incus; environment=dev-fixture; target=app-dev-1; expected=normal tool drift restores through NodeConverger and verifies clean; observed=tool.container_missing restored in one pass then healthy with Caddy running; result=passed; evidence=`.orbit/evidence/doctor-normal-tool-recovery-incus.json`
- Blast radius: complete - evidence=repository-wide call-path and docs searches plus the architecture guard; result=no live gateway interactive tool-repair caller remains and NodeConverger is the sole normal tool-repair owner
- Review: passed - Claude Opus found no remaining issues; human-judgment=not-required
- Reviewed feature tip: 33c93735d4100c5d22006f4d177bab9f74e55e20
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 33c93735d4100c5d22006f4d177bab9f74e55e20
- Accepted main tip: eff92e1af7c5dba5e710617c4e9076c7be7d6de3

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
