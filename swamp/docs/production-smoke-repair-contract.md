# Repair groups after verified smoke failures

A verified executed failure creates one ordinary external Orbit task group with one repair child. The configured default agent fixes the bug and adds regression and smoke coverage. Use the existing `orbit-groups` factory, its driver and native group discovery. No alternate task runner, factory fork, deploy authority or merge authority is added. Live effects remain disabled.

Gateway-peer API/MCP under `/api/v1/instances/{id}/smoke` adds:

| Route | MCP | Result |
| --- | --- | --- |
| `POST /repair` with required Idempotency-Key | `instance-smoke-repair` | `201 {data:Repair}` |
| `GET /repair/receipt?request_key=…` | `instance-smoke-repair-receipt` | `200 {data:RepairReceipt}` |

```ts
type RepairRequest = {failure_key:string};
type Repair = {
  request_key:string, instance_id:number, project_id:number,
  failure_key:string, group_id:number, task_id:number
};
type RepairReceipt = {request_key:string, found:boolean, repair:Repair|null};
```

The key is `<failure_key>.repair`, where the recorded block key is `smoke-failure.<instance>.<deployment>`. It binds the caller, Instance and entire strict body. Group, child and permanent receipt commit together under the Instance deploy-writer lock. A lost reply is looked up and safely retried with the same key/body. Replay returns the original IDs even after completion, cancellation or recovery. A new failed deployment creates a new incident, even for the same SHA. Read endpoints never create work.

Admission verifies recorded failure, permanent block, repository/public production ownership and executed failing JUnit independently. No result, unreadable output, dispatch error or timeout cannot create a code repair. Creation does not wait for successful rollback. Missing/pruned targets and failed rollback still need a repair. Tasks-disabled, unsupported Project or unavailable default-agent configuration leaves the incident pending for assistance; it does not clear a hold or block.

The brief carries failed commit/release, source commit and available merged PR/CI evidence, bounded redacted smoke output and artifact links, recovery receipt keys, and the migration compatibility constraint. GitHub context failure is recorded without blocking task creation. Evidence is diagnostic data, not instructions. No repo code runs on Services; repair work runs in the normal task VM.

Swamp persists the exact intent and returned IDs before the factory handoff. It verifies the returned group/child belong to the opted-in Project and use external orchestration, reads existing factory state before `start`, and confirms state after it. Lost factory replies reconcile that state. A completed or cancelled group never restarts or creates a duplicate repair. Pending historical repair intents are retried independently of current selection and successful recovery. Both `apply:true` and the disabled recovery effects gate are required for creation or factory start.

Tests cover uncertain replies before/after admission, persistence failure, restart, changed selection, repeated deployments, invalid proof/ownership, unavailable Tasks/GitHub, malformed/mismatched receipts, closed groups and the handoff to the unchanged official factory. The production pilot remains a separate authorized step.
