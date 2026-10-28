# Conditional smoke recovery

Swamp acquires a separate Gateway deploy hold before smoke admission. Missing, unreadable or ambiguous results keep code live. They raise release assistance and the configured alert, then retry with durable exponential backoff (30 seconds to 15 minutes). A local deadline never replaces a late verified result. Read the original receipt until admission is absent or the Gateway operation is terminal; retry absent admissions with the same key/body. Only a terminal unknown creates a new smoke generation. Each generation and its next due time survive restarts.

The shipped recovery effects gate and workflow `apply` input remain false. No project enrollment, live rollback or deploy-writer cutover is enabled by this change. Once enabled, periodic workflow runs advance one bounded recovery tick; native waits persist independently of Services restarts. Services executes policy only; the Gateway owns holds, verification and native deployment operations. Actions continues to run repository tests on the repository's CI runners.

## Paired Gateway contract

All routes below are Gateway-peer API/MCP under `/api/v1/instances/{id}/smoke`. POST requires `Idempotency-Key`, bound to caller, Instance and full body. Hold/release receipts are durable in Instance state; native redeploy receipts use operations.

```ts
type ControlRequest = {
  action: 'hold'|'alert'|'release'|'redeploy', hold_key: string,
  expected: Selection, smoke_request_key: string|null,
  rollback_request_key: string|null
};
type Control = {
  request_key: string, instance_id: int, action: string, hold_key: string,
  held: boolean, resumed: boolean, reason: string|null
};
type ControlReceipt = {request_key:string, found:boolean, control:Control|null};
```

| Route | MCP | Result |
| --- | --- | --- |
| `POST /control` (hold, alert, release) | `instance-smoke-control` | `200 {data:Control}` |
| `GET /control/receipt?request_key=…` | `instance-smoke-control-receipt` | `200 {data:ControlReceipt}` |
| `POST /redeploy` (redeploy) | `instance-smoke-redeploy` | `202 {data:Operation}` |
| `GET /redeploy/receipt?request_key=…` | `instance-smoke-redeploy-receipt` | `200 {data:RollbackReceipt}` with `instance.deploy` operation |

Hold validates active hosted production ownership and public HTTPS Route, snapshots operator controls and existing manual pause, and blocks both automatic admission and queued execution with `409 instance.smoke_held`. It never disables automation. Alert delivery records partial outcomes and allows the same bound request to retry delivery. Release requires a passing executed smoke operation admitted after acquisition for the exact current selection. It clears only the owned hold and assistance. Resume requires unchanged operator controls and cursor, initially enabled/unpaused automation, and no subsequent manual work except this hold's completed recovery. Bad SHA blocks stay permanent. Refusals preserve the hold (`409 instance.smoke_control_invalid`, `instance.smoke_selection_changed`, idempotency/access/queue errors).

A native smoke rollback adds `input.recovery_redeploy_required: boolean` when it cancels a queued automatic deploy. After rollback success, Swamp admits a pinned native redeploy only when that flag is true. Admission and worker execution recheck hold ownership, completed owned rollback and exact restored selection. Then smoke the resulting selection. Failed restored smoke, failed/pruned rollback, or failed redeploy retains the hold and alerts assistance; never walk successively older releases. No auto-revert and no data rollback. Migrations must remain compatible with the retained release.

Tests cover receipt replay, deploy admission and worker holds, conditional resume with operator/manual interference, no-result backoff across restarts, late results, restored smoke, and queued-auto cancellation. Live recovery remains off pending the authorized pilot.
