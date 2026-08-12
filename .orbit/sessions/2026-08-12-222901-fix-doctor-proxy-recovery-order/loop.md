# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 58
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-doctor-proxy-recovery-order`
- Branch: `fix/doctor-proxy-recovery-order`

## Goal

Make unscoped proxy recovery deterministic when managed Caddy is stopped: restore the Caddy runtime before saved routes that require a live Caddy reload.

## Scope

- Owned: proxy restore action ordering, convergence reporting, regression tests, and retained-Incus proof.
- Constraints: preserve issue order in verify reports, exact-key behavior, action envelopes, retry bounds, and all non-proxy Doctor families.
- Out of scope: changing proxy issue definitions, Caddy installation policy, retained fixture gateway port ownership, and unrelated Doctor extraction.

## Proof

- Verification:
  - focused: passed - Doctor runner/controller/restorer suites: 184 tests, 1297 assertions; dedicated proxy restorer boundary: 22 tests, 35 assertions
  - broader: passed - `composer quality-check`; evidence=`.orbit/quality-gates/quality-check-2026-08-12T201448Z-cf23ba3ac0e1.json`; exit_code=0; all subgates=0
  - runtime: passed - candidate=48d6871ee36f970df683601136bac9e51c701a07; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=proxy --restore --json; expected=Caddy recovery runs before saved-route recovery and both converge with no failed actions; observed=exit 0 healthy fixed 2 zero failed one pass converged then fresh proxy verify returned zero issues; result=passed; evidence=`.orbit/evidence/doctor-proxy-recovery-order-dev-ea3caa.json`
- Blast radius: complete - evidence=bounded repository-wide search found exactly two production orderForRecovery call sites and no positional issue-to-action consumer; result=CLI renderer sorts issues independently and renders actions in their own keyed loop with no schema vocabulary transport or ledger change
- Review: passed - human-judgment=not-required - Claude Opus exact general review found no actionable findings
- Reviewed feature tip: 48d6871ee36f970df683601136bac9e51c701a07
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 48d6871ee36f970df683601136bac9e51c701a07
- Accepted main tip: 8d45233943fd09c2ff0bb8fb59880535f42cf953

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
