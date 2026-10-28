# Smoke failure block and guarded rollback (slice 3a)

Goal: a verified failing smoke permanently blocks its deployed SHA before a guarded code rollback. Keep main and data unchanged; migrations must remain compatible with the previous release. Missing or ambiguous smoke is `hold_required`, never a block or rollback. The deploy-writer hold, assistance/alerts, retry generations, restored smoke, conditional resume and repair tasks belong to slices 3b/4.

The existing smoke behavior gains `failure({apply:false, deploymentId?:int})`, called after the native smoke wait/result. Omit deploymentId for the current intent; supply the historical deployment ID after supersession. The permanent job is authoritative. It snapshots the last smoke-passed selection when admitting a new smoke. It re-reads the keyed Gateway receipt, checks identity and executed failing JUnit, and plans recovery. `apply:true` also requires a release-level effects gate; that gate stays **false** in the shipped behavior. No project or schedule is enabled. Fixture-only instances enable effects to prove the protocol. Services runs policy only; Gateway owns database and Instance effects. Use the existing `instance.rollback` implementation, not a new shell/deploy path.

## Orbit half to implement and pair

Gateway-peer API/MCP uses existing authorization/envelopes. Positive integer IDs, full SHA40, required nullable fields and `Selection`/smoke receipts retain slice 2 definitions. Add:

```ts
type Failure = Selection & {smoke_request_key: string};
type Block = {request_key: string, instance_id: int, failure: Failure, blocked: true};
type BlockReceipt = {request_key: string, found: boolean, block: Block | null};
type Rollback = {
  failure_key: string, expected: Selection, target: Selection,
  target_smoke_request_key: string
};
type RollbackOperation = {
  id: int, kind: 'instance.rollback', target_type: 'instance', target_id: int,
  input: Rollback, status: 'queued'|'running'|'waiting'|'succeeded'|'failed'|'cancelled',
  error_code: string | null // other existing OperationData fields are allowed
};
type RollbackReceipt = {
  request_key: string, found: boolean, operation: RollbackOperation | null,
  deployment: (Selection & {status:'running'|'succeeded'|'failed'}) | null
};
```

| Route below `/api/v1/instances/{id}/smoke` | MCP | Result |
| --- | --- | --- |
| `POST /failure` with Failure, required Idempotency-Key | `instance-smoke-failure` | `200 {data: Block}` |
| `GET /failure/receipt?request_key=…` | `instance-smoke-failure-receipt` | `200 {data: BlockReceipt}` |
| `POST /rollback` with Rollback, required Idempotency-Key | `instance-smoke-rollback` | `202 {data: RollbackOperation}` |
| `GET /rollback/receipt?request_key=…` | `instance-smoke-rollback-receipt` | `200 {data: RollbackReceipt}` |

Block key is `smoke-failure.<instance>.<deployment>`; rollback key appends `.rollback`. Bind both keys to authenticated caller, Instance and full body. GET never creates effects. `found:false` has null evidence. Replay identical admission returns the original receipt; changed input/caller/target refuses. Swamp saves each exact intent before any POST, looks up first, and can safely repeat that same admission after a lost reply or restart. Gateway must not execute a second rollback for a replay.

The failure action independently reads the caller-bound smoke key and verifies the failed deployment identity, repository, public Route and failing executed JUnit (positive non-skipped tests, failure/error > 0, run/attempt/workflow/target proof). A workflow conclusion alone cannot block. Verify ownership even if a newer release is selected. Under the Instance deploy-writer lock and in one durable transaction, record the keyed failure and add its SHA to `auto_deploy_state.blocked`; expose through `auto_deploy.blocked`. Commit this before rollback admission. This block survives moved selection, missing/pruned rollback targets and failed recovery. Resume never clears it. Do not pause/resume automation as a side effect of this block action.

Rollback admission requires the caller-bound failure key and permanent block, current selection exactly `expected`, and the requested target's retained release and prior successful smoke receipt. Validate both target SHA and release; never choose an arbitrary older release. Take the existing Instance deploy lock. Recheck these guards immediately before effects under that lock, including if queued after admission. Changed selection or pruning refuses without code changes. Use the native rollback operation and existing manual-pause semantics; do not auto-resume. Keep its receipt/deployment, and verify selected target SHA/release after success (the rollback deployment ID is new). Never write an auto-revert commit or change data.

Refusals: `409 instance.smoke_selection_changed`, `409 instance.smoke_rollback_target_missing`, `422 instance.smoke_failure_invalid` (unverified failure/ownership), `422 instance.smoke_rollback_invalid` (unproven target/body); preserve operation lock/access/queue/idempotency errors. Failed/cancelled rollback or unreadable receipt requires assistance and no new target; retain the block and exact key.

## Failure states and proof

No result, pending/unknown/cancelled smoke, deadline, HTTP outage or unreadable/mismatched evidence produces `hold_required`, no external mutation. A real failure can record its block even after the selected release moves; rollback cannot. Missing prior baseline or pruned target requires assistance after the block. Restarts reconcile permanent incident resources and receipts. A lost admission reply never changes body/key or target. Disabled planning reads only existing smoke/context; it never probes unsupported effect routes. Tests must cover these states, ordering, forged receipts, changed selection between block/rollback, queued execution refusal, pruning, pause preservation and idempotent replay. Swamp fixtures prove policy; the paired Orbit tests must prove the real transaction and execution guards. No live rollback is authorized.
