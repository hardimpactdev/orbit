# Todo 763 — Blast Radius Inventory

Candidate: `1d57dcb41193c168e7e7392d383605224a963cf2`
Base: `e8ba9fbb7` (main incl. todo 762)

## Method

Repository-wide `rg` over WorkspaceStep `app_id` consumers: model relation,
policy service, serializers/payloads, workspace-step actions, and the
migration. Excluded `app_id` on the separate Workspace / Instance / ProxyRoute
models (out of scope for [P06-B]).

## Result — complete

WorkspaceStep no longer owns `app_id`; Instance is the sole authoritative owner
and App is derived through Instance:

- **WorkspaceStep model**: removed `@property int $app_id` and `'app_id'` from
  fillable; `app()` changed from `belongsTo(App, 'app_id')` to
  `hasOneThrough(App, Instance, ...)` so App is derived through Instance.
  `instance()` unchanged.
- **WorkspaceStepPolicyService**: the `$step->app_id !== $app->id` check is
  replaced with `Instance::whereKey($step->instance_id)->value('app_id')` and a
  null-or-mismatch guard (fail-closed when the instance is missing); the scoped
  query filter `->where('app_id', $app->id)` is replaced with
  `->whereHas('instance', fn => where('app_id', $app->id))`. The `instance_id`
  authority check is unchanged, so policy decisions are identical for consistent
  rows.
- **Consumers** (AddWorkspaceStep, RemoveWorkspaceStep, WorkspaceStepListPayload,
  WorkspaceStepFactory): migrated off the removed column. AddWorkspaceStep keeps
  a transitional `Schema::hasColumn($step->getTable(), 'app_id')` guard that only
  force-fills `app_id` while the column still exists (pre-migration), and is a
  no-op once the migration drops the column — a self-disabling shim.
- **Migration** `2026_08_18_120000_drop_redundant_app_id_from_workspace_steps_table`:
  SQLite-safe. Left-joins `instances` and FAILS CLOSED (throws RuntimeException
  listing offending step ids) on any missing instance or
  `workspace_steps.app_id != instances.app_id`, then `dropColumn('app_id')`.
- **WorkspaceRunStep**: untouched.

The other `->app_id` references in apps/gateway/app (Process, AppSelectorResolver,
WorkspaceProxyRouteOwnershipResolver, SetupWorkspace, WorkspacesProbe,
EnsureWorkspaceProxyRoute, CreateWorkspace) are on the Workspace / Instance /
ProxyRoute models — not WorkspaceStep — and are correctly out of scope.

Tests: WorkspaceStepAppOwnershipTest (mismatch/missing-instance rejection,
policy equivalence via Instance, migration fail-closed + drop), plus updated
WorkspaceStepActionsTest and WorkspaceStepStoreControllerTest.

Result: complete — evidence=repository-wide rg sweep + model/policy/consumer/migration review.
