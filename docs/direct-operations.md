# Direct Operation template

Reuse `extensions/behaviors/operations/_lib/direct.ts`; Routes is the first
consumer. It reads an admitted intent, checks its ID/kind/Node, projects
running, executes one family action, and projects the bounded outcome. A
terminal result is read without repeating the action. An uncertain action or
projection throws; Swamp's native recover/resume owns what happens next. No
effect retry, claim, heartbeat, child wait or keyed receipt is added.

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
`DirectOperationPrimitive::execute(Operation): array`. This hook is reserved for
generic Gateway primitives such as Route/Caddy publication or scoped token
minting. New family execution relays are refused. Family actions run locally on
their Node worker and omit the primitive hook; a fixed command name alone does
not make a relay generic. Gateway primitive input comes from admitted state,
with no caller command or input.

`GatewayOperation(http, args)` uses
`GET /api/v1/direct-operations/{id}?run_id=UUID`, `POST .../result` and optional
`POST .../primitive`. Read returns `id`, `kind`, `node_id`, `status`, `input`,
`result`, `cancel_requested`; `input` comes only from the projection hook.
Result body is `run_id`, `status`, `started_at`, `finished_at`, `result`,
`error_code`. Primitive body is only `run_id`; call `gateway.primitive()`.

For an existing pinned file, a declared private-material source may implement
`OperationArtifactSource`. The private read returns a reference containing its
relative private-read path, SHA-256 and byte count, bounded by 256 MiB. Use
`gateway.artifact(field, admittedSha256)` on the admitted Node worker. The
shared helper accepts only that Operation/run/field path, bounds the download
and checks its digest before returning bytes in memory. It adds no public URL,
inline JSON binary, family route or execution relay. The same private endpoint
serves the declared artifact with `artifact=FIELD`; Node, run and terminal
checks still apply.

`StartSwampRun` records the native run ID after the intent commits. Existing
Route URLs reach the same shared controller during deployment.

The Gateway alone binds the run ID from its native start response or an exact
native history match. It tags each submission with the stable
`orbit_start_key: operation:<id>`. After a lost response it reads native run
history once by workflow, key and Operation ID. Only confirmed absence permits
one immediate resubmission with the same inputs and key. Native tags support
lookup, not atomic start deduplication. There is no Gateway retry job or poll.

Read, result and primitive requests refuse until that binding is recorded.
Projection never establishes or replaces it. Each canary verifies
start-response/first-read ordering. Per-Node callers in `worker_node_ids` must
match the admitted `node_id`; foreign and Gateway-scoped intents are refused.
The configured control-plane peer retains its existing fleet authority; joining
the worker list grants no such authority.

Swamp's first read waits only for `409 operation.run_unbound`, once per second
for up to 90 seconds. This covers the measured 29.316-second native history
lookup plus the 30-second lost-start response budget. Cancellation stops this
wait before any effect. A mismatch, access refusal, transport error or any
mutation fails immediately. Remove this binding wait when native admission and
Gateway binding have an atomic handoff. The exception is recorded in PLAN.md;
serve, worker and queue defaults stay unchanged.

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

During a bounded read-only wait, the family rereads `gateway.read()` between
status reads and before accepting the final result. If `cancel_requested` or the
native signal requests cancellation, the action may throw
`new DirectOperationCancelled({ effectRunning: false })` after confirming that
no effect is running. Import the sentinel from the shared template. Only this
sentinel makes the shared helper project `cancelled` with `{}`. An ordinary
error or native abort during a mutation remains uncertain and propagates. A
successful mutation still projects success after a late abort.

Native resume does not guarantee exactly once. Use convergent Node primitives or
observe/reconcile an uncertain external mutation before repeating it. Cancelling
a suspended nested parent can detach its child; settle that child through native
Swamp rather than a Gateway cascade.

Prove each kind E2E on dev/canary before takeover. Its owning PR selects the
direct writer and deletes the replaced Gateway execution path and tests
together. Rollback is a revert PR. Remove obsolete polling definitions after the
release is live, while preserving native history and data.

Sources:
[workflow model](https://swamp-club.com/manual/explanation/the-workflow-execution-model),
[failure semantics](https://swamp-club.com/manual/reference/remote-execution/failure-semantics),
[idempotency](https://swamp-club.com/manual/how-to/make-workflows-idempotent).

## Scoped GitHub credentials

A checked-in declaration can add
`github_token: {repository: "owner/name", permissions: {contents: "write", pull_requests: "write"}}`
and `private_material: {github: "github.installation"}`. Admission records the
owned reference
`github: {$secret: "github.installation", id: <Operation Node ID>}`. The Gateway
accepts only the closed permission names `contents`, `pull_requests`, `checks`,
`actions`, `metadata`; the last three are read-only. This is a
credential-authority change requiring review; no family is enabled by the shared
helper PR.

Deploy the Gateway consumer first. The shared
`GatewayOperation.withGitHubToken(async (token, grant) => safeResult)` calls the
shared `GET /api/v1/direct-operations/{id}/private-material?run_id=UUID` through
`withPrivateMaterial`, reading `data.github` inside its effect callback. No
family controller, route or token is added. Caller Node/run binding and direct
intent apply; terminal or cancelled intents refuse. The private `no-store` reply
holds exactly the admitted repository/permissions and a fresh token expiring
within about an hour. Signing keys remain on the Gateway, with no shared worker
cache or public `result_fields` change.

Mint once per native step, outside item loops; use one `GatewayOperation`
instance per step. Further mint attempts on that instance refuse. Use the
credential only inside that callback, in trusted fixed GitHub/git calls, never
package or repository code. Do not log, persist or return it from a behavior.
The shared helper attempts to revoke the token at the fixed GitHub
`DELETE /installation/token` endpoint when the callback ends, including errors,
then drops its reference. Revocation is best effort: failure reports only
`operation.github_token_revoke_failed` and preserves the action's result or
refusal. A successful GitHub write must not fail because revocation failed. The
expiry check uses the client's injected clock. The helper refuses callback
results containing the credential and replaces thrown diagnostics with fixed
codes. Family results remain bounded and secret-free. Native Swamp owns retry
policy; token minting adds no claim, lease, receipt or retry loop.

Mint callers match their Operation Node. The shared private reader has no
control-plane exception: Node209 cannot mint for a Gateway14-owned intent.
Control-plane consumers must admit the intent on the executing Node. Safe intent
reads and result projection retain their existing authority. Each successful
mint records one token-free Gateway Activity containing Operation, run, caller
Node, repository, permissions and expiry.
