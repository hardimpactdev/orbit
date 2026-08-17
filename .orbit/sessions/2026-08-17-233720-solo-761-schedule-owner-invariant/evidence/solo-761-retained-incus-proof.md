# Todo 761 — Retained-Incus Runtime Proof

- Candidate: `a749f66a09a6455a358a2d74a0f2d6e86d1225fe`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `operator_gateway_app-dev` id `dev-afb7b3` (host `beast`)
- Bind: sha256 of `apps/gateway/app/Models/Schedule.php` and the canonicalization
  migration match between the worktree and the gateway VM mount `/home/orbit/orbit`.
- Runner: `/tmp/761-proof.php` executed inside the `orbit-gateway` container against a
  disposable copy of the live gateway SQLite (`DB_DATABASE=/tmp/761-proof.sqlite`),
  booting the deployed Laravel runtime (PHP 8.5).

## Result: 36 passed, 0 failed — ALL PROOF CHECKS PASSED

### Part A — live invariant (real models, disposable live-DB copy)
- `schedules.app_id` column dropped on the deployed schema.
- Orbit scope persists with no owner FKs; derives `gateway` target; App derivation null.
- Node scope persists only `node_id`; derives the node name target.
- Instance scope persists only `instance_id`; derives `proofapp.prod`; App via
  `hasOneThrough`; `App::schedules()` hasManyThrough resolves; key uses
  `instance:proofapp.prod:` prefix.
- Resolver resolves the instance schedule to its placement node.
- Model guard rejects `orbit+node_id` and `node scope + instance_id`
  (`ScheduleOwnerInvariantViolation`).
- DB CHECK constraint rejects a raw contradictory owner-FK update.
- Owner-instance delete cascades the schedule; the historical `schedule_run` keeps its
  `schedule_key` and derived `target_name` label.

### Part B — legacy canonicalization migration (disposable legacy schema)
- Drops `app_id`; legacy `app` scope → `instance`, keeping `instance_id`, nulling
  `node_id`, re-keying to `instance:docs.development:...`, deriving `docs.development`.
- `schedule_runs` and `schedule_locks` re-keyed atomically; historical run label stamped.
- Node/orbit scopes retained with refreshed derived targets.
- Orphaned schedule (owner already deleted) removed; its run keeps the historical key +
  label; its lock dropped.
- Post-migration CHECK constraint rejects a contradictory owner FK.

### Part C — ambiguous fail-before-mutation
- A legacy row with two live owner FKs throws (`schedule_id=1`) before any schema
  mutation; `app_id` column and `scope` remain untouched.
