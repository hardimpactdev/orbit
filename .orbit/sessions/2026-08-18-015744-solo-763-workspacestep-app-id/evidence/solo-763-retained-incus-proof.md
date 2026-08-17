# Todo 763 — Retained-Incus Runtime Proof

- Candidate: `1d57dcb41193c168e7e7392d383605224a963cf2`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `operator_gateway_app-dev` id `dev-e1dc32` (host `beast`)
- Bind: sha256 of `WorkspaceStep.php`, `WorkspaceStepPolicyService.php`, and the
  drop-app_id migration match between the worktree and the gateway VM mount
  `/home/orbit/orbit`.
- Runtime: `orbit-gateway` container (PHP 8.5).

## Part A — migration applied fail-closed on the retained gateway DB (read-only)

Queried `PRAGMA table_info(workspace_steps)` on the retained gateway SQLite
(`/home/orbit/.config/orbit/gateway.sqlite`).

Observed columns: `id, phase, sort_order, command, timeout_seconds, created_at,
updated_at, instance_id` — **no `app_id`**. The drop-app_id migration ran to
completion on the retained gateway data, which means its fail-closed audit
(throw on any `workspace_steps.app_id != instances.app_id` or missing instance)
found zero violating rows and then dropped the column. `instance_id` is the sole
owner FK.

## Part B — ownership behavior on the deployed runtime

Ran the focused workspace-step Pest inside the `orbit-gateway` container against
an isolated disposable test DB (`DB_DATABASE=/tmp/763-test.sqlite`,
RefreshDatabase-migrated), NOT `composer test:e2e*`:
`WorkspaceStepAppOwnershipTest`, `WorkspaceStepActionsTest`,
`WorkspaceStepStoreControllerTest`.

Observed: **18 passed (80 assertions)** — proving on the retained-topology PHP 8.5
runtime:
- App is resolved through Instance (hasOneThrough) and equals the prior app_id
  behavior for consistent rows;
- the policy service allows/denies identically via the Instance-derived owner app
  and the Instance-scoped query, with the instance_id authority check unchanged;
- the migration FAILS CLOSED (throws listing offending step ids) on a
  `app_id != instance.app_id` mismatch or a missing instance, and drops the
  column when consistent;
- deleted/missing-instance relations are handled safely.

Result: passed.
