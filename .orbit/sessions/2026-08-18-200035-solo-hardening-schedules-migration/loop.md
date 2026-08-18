# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-schedules-migration
- Branch: solo-hardening-schedules-migration

## Goal

Harden `2026_08_17_200000_constrain_schedule_owner_invariant` so remapped `schedule_key` collisions abort before mutation, the SQLite rebuild cannot record success after a partial crash, and clean-data reruns stay idempotent.

## Scope

- Owned: `apps/gateway/database/migrations/2026_08_17_200000_constrain_schedule_owner_invariant.php`, `apps/gateway/tests/Feature/Migrations/ConstrainScheduleOwnerInvariantTest.php`, and the schedule-add technical test-mapping citation
- Constraints: keep the closed Orbit/Node/Instance invariant, CHECK constraint, and Schedule saving guard; no clean-data-path behavior change
- Out of scope: Schedule model/API behavior, live nodes, E2E, merge/push

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Migrations/ConstrainScheduleOwnerInvariantTest.php` 8 passed 43 assertions on merged tip c4a4173ff; schedule model suites 16 passed
  - broader: passed - `composer quality-check` on clean merged commit c4a4173ff49bd5c4186104d672ac90e3c3274683 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T175802Z-cc0d8e169abc.json`)
  - runtime: passed - candidate=c4a4173ff49bd5c4186104d672ac90e3c3274683; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-99a268-gateway; expected=exact candidate migration executes via checkout.migrate and passes the migration and schedule invariant suites in the routed retained gateway environment; observed=matching migration sha256 1644bfdb2745 and 24 tests passed 99 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/solo-hardening-schedules-migration-retained-incus-runtime.txt`
- Blast radius: complete - evidence=repository-wide diff inventory plus full gateway Pest suite; result=only the schedules invariant migration, its test, and one docs test-mapping citation changed, the migration has no external callers, collision preflight and partial-rebuild guards are additive fail-loud paths, clean-data path proven idempotent by rerun test, full suite 6944 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: DML plus rebuild plus index creation wrapped in one transaction with FK pragmas outside, collision preflight aborts before mutation, partial-rebuild rerun states fail loud with actionable messages, cross-set key collisions covered by transactional rollback against the old unique index; human-judgment=not-required
- Reviewed feature tip: c4a4173ff49bd5c4186104d672ac90e3c3274683
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c4a4173ff49bd5c4186104d672ac90e3c3274683
- Accepted main tip: 6640885299bd6b77fb8b72f6147b1ec815a529ab

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
