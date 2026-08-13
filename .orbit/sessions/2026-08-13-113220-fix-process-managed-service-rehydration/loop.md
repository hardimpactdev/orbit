# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/orbit-stabilization--416`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-process-managed-service-rehydration`
- Branch: `fix/process-managed-service-rehydration`

## Goal

Doctor restores an unrenderable node-owned managed process from its stored intent without rotating credentials or losing service options, image, or bind intent.

## Scope

- Owned: managed-service reconstruction for `process.runtime_unit_unrenderable`; catalog support for existing credentials; focused Doctor and process-service regression tests
- Constraints: preserve creation-time credential generation, action receipts, final fresh observation, and non-managed process restore behavior
- Out of scope: service catalog splitting, credential rotation, human-only E2E lanes, and unrelated process runtime refactors

## Proof

- Verification:
  - focused: passed - 156 Pest tests, 1,343 assertions; scoped Mago format/lint; docs lint; diff check
  - broader: passed - `composer quality-check`; receipt `.orbit/quality-gates/quality-check-2026-08-13T092045Z-7d19679b246a.json`
  - runtime: passed - candidate=a3bab1c225d22f3fae1ca3cf90a8443c8aa65598; venue=retained-incus; environment=dev-fixture; target=dev-02a7ba/app-dev-1; expected=Doctor restores stored managed-service intent without rotating credentials or losing options and binds; observed=one-pass convergence preserved credential hash database username port and loopback bind with zero remaining exact-key issues; result=passed; evidence=`.orbit/evidence/managed-service-doctor-rehydration-a3bab1c22.md`
- Blast radius: complete - evidence=Claude Opus repository-wide inventory of every ProcessServiceCatalog::resolve caller and DoctorProcessRestorer construction path; result=existingCredentials is opt-in only for ProcessServiceRehydrator, all creation and role-baseline callers retain null generation behavior, and no unresolved schema or construction surface remains
- Review: passed - Claude Opus Solo process 2379 found no actionable findings after correcting the venue to retained-incus; human-judgment=not-required
- Reviewed feature tip: a3bab1c225d22f3fae1ca3cf90a8443c8aa65598
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a3bab1c225d22f3fae1ca3cf90a8443c8aa65598
- Accepted main tip: 6b5e196020af7fc3467ff81acefd0f7338eefd3c

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
