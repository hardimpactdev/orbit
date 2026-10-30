# Direct Operation template

Reuse `extensions/behaviors/operations/_lib/direct.ts`; Routes is the first
consumer. It reads an admitted intent, checks its ID/kind/Node, projects
running, executes one family action, and projects the bounded outcome. A
terminal result is read without repeating the action. An uncertain action or
projection throws; Swamp's native recover/resume owns what happens next. No
local retry, claim, heartbeat, child wait or keyed receipt is added.

A family behavior supplies `DirectArgs.extend({kind: closedKinds, ...})`, its
action and a result validator. The shared Gateway endpoints are fixed. The
action can call a typed local Node primitive instead of a Gateway action. Keep
inputs from admitted intent and vault references; never accept arbitrary command
names or endpoints. Use `GatewayOperation` for the fixed read/result protocol:

```ts
const gateway = new GatewayOperation(
  http,
  args,
);
return await directOperation(
  args,
  gateway,
  (input) => localNodeAction(input),
  resultSchema.parse,
  ctx.signal,
);
```

The Gateway declares its fixed workflow, writer-config array, writer-state field
and a typed projection hook in the family's `direct-operations.php`. New
families use `writer_config: 'swamp.direct_writers'`: one shared
`ORBIT_SWAMP_DIRECT_WRITERS` JSON map keyed by exact kind, default `{}`. Every
family uses the one `ORBIT_SWAMP_RUN_TOKEN`. A family adds no token, controller
or routes. Grant only the approved workflows; review any new token/grant change.
The helper sends `operation_id`, `kind`, `node_id`; workflow CEL passes `run.id`
to each action. It records only the native run identity and product projection,
with no Gateway execution policy.

The declaration names `projection: YourProjection::class`, implementing
`DirectOperationProjection::input(Operation): array` (only safe admitted fields)
and `apply(Operation, array): Operation` (domain validation, then shared
`DirectOperation::project($op, $data, $safeResult, 'fixed-step')`). Optional
`primitive: YourPrimitive::class` implements
`DirectOperationPrimitive::execute(Operation): array`. This transitional hook
runs one fixed typed action from admitted state; it receives no caller command
or input. Local Node steps omit the primitive.

`GatewayOperation(http, args)` uses
`GET /api/v1/direct-operations/{id}?run_id=UUID`, `POST .../result` and optional
`POST .../primitive`. Read returns `id`, `kind`, `node_id`, `status`, `input`,
`result`, `cancel_requested`; `input` comes only from the projection hook.
Result body is `run_id`, `status`, `started_at`, `finished_at`, `result`,
`error_code`. Primitive body is only `run_id`; call `gateway.primitive()`.
`StartSwampRun` records the native run ID after the intent commits. Existing
Route URLs reach the same shared controller during deployment.

Use `swamp workflow create` to obtain the workflow ID, then adapt the existing
`workflow-operation-route-publication.yaml`. Keep one workflow per family with
native steps/dependsOn/forEach, nested workflows only where useful, and explicit
placement on the intended Node pool. Give the behavior a stable target identity
for native locking; job concurrency alone is not a cross-run lock. Mutating
external steps declare `writes: true`. Do not copy the Route migration-pool
label for a Node worker. Native defaults govern concurrency, queueTimeout and
retention; list only the vaults that the family needs (`vaults: []` without
secrets).

Keep the workflow cancellation signal out of bounded Gateway HTTP calls. Check
it before the effect; once a primitive starts, let it return and project its
outcome. Aborting HTTP cannot cancel a Node action already in progress.

Native resume does not guarantee exactly once. Use convergent Node primitives or
observe/reconcile an uncertain external mutation before repeating it. Cancelling
a suspended nested parent can detach its child; settle that child through native
Swamp rather than a Gateway cascade.

Prove E2E on dev/canary before takeover, use one reversible per-kind setting
back to the previous Swamp path, and delete that path only after production
success. This extraction changes no live setting, token/grant, writer or
workflow identity.

Sources:
[workflow model](https://swamp-club.com/manual/explanation/the-workflow-execution-model),
[failure semantics](https://swamp-club.com/manual/reference/remote-execution/failure-semantics),
[idempotency](https://swamp-club.com/manual/how-to/make-workflows-idempotent).
