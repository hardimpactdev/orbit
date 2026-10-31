# Direct operations: one parent, one native run

Nick's 2026-10-31 Big Switch is the governing contract. Kit is removed; an unported kind returns `operation.kind_unavailable`. Management outages are accepted. Do not retain a polled writer or make Gateway child Operations.

## Add a kind in under one hour

1. **Admission:** validate the user's request, Node/target ownership and public input. Record one Operation through `StartOperationAction::execute`. It starts the declared native workflow through `StartSwampRun`. No Gateway executor, queue job, lease, retry loop, completion proof or child admission belongs in the family.
2. **Declaration:** add the kind to the family's `direct-operations.php` (or existing `DirectOperationContribution`). Required fields are `workflow` and `projection`. Do not add `operation-kinds.php` execution definitions, `runs`, `children`, `completion_contract` or `result_fields`. The direct declaration is the registration. Existing writer flags are being removed from the shared admission path; no runtime map mutation is needed.
3. **DAG:** create one `operation-<family>-<verb>` workflow with inputs `operation_id`, `kind`, `node_id`. Pass native `run.id` as `run_id` to each method. The workflow owns preparation, effects, retries, cancellation and failure handling. Compose lower actions as native steps, never child Operations. Every placed step uses exact `orbit-node-N` plus `labels.node: string(N)` for its admitted Node. No fallback worker.
4. **Private material:** use declared `private_material` / `secret_outputs` and `GatewayOperation.withPrivateMaterial` / `storeSecretOutput`. Keep values inside the effect callback. Existing sources and fixed wire serialization remain authoritative. Raw secrets never enter workflow inputs, outputs, logs or resources. Own-Node private access remains the default. Cross-Node custody requires the shared declared-participant interface; do not relax guards or create a family relay while that interface is being completed.
5. **Projection:** implement `DirectOperationProjection::input(Operation): array` with safe admitted input and `apply(Operation, array): Operation` with only the bounded domain writes. Use `DirectOperation::project(Operation, callbackData, safeResult)` to record native status/result. Do not re-run Swamp's validation, verify stages/receipts, check runtime proofs or call an effect. No step receipts are recorded in the Gateway.
6. **Finish:** delete the old executor, child/receipt/completion contracts and polled registrations for this parent. Run system commands in Swamp itself; orbit-agent only observes health, drift and metrics. Delete matching agent action commands in the owning family PR. Run focused tests and a disposable dev canary. Auto-merge on green. Only a NEW secret/credential type requires your own Opus review. Swamp hot-reloads every merge; do not wait for evidence receipts or user approval.

```php
return [
    'example.apply' => [
        'workflow' => 'operation-example-apply',
        'projection' => ExampleProjection::class,
        // 'private_material' => ['material' => 'existing.approved_source'],
        // 'secret_outputs' => ['declared_field' => 'existing.approved_output'],
    ],
];
```

## Shared HTTP boundary

- `GET /api/v1/direct-operations/{operation}?run_id=<native UUID>` reads safe intent and cancellation.
- `GET .../{operation}/private-material?run_id=<native UUID>` reads only the declared private fields.
- `POST .../{operation}/secret-output` stores only declared protected fields.
- `POST .../{operation}/result` records `run_id`, native `status`, timestamps, bounded `result`, and optional `error_code`.
- The shared layer checks caller authority and the bound root UUID. It does not revalidate native work. Public `operation:list`, `operation:show` and `operation:cancel` remain. Executor/claim/run/step/child/receipt APIs do not.

`POST .../{operation}/failure` accepts only `run_id` from the exact root Node and records fixed `operation.native_failed` without family projection. A separate root-placed failed-dependency job calls `@nckrtl/operation-failure` v2026.10.31.1 `report` with the four direct arguments. This covers upstream behavior rejection. Pinned Swamp does not provide a durable terminal outbox; delivery failure still needs native retry and must not be claimed crash-safe.

`directOperation` reads the intent before the family action. A recorded cancellation skips the action and projects cancellation. A workflow must also project an engine-level failure that occurs before a behavior can callback; this is shared native failure handling, not a Gateway poller.

## Multi-Node public and private steps

Admission stores immutable `participant_node_ids` (positive Node IDs; include every effect/issuer/guest Node). Own-Node access stays the default. A family may implement the shared `DirectOperationPublicView` hook for fresh public input selected by the authenticated admitted participant; use the same GET and root run ID. Only the root Node posts the parent result.

For each delegated declared private field, admission stores `private_bindings[field] = {owner_node_id, read_node_ids, write_node_ids}`. Every ID must be an admitted participant. `owner_node_id` selects the material's owning Node; reader/writer IDs select only that field's consumers/producers. The fixed declared source/output mapping remains mandatory. The shared private layer enforces this binding; request callers cannot supply or change it. Existing sources use the shared field-owner binding when checking their Node custody. This is the shared implementation being added in the Kit PR; do not build a family transport or widen the old global peer guard.

TaskVM enrollment uses the existing worker credential type: issuer209 writes only the declared credential field for the admitted guest owner; the exact admitted bootstrap host reads that field inside its existing effect callback. The guest is not added to fleet worker rollout. Public readiness follows on the guest's exact worker. No token, issuer credential or CA key is exported through public input.

The certificate primitive POST stays `run_id`-only. The root records public `result.certificate_requests[{node_id,scope,request_base64}]` through its running result callback. The existing generic signer resolves immutable admitted `input.certificates[]`, checks that public request, and returns its typed public output directly. No shared result-field filtering applies to primitive output. The leaf private key stays at the effect Node and the CA key stays in its existing Gateway custody. The family owns the signer and CSR storage; no new endpoint, body schema or credential type is introduced.

## Ownership

Lane 16 owns `StartOperationAction`, `StartSwampRun`, `DirectOperation*`, the private boundary and Kit deletion. Family owners own declarations, admission payloads, native workflows and minimal domain projections. Node-role owners own `NodeFootprintProjection` and `NodeConvergeProjection`; lane 16 will not rewrite them. TaskVM enrollment's issuer-to-host private handoff is shared work, not a reason to retain Kit.

Report only: merged PR, dev-canary Operation ID, Gateway net lines.


## Submission failures

`StartSwampRun` makes one synchronous submission after the intent commits. It reserves RUNNING under a row lock before opening the socket, then binds the native root. Configuration/connect failures and explicit refusal record terminal errors. A lost send/reply records `swamp.start_uncertain`; this is not a no-effect claim. Replay returns the same record without history recovery or resubmission. Native callbacks preserve terminal outcomes.
