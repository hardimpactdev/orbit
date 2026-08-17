# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p20-a-require-analyt--762`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-762-analytics-postgres-process-id`
- Branch: `solo-762-analytics-postgres-process-id`

## Goal

Analytics PostgreSQL endpoint resolution requires a stored postgres_process_id: the transitional missing-ID single-process fallback in AnalyticsProcessEndpointResolver is removed so a missing identity fails closed (like the already-failing stale-ID and multiple-process ambiguity paths), backed by a migration-completeness diagnostic that proves/repairs the 2026_07_19_030000 backfill for supported installations. ClickHouse service discovery (no stored process id) is unchanged.

## Scope

- Owned: apps/gateway AnalyticsProcessEndpointResolver missing-ID branch (remove the unique-process fallback only when a process-id setting is expected; keep stale-ID and ambiguity errors), a migration-completeness diagnostic that detects analytics assignments lacking postgres_process_id (home it with the existing analytics Doctor probes), AnalyticsRoleSettings validation reinforcement if needed, and focused Pest covering valid IDs, missing IDs (now fail closed), stale IDs, multiple PostgreSQL processes, the removed single-process fallback, and unchanged ClickHouse discovery.
- Constraints: preserve existing stale-ID and ambiguity error shapes/messages; keep ClickHouse discovery (processIdSetting === null) untouched; the resolver stays generic; declare(strict_types=1); Mago/Rector clean; keep serialized/endpoint behavior identical for valid stored IDs. Prove backfill completeness on the retained topology before requiring presence. Do NOT run composer test:e2e*.
- Out of scope: ClickHouse identity model, PostgreSQL 16 plausibility check changes, analytics proxy/route registrars, and the backfill migration body itself (already landed).

## Proof

- Verification:
  - focused: passed - gateway Pest analytics resolver/role-settings/doctor-probe suites green
  - broader: passed - composer quality-check exit 0 on amended candidate 380deb1c3, all 45 subgates zero
  - runtime: passed - candidate=380deb1c350da11032c439ac4da26ed398a67b69; venue=retained-incus; environment=dev-fixture; expected=analytics postgres endpoint resolution requires a stored postgres_process_id (missing id fails closed), stale-id and multiple-process ambiguity errors preserved, ClickHouse single-process discovery unchanged, read-only migration-completeness diagnostic flags missing-id assignments, and the 2026_07_19_030000 backfill is complete on the retained gateway DB; observed=Part A backfill complete (0 analytics assignments missing postgres_process_id on the retained gateway DB) + Part B 42 analytics tests passed on the retained-topology gateway runtime; result=passed; command=`docker exec -e DB_DATABASE=/tmp/762-test.sqlite orbit-gateway php artisan test tests/Unit/Services/Analytics tests/Unit/Data/Nodes/RoleSettings`; evidence=`.orbit/evidence/solo-762-retained-incus-proof.md`
- Blast radius: complete - evidence=repository-wide rg sweep of AnalyticsProcessEndpointResolver callers + postgres_process_id consumers + ClickHouse path; result=change scoped to the resolver missing-id guard (postgres path via sole caller PlausibleRuntimeConfig), a read-only Doctor diagnostic, and already-present role-settings validation; see `.orbit/evidence/solo-762-blast-radius-inventory.md`
- Review: passed - human-judgment=not-required; reviewer 2489 VERDICT PASS + BLAST_RADIUS complete; two non-blocking notes explicitly required no fix (drift diagnostic node-branch is theoretical since analytics is a distinct workload role and the resolver fail-closed guard covers resolution regardless; constant naming matches AnalyticsPublicProxyDoctorProbe precedent)
- Reviewed feature tip: 380deb1c350da11032c439ac4da26ed398a67b69
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 380deb1c350da11032c439ac4da26ed398a67b69
- Accepted main tip: a9afe594f89f202841398a4c04b3f54892706556

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
