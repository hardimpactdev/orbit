# Todo 761 — Blast Radius Inventory

Candidate: `a749f66a09a6455a358a2d74a0f2d6e86d1225fe`
Base: `b1b8d80f4`

## Method

Repository-wide `rg` sweeps for schedule owner authority: legacy `scope === 'app'`,
`app_id` as a schedule owner FK, factory/key composition, resolver/dispatcher/payload
target derivation, and lock identity. Excluded unrelated `app_id` domains
(analytics, websockets, processes, workspaces, proxy-routes, metrics, dependency audit).

## Result

Prod schedule code fully migrated to the closed Orbit|Node|Instance invariant:

- `Schedule` model: closed `ScheduleScope` enum, booted `saving` guard
  (`assertOwnerInvariant`) rejecting contradictory owner FKs, derived
  `liveTargetName()` + `app()` via `hasOneThrough(App, Instance)`. No stored
  `app_id` authority.
- `App::schedules()`: `hasManyThrough(Schedule, Instance)` — App derived, not owner.
- `ScheduleDispatcher::targetNode`/`executionOptions`: exhaustive `ownerScope()`
  match / `isInstanceOwned()`; `recordRun` stamps historical `target_name`.
- `SchedulePayload::list`/`find`/`serialize`: query + serialize on canonical
  `instance` scope and `liveTargetName()`; exhaustive `ownerScope()` match.
- `SchedulesProbe`: compares canonical `instance`/`node`/`orbit` scope values.
- `ScheduleFactory`: `scope=instance`, `instance_id` FK, `instance:` key prefix;
  `app_id` removed from every state.
- `ScheduleRun`: `target_name` label column added (historical run labels).

Only residual `'app'` string literals in schedule prod code live inside the
canonicalization migration (`2026_08_17_200000_constrain_schedule_owner_invariant.php`),
which intentionally recognizes legacy `app` rows to re-key them onto Instance.

## Migration surface

- Legacy `app` scope → `instance` (via existing instance authority), `app_id` column dropped.
- Ambiguous rows (unknown scope / two live owner FKs / instance-owned without a
  resolvable instance_id) fail **before** schema mutation.
- Orphaned schedules (owner deleted) removed; their `schedule_locks` dropped;
  `schedule_runs` keep their historical `schedule_key` + a derived `target_name` label.
- `schedule_runs` / `schedule_locks` keys re-keyed atomically inside one transaction
  when `schedule_key` changes.
- SQLite table rebuild adds a `CHECK` constraint enforcing exactly one owner FK per scope.

Result: complete — evidence=repository-wide search + migration/model/resolver/API/lock review.
