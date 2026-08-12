# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo design and final review
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-app-workspace-restorer`
- Branch: `refactor/doctor-app-workspace-restorer`

## Goal

Move app and workspace recovery out of `DoctorReportRunner` without changing issue lookup, placement rules, action results, or restore convergence.

## Scope

- Owned: app and workspace recovery delegation, focused tests, and architecture checks
- Constraints: preserve app, instance, and workspace selection; production workspace rules; `runtime_config_extra` handling; skipped workspace actions; action result fields; issue order; and final verification
- Out of scope: node, process, proxy, firewall, tool, schedule, database, and DNS recovery; adopt and probe flows; product behavior changes

## Proof

- Verification:
  - focused: passed - 282 Doctor tests, 2,062 assertions; scoped Mago format and lint passed
  - broader: passed - `composer quality-check`; artifact `.orbit/quality-gates/quality-check-2026-08-12T205345Z-8e8120f1ece2.json`
  - runtime: passed - candidate=fbd1d5d8ad7a5b439e9988f3c602d73a9efcff61; venue=retained-incus; environment=dev-fixture; target=app-dev-1; expected=orphan managed app config is removed and recovery converges; observed=one completed removal with zero failed actions followed by a healthy verify with zero issues; result=passed; evidence=`.orbit/evidence/doctor-app-workspace-restorer-dev-f3447c.json`
- Blast radius: complete - evidence=repository-wide search for Doctor restorer construction, bindings, and dispatch; result=no registry, binding, or external caller requires a change
- Review: passed - Claude Opus found no actionable issue - human-judgment=not-required
- Reviewed feature tip: fbd1d5d8ad7a5b439e9988f3c602d73a9efcff61
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: fbd1d5d8ad7a5b439e9988f3c602d73a9efcff61
- Accepted main tip: 12df740f52ed09e3cb4905d2781bdffef2daf12d

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
