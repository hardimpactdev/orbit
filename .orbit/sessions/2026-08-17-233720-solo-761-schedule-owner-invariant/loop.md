# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p09-a-constrain-sche--761`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-761-schedule-owner-invariant`
- Branch: `solo-761-schedule-owner-invariant`

## Goal

Schedule ownership is a closed invariant: a schedule's owner is exactly one of Orbit, Node, or Instance, and the database enforces that exactly the matching foreign key is set (orbit -> no owner FK; node -> node_id only; instance -> instance_id only), so contradictory owners can never be persisted; App and live target labels are derived from the owner rather than stored as an authoritative column, while historical labels needed for deleted-run history are preserved on schedule_runs.

## Scope

- Owned: apps/gateway Schedule/ScheduleRun/ScheduleLock models + scope-owner invariant (a scope enum Orbit|Node|Instance, a booted saving/validation guard rejecting contradictory owner FKs, derived App + live target-name accessors), a schema migration that canonicalizes legacy rows (map legacy `app` scope to Instance via the existing instance authority, enforce the per-scope FK invariant, and apply an explicit policy for ambiguous/deleted-owner rows) and drops/derives the authoritative app_id where App is now derived, the schedule owner resolver (ScheduleInstanceResolver + ScheduleDispatcher/OrbitScheduler as needed), schedule API resources, schedule lock identity, and focused schedule schema/model/resolver/API/lock Pest tests.
- Constraints: FIRST map the current scope values and owner columns precisely (scope string + app_id/instance_id/node_id + target_name + schedule_key; there is a prior 2026_07_19 canonicalize_schedule_app_instance_ownership migration and a 2026_08_05 app_instances->instances rename) and derive the exact closed-owner set from the todo intent (P05-B: Instance authority replaces App ownership). SQLite-safe migration; keep schedule_key + lock identity stable across the migration or migrate schedule_runs/schedule_locks keys atomically as the prior migration did; keep serialized API compatible where consumers depend on App/target labels; deterministic runtime behavior unchanged for Orbit/Node/Instance schedules; declare(strict_types=1); Mago/Rector clean. Do NOT run composer test:e2e*.
- Out of scope: scheduler interval/dispatch semantics beyond ownership, unrelated schedule fields, and non-schedule owners.

## Proof

- Verification:
  - focused: passed - gateway Pest schedule owner-invariant + migration + model/API/lock suites green
  - broader: passed - composer quality-check exit 0, all 45 subgates zero, commit a749f66a09a6455a358a2d74a0f2d6e86d1225fe, dirty false
  - runtime: passed - candidate=a749f66a09a6455a358a2d74a0f2d6e86d1225fe; venue=retained-incus; environment=dev-fixture; expected=closed Orbit|Node|Instance owner invariant persists+resolves, contradictory owners rejected at model guard and DB CHECK, legacy app-scope canonicalized to instance with atomic schedule_runs/schedule_locks re-keying and preserved historical labels, orphan and ambiguous fail-closed; observed=36 proof checks passed 0 failed; result=passed; command=`docker exec -e DB_DATABASE=/tmp/761-proof.sqlite orbit-gateway php /tmp/761-proof.php`; evidence=`.orbit/evidence/solo-761-retained-incus-proof.md`
- Blast radius: complete - evidence=repository-wide rg sweep + model/migration/resolver/API/lock review; result=schedule prod code fully migrated to the closed invariant, only residual 'app' literals are inside the canonicalization migration; see `.orbit/evidence/solo-761-blast-radius-inventory.md`
- Review: passed - human-judgment=not-required; reviewer 2487 VERDICT PASS + BLAST_RADIUS complete; the sole HUMAN_JUDGMENT flag (irreversible orphan-deleting canonicalization) was operator-approved to land as-is, consistent with forward-only canonicalize convention and the todo's explicit deleted-owner policy
- Reviewed feature tip: a749f66a09a6455a358a2d74a0f2d6e86d1225fe
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a749f66a09a6455a358a2d74a0f2d6e86d1225fe
- Accepted main tip: b1b8d80f48cae01d4ff817dc451aa584bf2f56a8

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
