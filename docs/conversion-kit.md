# Convert an Orbit operation family

Swamp executes operations. Orbit validates requests and stores intent, Operations and receipts. Reads stay in Orbit.

1. Read the family's existing tests. Preserve input validation, ownership, errors and result shapes.
2. Add each write kind to its Orbit family registration with `executor: swamp`. Keep the normal CLI and MCP command. Use `StartsOperation` and the existing Operation idempotency boundary. Return 202 after recording intent and target. Never dispatch a PHP executor for the kind.
3. Keep one directory per family under `extensions/behaviors/operations/<family>/`. Put its behavior in `mod.ts`, its typed contract beside it, and its focused tests in that directory. Give its workflow a unique `workflows/workflow-operation-<family>.yaml` file accepting `kind` and a stable `worker` claim key. Place its single claim-and-execute behavior invocation on the intended worker pool. The shared runner lives in `extensions/behaviors/operation-runner/`. Services reads claimable kinds without claiming. Its operation-dispatch workflow expands kind names and starts each family on its placed worker; families never edit this helper.
4. Register the kind and family workflow in the family's own registration file. Do not edit another family's files.
5. Services alone reads `GET /api/v1/operation-executor/claimable`. Its `{data:{kinds:[{kind,claimable,oldest_at}]}}` response contains counts and times, never Operation IDs or inputs. Dispatch only registered kinds with a positive count. The placed family behavior must claim its kind and execute the claimed Operation in one invocation. Claim, heartbeat, step, run and completion all come from that same authenticated worker Node. An empty claim ends without writes. A worker lost during execution may be replaced only through normal expired-lease reclaim; there is no lease handoff. Use the Gateway primitives at `/api/v1/operation-executor`. Reuse the existing RemoteProgram or agent command; never send script source or create another job store.
6. Use one permanent operation identity and stable effect keys. Persist an intent before an effect. Retry a lost response with the same key and payload. Resume successful steps from their recorded receipt. Reconcile uncertain effects with the target before doing new work. Never treat a transport error as proof that an effect did not happen.
7. Delete the PHP executor and the tests that exercise that executor. Retain and update admission and read tests. Add Swamp success, refusal, lost-reply, expired-lease and restart tests.
8. Run only the affected tests, format, lint and type checks. Use at most four workers.
9. Ship an Orbit PR and an orbit-swamp PR together. Do not merge. The orchestrator merges and deploys the pair.
10. After deployment, invoke the normal command on a designated dev Node. Read the Operation and show its steps and final result. For firewall, allow a high port from a documentation-only source, remove that exact rule, and confirm removal. Ask the orchestrator before new access or production data changes.

## Mandatory Gateway contracts

Every family must declare these rules in its Gateway `operation-kinds.php`. Client-side checks do not enforce the trust boundary.

- `runs` is a closed list of `transport` and `name` pairs. Each entry declares `input_fields` copied from admitted Operation input, `input_values` for fixed values, and `node_field` when the command input includes the target Node. The Gateway checks the real agent input JSON Schema, rejects unknown fields, and requires exact equality with these bindings. RemotePrograms must declare an explicit `input_schema` in the entry. No run is allowed without a contract.
- The Gateway takes the transport target from `Operation.node_id`. The run body must omit `node_id`; a caller-supplied target, unlisted command, invalid schema or changed intent receives `operation.execution_forbidden`. A Node ID inside typed command input must equal the Operation target. Do not authorize a broad command whose fixed input can execute unrelated work.
- `completion_contract` names the required `step`, `transport`, `name` and successful `result`. A successful step must match a Gateway-recorded successful run receipt. A successful completion must match that step and receipt. Bare completion, a forged step and the wrong backend receive `operation.completion_unproven`. A completion hook projects intent only after this generic check. Firewall requires `ufw-converge` with `backend: active`, or `ufw-remove` with `backend: absent`.
- `result_fields` is a closed list of scalar leaf paths or bounded typed lists, using dots for nested paths. Include `error_code` for command refusals and `operation.cancelled`. The Gateway drops undeclared top-level fields and rejects invalid typed lists, and applies its existing secret redactor to retained values, step output and error codes before persistence or return. Never allow an unbounded container, raw stdout, environment or credential field. Tokens must never reach Operation state, steps, results or responses. Test secrets in run results, step output and completion payloads.

Tests must reject another Node, an unlisted agent command and RemoteProgram, malformed input, changed admitted intent, missing Services identity, missing or forged completion evidence, and secret-bearing receipts. Verify allow, deny and remove with proven receipts and keyed retries.

## Reference family

Firewall writes are `firewall.allow`, `firewall.deny` and `firewall.remove`. The family calls `firewall.rule` with the recorded name, source, protocol, port, action and Node ID. `converge` is safe to repeat; `remove` is safe after an already successful removal. An inactive UFW backend fails with `firewall.backend_inactive`. The Gateway projects completion onto the existing FirewallRule intent. List, live-list, managed-list and fleet-list keep their existing Orbit read paths.

## Orbit files

Use lane 0c's family layout:

- `apps/gateway/app/Domain/<Family>/operation-kinds.php`: kind declarations. Each Swamp entry declares `executor`, `completion`, `runs`, `completion_contract` and `result_fields`; copy the firewall declarations as a starting point. The completion hook writes Gateway intent only; it does no Node work.
- `apps/gateway/app/Actions/<Family>/`: admission and read actions.
- `apps/gateway/app/Http/Controllers/Api/`: normal family endpoints. Mark each write with `StartsOperation('<family>.<verb>')` and return OperationData with status 202. Keep input and target validation before admission. Resolve a deletable named target after the shared idempotency middleware so a lost-reply retry still finds the receipt.
- `apps/gateway/routes/api/<family>.php`: family routes. The loader supplies `/api/v1`.
- `apps/gateway/app/Providers/Domains/<Family>ServiceProvider.php`: family bindings.
- `apps/cli/app/Support/CommandVocabulary/<family>.php`: vocabulary when needed.
- `packages/php-sdk/src/Requests/<Family>/`: return OperationResponse for writes. Pass Idempotency-Key unchanged. The CLI uses FollowsOperations.

Generated OpenAPI, MCP, web schema and docs navigation outputs are built by the foundation's generation path. Do not edit or commit them in a family conversion.

## Primitive contract

Requests use JSON under `/api/v1/operation-executor`. The caller must be the active Services peer configured by `dependency_detection.services_node_id` or an explicitly configured `operation_executor.worker_node_ids` peer, with Gateway access. The authenticated claiming Node and worker string own the lease. This does not change dependency-token authorization or grant new access. MCP tools are generated from the same API and enforce the same identity.

| Suffix | Body | Receipt |
| --- | --- | --- |
| POST `claim` | `kind`, `worker`; optional `operation_id` | One Operation with its input, Node ID, steps, step results, worker and 120-second lease; null if no work is available. |
| POST `{operation}/heartbeat` | `worker` | Current Operation; renews its lease. |
| POST `{operation}/step` | `worker`, `key`, `name`, `status`; optional `output`, `result`, `error_code` | Appends output to the named step and records its status under the existing Operation sequence. Status is running, succeeded or failed. Failed requires an error_code matching `^[a-z0-9_.-]+$`. |
| POST `{operation}/run` | `worker`, `key`, `transport`, `name`, `input` | Only the kind’s declared, redacted result fields from the named agent command or RemoteProgram. Use a registered transport: built-ins are agent, remote_program and gateway. The stored Operation supplies the Node; a Gateway command may have a null Node. Named-command and request deadlines apply. |
| POST `{operation}/children` | `worker`, prepared `key` | Same stamped child; owning admission once. |
| GET `{operation}/children` | None | Bounded receipts for that parent’s children. |
| POST `{operation}/wait` | `worker`, child `keys` | Releases worker; keeps target hold. |
| GET `{operation}/receipts/{step}` | None | Original sanitized effect receipt. |
| POST `{operation}/complete` | `worker`, `status`; optional `result`, `error_code` | Terminal Operation and projected family intent. Status is succeeded, failed or cancelled. Failed requires an error_code matching `^[a-z0-9_.-]+$`. |

Use a stable workflow-run identity as worker. The runner appends the kind, so each claim can be replayed independently. Heartbeat every 30 seconds while a family runs. A new worker can reclaim an expired Operation. A stale worker receives `operation.lease_lost` and stops. PHP jobs and the PHP recovery sweep leave Swamp kinds alone.

Step event keys identify exact payloads. A replay appends no duplicate output. Run keys bind the named command, target and input before execution; a different payload returns `operation.idempotency_conflict`. A successful result is reused. If the process dies before saving the result, the default is an uncertain refusal with no saved result or projection. Only an explicitly resumable convergent command may repeat; other effects require a proven original receipt. The Gateway serializes invocations on an Operation with its existing attempt lease. It does not promise exactly-once execution of an arbitrary remote program. Output keeps the existing 8 KiB tail.

Persist completion intent in Swamp data before posting complete. A lost completion reply replays the same receipt; it must not start another Node command. Terminal Operations reject a different completion. The Gateway rechecks status, lease and cancellation immediately before a new Node command. It records a keyed `operation.cancelled` refusal when cancellation wins. Finish that step as failed and complete the Operation as cancelled; never retry the refused effect. Cancellation before a Node effect returns cancelled. Once an effect has a proven result, finish recording that result.

## Swamp files

Copy `extensions/behaviors/operations/firewall/` to the new family directory. Change `registration.json`, the type name, input contract, steps and named commands. The generic runner scans these registration files in sorted order and rejects duplicate kinds. Discovery reads the Services-only claimable summary and dispatches registered kinds with available work. Each placed family invocation claims on its worker. The typed `Executor.claim` helper returns no work for `operation.executor_invalid`, `operation.kind_unavailable` or `operation.child_unproven`; authentication and unrelated transport failures remain errors. This permits static family registration while its Gateway kind is capability-gated. Each entry supplies `kinds` and the name of its family workflow. Add one uniquely named `workflows/workflow-operation-<family>.yaml`; the pinned Swamp release discovers workflow YAML files at that level. Keep all family code and tests in the family directory. Do not edit the generic runner to add a family.

Run the focused Deno tests, format, lint and type checks. Validate both operation-runner and the family workflow with the pinned Swamp CLI. Test a process restart after each uncertain boundary: after admission, after a Node effect, and after completion. Demonstrate that the same keys and saved receipts are reused. Use the existing `swamp-testing` test context and recover/resume a native workflow before claiming native recovery proof.

## Live firewall proof after paired deployment

Choose the dev Node with the orchestrator. Use a stable unique name and high port. These examples use the documentation-only address `192.0.2.1/32`:

```sh
orbit firewall:allow swamp-conversion-proof --node=DEV_NODE_ID --from=192.0.2.1/32 --protocol=tcp --port=65431 --idempotency-key=firewall-proof-allow --json
orbit firewall:remove swamp-conversion-proof --node=DEV_NODE_ID --yes --idempotency-key=firewall-proof-remove --json
orbit operation:show ALLOW_OPERATION_ID --json
orbit operation:show REMOVE_OPERATION_ID --json
```

Save both Operation IDs, deployed Orbit and orbit-swamp SHAs, the dev Node ID, step names, step output, final backend results, and the final firewall list showing the rule is absent. Expect `ufw-converge` with active and `ufw-remove` with absent. A focused fake test proves contract behavior; only this deployed round trip proves the live path.

The reference tests run the native parent, dispatch and family workflows in a disposable repository. They then resume the failed family in a new Swamp process after losing the Node reply. The fake Gateway records one Node mutation and one completion. These native tests run in CI with the same pinned Swamp release as Services.

## Kit v2 A: protected inputs and family hooks

A run declares `secrets: ['git_read' => 'project.git_read']`. Admission and Swamp carry only `{'$secret': 'project.git_read', 'id': PROJECT_ID}` at that parameter path. No credential bytes may enter Operation input, Swamp resources, logs or results. The Gateway first matches the kind, command, target and exact expected public input, then verifies each reference owner, hydrates it and validates the real command schema. Undeclared references, changed sources, foreign IDs and raw values at protected paths fail with `operation.execution_forbidden`.

Families register resolver classes using their own provider's `$this->app->tag([MySource::class], OperationSecretSource::class)`. There is no central source enum or per-family edit to the executor. `OperationSecretSource` is under `App\Domain\Operations`. Its exact methods are:

```php
name(): string
targetTypes(): array // list of Operation target types this source supports
ownerId(Operation $operation): ?int
resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
```

`ownerId` reads public ownership relations only. The generic hydrator first requires the Operation target type in `targetTypes()`, then requires a non-null owner and an equal reference ID before calling `resolve`. Map ownership through the actual resource relation; equal numeric IDs across types do not prove ownership. Return `new OperationSecretValue($value, $redactionValues)`; values stay in request memory. For script sources such as `instance.deploy_step` and `project.dev_deploy_step`, return `OperationSecretValue::script($contents, $admittedSha256)`. It refuses a digest mismatch before sending anything. Resolve the descriptor from admitted input and the stable run key, never a caller-selected script path. Git sources must preserve `GitReadEnvironment` so the existing credential transport restrictions still apply. Environment sources must include individual sensitive values in the redaction list, including when the transported value is rendered content.

A kind may declare `execution: MyFamilyExecution::class`, implementing `OperationFamilyExecution`:

```php
context(Operation $operation): array
input(Operation $operation, array $run): array
beforeRun(Operation $operation, array $run): void
received(Operation $operation, array $run, array $receipt): array
assertCompletion(Operation $operation, OperationOutcome $outcome): void
cancellationBoundary(Operation $operation): ?array
```

`context` supplies public workflow context and protected references in executor receipts. `input` returns the exact expected unhydrated command input. It may select nested admitted descriptors, array elements, immutable configuration and previously proven `state.steps` receipts. It replaces static `input_fields`/`input_values` for that kind, while the run allowlist and target Node binding remain mandatory. No raw secrets belong in either method.

`beforeRun` runs after a fresh lease/status/cancellation check and before secret hydration and the Node call. Use it for auto-deploy and smoke policy guards. `received` projects the transport-proven receipt after known-secret redaction in the same transaction that stores the keyed receipt; it runs once for a saved result. It performs DB work only. `assertCompletion` adds family requirements after the generic proof check. Neither hook executes Node work or bypasses the generic evidence contract. The existing `completion: MyCompletion::class` hook still projects final intent through `OperationCompletion::complete(Operation, OperationOutcome)`.

A run defaults to `resumable: false` and never reissues a key whose persisted started invocation has no result. The Gateway marks it started only after guards, hydration and schema checks, immediately before dispatch; a preflight refusal remains safe to retry. It returns `operation.execution_uncertain`; the family must reconcile the effect through a separately allowed read, or end with an explicit unknown outcome. Only a proven convergent command may opt in with `resumable: true` in its runs entry. A descriptor cannot enable replay when the runs entry does not. Firewall explicitly opts in.

`$run` imports the exported PHPStan `RunInput` shape from `SwampOperationExecutor`: `{worker: string, key: string, transport: string, name: string, input: array<string,mixed>}`. `context` returns `{descriptors: list<Descriptor>, result?: array<string,mixed>}`; `Descriptor` is exported by `OperationFamilyExecution`: `{key, step, transport, name, input, resumable: bool, required: bool}`. Descriptors are public, unhydrated inputs. The runtime returns this as `execution_context` in claim and heartbeat receipts. Swamp owns ordering.

`received` receives a transport-proven receipt after redaction of secret fields and values collected during this invocation’s hydration and returns public result fields; it can perform DB-only receipt projection in that transaction. `cancellationBoundary` returns null or `{key, transport, name, result}`. Only an already stored successful matching invocation permits effects after cancellation; a claimed activation flag or caller step cannot authorize that boundary. Production activation can therefore require PHP/process restarts and after steps to finish.

## Kit v2 F: named Gateway effects

`transport: gateway` uses the same `/run`, with no caller Node ID. Register `['github.recipe-pr' => RecipePullRequestCommand::class]` in `app/Domain/<Family>/operation-commands.php`. The class implements `OperationGatewayCommand::schema(): array` and `run(Operation $operation, array $input): array`. Its entry still needs a per-kind run allowlist, exact input binding, protected references and completion proof. Mark the command implementation’s input parameter `#[\SensitiveParameter]`. The schema validates hydrated input. Run fingerprints and stored invocation inputs always use the unhydrated request, including only `{$secret, id}` at protected paths. A newly minted value never changes the run identity. The command runs on the Gateway and cannot pick a Node through the primitive. GitHub writes that cannot safely repeat must set `resumable: false` and reconcile the previous keyed effect.

## Kit v2 G: nested admitted bindings

A run can declare `bind: ['port' => 'input.configuration.ports.0', 'options.path' => 'input.release.path']`. The Gateway reads only those admitted Operation input paths and combines them with fixed `input_values`. Missing paths fail closed. Executor endpoints preserve empty strings and whitespace before binding; send the exact admitted values. The submitted input must match exactly, including array order and types. These bindings also work with the family input hook; explicit bindings remain mandatory constraints on its expected result.

## Family-owned transports and worker peers

Register `['bootstrap-ssh' => BootstrapTransport::class]` in `app/Domain/<Family>/operation-transports.php`. The class implements `OperationTransport::schema(Operation $operation, array $run): array|bool` and `run(Operation $operation, array $run, array $input): array`. `$run` is the exported unhydrated `RunInput`; `$input` is hydrated in memory. Constructors and schema methods perform preparation only. The named effect runs after generic binding, ownership, schema, lease, cancellation and fresh family guards. A custom Node transport uses only `Operation.node_id`; bootstrap pin/host values must be bound to admitted input. Never accept an address, Node override, handler class, shell source or fingerprint outside that binding. Built-in agent, remote_program and gateway transports use the same interface. Registering a transport does not authorize any kind or command; its per-kind allowlist is still required.

Gateway config `operation_executor.worker_node_ids` comes from `ORBIT_OPERATION_EXECUTOR_WORKER_NODE_IDS`, a comma-separated list, empty by default. Configured Services remains included. Every caller must be an active peer with Gateway Node access. A lease stores the authenticated claiming Node ID as well as the worker string. Another peer cannot replay that claim or mutate its Operation, even with the same worker string. An expired lease may be reclaimed by a different peer. This extension applies only to executor routes and does not change Services-only dependency tokens. The orchestrator applies managed env changes and runs `php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure`; never run config:cache inside a release. This change performs no fleet rollout.

A family hook must retain all completed descriptor identities. Every successful descriptor step must match its keyed server-recorded transport result; Services cannot manufacture intermediate `state.steps` values to influence later input. Completion requirements remain mandatory even when the family projects a receipt into `state.steps` itself. The generic store preserves its invocation evidence while the family projects DB state.


`execution_context.result` is an optional public terminal-result projection derived only from Gateway state and proven receipts. The Gateway filters it through the kind's `result_fields` and redactor before returning it. Swamp can copy it into failed or cancelled completion without dispatching a command after cancellation. Families validate it in `assertCompletion`, which runs for every terminal outcome. For deploys, retain the deployment ID, actual selected release and commit needed by smoke readers.

`context` performs reads only. For one-time DB intent preparation, declare a keyed Gateway command, then derive later context from its proven receipt. When a guard lock must cover both a fresh policy check and dispatch, the family's registered `OperationTransport::run` holds the existing lock, rechecks inside it, and invokes the named transport while still holding it. `beforeRun` is a point-in-time check. The generic attempt lease serializes one Operation; it does not replace a shared Node or deployment policy lock.


A transport that wraps the native agent also implements optional `OperationTransportInput::wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed`. Delegate this method and `schema` to `OperationAgentTransport`. The generic validator then checks the native `GitReadEnvironment` and credential wire form, while dispatch still receives the original typed objects. `OperationTransport` itself is unchanged. Plain JSON transports keep JSON validation. Never return a permissive schema to work around serialization. Empty maps require an explicit PHP object from the input builder or source resolver; a PHP list remains a list and is refused where the native schema requires an object. The wire conversion must match what `run` actually sends.


Protected sources resolve once per invocation hydration. Step, completion, claim and heartbeat processing never re-resolves a source for redaction and never mints an unused token. Run redaction values exist only in request memory. Swamp receives already-redacted receipts and must reuse those safe values in later payloads; ordinary secret-pattern redaction still applies to steps and completion. Record deletion or a minting-service outage must not prevent failure completion. Transport schemas must be explicit restrictive schemas; `schema() === true` is refused. Schema and wire-conversion failures return only the fixed execution refusal, including invalid UTF-8 and type errors.


The kind loader refuses unsupported kind, run and completion-contract keys. Do not declare planned guarantees: `children` and `children_succeeded` use the B checks below. `children_settled` uses the C checks below. A declaration must fail during loading when the shared kit cannot enforce it.


An uncertain refusal leaves the started run’s result unset and never calls the family projection hook. Only a proven original receipt may fill that slot. Typed agent tokens and scalar protected values contribute redaction strings. Boolean flags, null, empty strings, empty arrays, empty plain objects and `GitReadEnvironment::none()` hydrate without inferred masks, preserving their native types. True does not add a mask for the digit `1`. This permits public Git reads, empty npmrc or rendered environment content and empty redaction lists. Command schemas still decide which type is valid. Unknown opaque objects and nonempty protected content without masks remain refused. Sources must provide individual masks for opaque structured values. Transport schemas must also be non-empty; `[]` and `{}` do not supply a validation contract.

Use `OperationKinds::supports($capability)` before registering a kind that needs an optional kit guarantee. Names are `children` (including `children_succeeded`), `children_settled`, `list_of`, `receipts` and `secret_outputs`. This slice enables `children`, `children_settled`, `list_of`, `receipts` and `compensations`. `secret_outputs` and unknown names return false. Return `operation.kind_unavailable` at admission while a required capability is absent. Do not register a weaker guarantee.

## Child Operations and durable waits

Register `children` on the parent kind and `admission` on each child kind. The parent's `execution` implements `OperationFamilyChildren::childContext(Operation): array{intent:array,children:list<ChildIntent>}`. `ChildIntent` is `{key,kind,node_id,input}`. Return the exact full intents from Gateway state, such as `state.child_intents`. Build them from admitted input or a proven named preparation command. Context only reads. Expose a later-stage key only after its prerequisite receipts are proven.

Each `children` entry is `{kind,node_from,bind?}`. `node_from` is `operation.node_id`, `input.<path>`, `state.<path>` or `intent.<path>`; it may contain one Node or a list. `bind` maps child parameters to `input.<path>` or `intent.<path>`. A missing path refuses; null and scalar types remain exact. Without bind, the stored keyed intent provides the exact input. Swamp cannot choose any of these values.

The child kind's `admission` class implements `OperationChildAdmission::admit(Operation $parent,array $intent): Operation`. Call the owning family's normal admission action. In particular, a `route.destroy` adapter calls `AdmitRouteOperationAction::destroy`; it must not replace its validation with raw `StartOperationAction`. The framework scopes a stable key around that action and stamps ancestry in the same transaction. Admission may enrich the normal child input. It cannot adopt an unrelated Operation.

Use the shared `Executor` methods:

```ts
const child = await executor.child(parent.id, worker, "prepare-destination");
const receipts = await executor.children(parent.id);
const waiting = await executor.wait(parent.id, worker, [child.child_key!]);
// Persist a family waiting resource and return. End this native workflow now.
// The ordinary runner drives registered children and later reclaims the parent. `executor.receipt(operationId,key)` reads the shared Kit I effect receipt after a lost run reply.
```

`child()` sends only `{worker,key}`. The Gateway returns HTTP 202 on first admission and 200 on replay. Optional raw POST `kind`, `node_id` and `input` fields are assertions only; conflicting values refuse. Each child has its own ordinary claim/run/step/complete contract. `Executor.claim(kind,worker,childId)` can claim an exact admitted child, but a parent should normally yield so children use the regular runner's capacity. Never sleep or hold all four workers while waiting.

`wait()` sends `{worker,keys}`. The Gateway records `{key,id,fingerprint}` tuples on the parent, releases its worker lease and keeps its target hold. It returns `status:waiting`, `worker:null`, `lease_seconds:0` and `waiting_for:[{key,id}]`. An identical lost-reply retry is read-only; a stale worker cannot release a later lease. Re-entry requires the same recorded children to be terminal. Failed children permit re-entry for a family failure branch; they never count as successful effects. Keep effect and child keys stable across workflow runs.

Child rows have Gateway-only `parent_operation_id`, `child_key`, `child_admission`, `child_fingerprint` and `run_constraints`. Family guards call `OperationChildren::requireSucceeded($parent,$key)` and inspect that exact child's result. The helper verifies the recorded kind, target, Node, enriched input and constraints. Never treat a parent run result, a client-selected child ID, or an unrelated successful Operation as proof.

Same-target children run under the original ancestor hold. Chains such as `node.add -> node.role_add -> node.converge` inherit that hold. Child completion cannot release it. Unrelated starts remain busy until its holder ends. Other targets take their own normal holds. A parent cannot complete while any admitted child is nonterminal.

Owning admission may set a server-only constraints map. Children keep every inherited constraint unchanged and may add nonconflicting ones. The built-in `time_window` is `{timezone:"Europe/Amsterdam",start:"03:00",end:"06:00"}`, start inclusive and end exclusive, with midnight crossing supported. Gateway checks run at final dispatch. Additional checks implement tagged `OperationRunConstraint::{name,assertAllowed}`. Never send constraints in Swamp input.

Use `completion_contract.children_succeeded:[kinds]` for required child proof. The Gateway rejects missing keyed children and non-successful children. It enforces this alongside a named step proof when both are declared. `OperationKinds::supports("children")` is true only with this enforcement. `children_settled`, list results, secret outputs and protected relay are separate capabilities; do not declare a guarantee until the kind loader supports it.

## Retained Gateway callers

PHP callers start an Operation through the owning family's admission action. Return it when the caller can follow HTTP 202. A retained synchronous caller may use `App\Domain\Operations\AwaitOperation::execute($operation, $timeoutSeconds = 30)` after the admission transaction commits. It only refreshes that exact Operation, sleeping between reads, with a bound of 1–120 seconds. It never runs the family executor, restarts an effect or requests cancellation.

The returned Operation may be succeeded, failed or cancelled. Check its status and result before dependent cleanup. `operation.await_timeout` contains only the Operation ID; it means the caller's wait ended while Swamp work continues. Do not treat it as a successful teardown or cancel the Operation as a side effect. Never await inside the transaction that admits or holds the Operation. `AwaitOperation` throws a `LogicException` before reading or sleeping when any DB transaction is open.

## Bootstrap receipt connection authentication

A receipt transport receives unhydrated input and must not call general `hydrate()`. Payload sources and original redaction masks are never reconstructed during recovery. Managed transports keep their normal connection authentication.

Before managed login exists, a bootstrap transport may call `OperationSecrets::connection($operation, $run, 'credential_parameter')`. Its source is already declared for that parameter in the kind's A `secrets` map and is tagged through the existing source registry. It also implements `OperationConnectionSecretSource`:

```php
public function resolveConnection(Operation $operation, int $id): OperationSecretValue;
```

This method only reads an existing connection credential. It must not mint, rotate, create or project state. The helper reuses exact run authorization and A target-type/owner checks; an ordinary payload source cannot use it. Use the returned in-memory value only to authenticate the pinned host/fingerprint from admitted intent. Do not put it in the command payload, original receipt masks, result, Operation state or logs. The receipt still requires original sanitized target proof. This is an authentication hook, not permission to rerun an effect or resolve other references.


## Cancellation and waiting-parent assistance

Kinds that need cleanup after cancellation declare both `compensations: [<child kinds>]` and `cancellation_boundary: <child key>`. Gate them with `OperationKinds::supports("compensations")`. Every compensation kind must also be a declared child kind. After cancellation, the parent runs no further effect of its own. Only a compensation child may be admitted or run, and only after the boundary is a Gateway-stamped successful child of that exact parent. Several compensation kinds are allowed. Cancellation stamps every nonterminal descendant in the same transaction. Queued children that have not started settle immediately. Running children use their own cancellation flag and proven boundary, so an intermediate transfer may restore even when its grandparent initiated the cancel. New compensation children can run their cleanup plan. All input, target, hold, completion and receipt checks still apply.

Only `state.child_intents`, `state.integration_intent`, `state.route_plan` and `state.role_intent`, including nested fields, are allowed as `node_from` state sources. Other prefixes refuse at load. Use admitted `input`, `intent` or `operation.node_id` for other plans. Never derive Node authority from a posted step or a target command result.

Readiness checks return false for missing or mismatched waiting children. Claims flag that parent in `state.executor_assistance` and continue to other candidates. A queued child whose kind is unavailable in the Gateway Swamp registry is also flagged. This does not prove whether a deployed Swamp checkout contains a workflow; keep family registrations deployed with their Gateway kinds. Discovery does not claim work. The typed `Executor.claim` helper returns no work for `operation.child_unproven`, `operation.executor_invalid` or `operation.kind_unavailable`, so a placed family invocation can end without stopping other dispatches. Authentication and unrelated transport errors still fail the tick.

`GET /operation-executor/claimable` is Services-only. It shares the exact candidate selector with claim, including verified terminal children for waiting parents. Discovery is read-only and never writes assistance flags. A parent re-entry copies the verified children's status, error code and redacted result beside the original key, ID and fingerprint. Same-target siblings and their parent serialize effects using the ancestor target hold's attempt lease. Custom run constraints execute once at final dispatch; their parameters must be validated by their owning admission adapter.

Custom target receipt normalizers must retain internal `effect_nonce`, `effect_created_at` and `sanitized` evidence. The public GET receipt shape and `op-ID.key` remain unchanged. Dispatch uses `AgentEffectReceipts::input` or `EffectReceiptMetadata::fromRun`; never reconstruct identity from a numeric Operation ID or hydrate payloads during receipt reads. An infrastructure refusal, identity conflict or unproven effect-complete response leaves the keyed result unset for later recovery.

## Placement rollout

Deploy the Gateway claimable-read endpoint before this dispatcher. Services discovery performs one GET and no claim or other mutation. The endpoint owns the same eligibility predicate as claim, including expired leases and ready waiting parents. Counts are advisory; another worker may claim first, so a null claim is normal. Each dispatched family invocation claims at most one Operation of its kind. The next scheduled discovery drains further work. Keep all lease-bound writes inside that invocation; splitting claim and execution into separate placed steps could select different Nodes. The Node-bound lease must never be relaxed to compensate for placement.

Persist the dispatch's claimed Operation ID before the first effect. A retry of that dispatch must read its assignment before any new claim. If completion committed but its response was lost, confirm the original terminal result through the normal read API and finish without claiming new work. If the original lease is still live, renew only that Operation. If it expired or belongs to another Node, fail that attempt; normal claimable discovery may reclaim it in a fresh dispatch. Never let an old retry select the next queued Operation.


## Cancellation recovery and deleted Nodes

Show and list expose `assistance: null|{error_code}` for flagged waiting parents. They never expose the internal state or arbitrary detail. Explicit cancel can end a flagged parent when no stamped nonterminal child can still be proven; a proven running child retains the hold. Swamp sees the cascaded child's own `cancel_requested` flag, and a refused effect returns the usual `operation.cancelled` result so it can finish its step and terminal cancellation. A child's proven activation boundary still lets that child finish mandatory follow-up work. Cancellation is never turned into an endless ancestor-fence exception.

Admission snapshots each original Node ID and target independently of the live Node foreign key. A deleted Node does not erase successful child proof, terminal context, completion replies or cached effect receipts. Those reads do not hydrate secrets. A new effect on a deleted target returns `operation.target_missing` before hydration or dispatch. Nulling the foreign key while the original admitted Node still exists refuses proof. Custom family context must retain completed descriptors from admitted data; it must not require the removed live Node for terminal reads.

A run result with `not_started: true` is a proven pre-effect refusal from the compiled command contract. The Gateway leaves `started:false` and `result:null`; the same key can retry without enabling replay of an uncertain effect. After any prior uncertainty, a later pre-start refusal cannot reset the original intent. Preserve the existing effect metadata forwarding helpers and internal receipt identity fields. Do not interpret every target lock or transport exception as proof that nothing ran.

## Kit C: bounded results and completion variants

Gate registrations that require rich lists with `OperationKinds::supports("list_of")`. The Gateway validates declarations while loading every family. A result mismatch returns `operation.result_invalid` before storing a partial keyed receipt or projecting DB state. Swamp forwards safe Gateway receipts; it never coerces list item types or rebuilds them from a map.

A scalar list declaration is `{name, type: "list", items: "string"|"integer"|"boolean", max: N}`. Values require actual strings, integers or booleans; numeric strings are not integers. Lists use JSON arrays; empty objects and objects with numeric keys are refused. Order stays unchanged. Every explicit bound is from 1 through 4096.

An object list is `{name, list_of: {field: rule}, max?: N}`. The default outer bound is 4096. Every entry requires exactly the declared fields; unknown and missing fields refuse. Rules are `string`, `int`, `bool` or `enum(applied,unchanged,skipped)`, optionally ending in `|null`. A field may instead declare one bounded scalar list using the scalar-list shape above. Further nested objects and containers are refused. Keyed maps are never lists.

For example:

```php
'result_fields' => [
    'backend', 'error_code',
    ['name' => 'process_ids', 'type' => 'list', 'items' => 'integer', 'max' => 4096],
    ['name' => 'retained_on_nodes', 'max' => 128, 'list_of' => [
        'node_id' => 'int', 'node' => 'string',
        'steps' => ['type' => 'list', 'items' => 'string', 'max' => 32],
    ]],
    ['name' => 'child_phases', 'list_of' => ['stage' => 'string', 'key' => 'string']],
],
```

All values still pass through the existing redactor and original hydration masks. Only `child_phases[].key` and `skipped[].key` have an audited public child-identity meaning; other secret-named fields remain masked. Text redaction and known-secret masks also apply to those public identifiers. A list that no longer matches its declared types after redaction is refused, never stored with unredacted values.

Keep the root `completion` as the DB projection class. To select a proof per admitted manager or variant, declare:

```php
'completion_contract' => ['completion' => [
    'by' => 'configuration.manager',
    'map' => [
        'apt' => ['step' => 'tool-verified', 'transport' => 'agent',
            'name' => 'tool.apt-inventory', 'result' => []],
    ],
]],
```

`by` is a path relative to immutable Operation input, without an `input.` prefix. The Gateway validates every mapped proof at load. Missing or unlisted runtime variants refuse with `operation.completion_unproven`. Swamp cannot choose the variant in the run or completion body. The selected command still needs its normal allowlist and a proven successful step.

Gate `children_settled` with its matching capability. Its completion contract names declared child kinds that must settle. On success, all required prepared children must have been admitted. Every admitted child must be terminal before the parent ends. Every failed or cancelled child of this parent must appear once in `result.skipped` with `{child_id, reason}`. Declare that list with `list_of`; `reason` must be nonempty and at most 500 bytes. If declared, `key` and `kind` must match the stamped child. Foreign, duplicated, successful, running or unacknowledged failures refuse. On failed or cancelled completion the Gateway derives skipped from its stamped children; send an empty or omitted list. Only successful completion requires an exact echo of failed child IDs and nonempty reasons. The Gateway stamps any declared key and kind from the child row, ignoring caller metadata.

Optional stages should use a mandatory final Gateway proof and family `assertCompletion` that calls `OperationChildren::requireSucceeded` for the exact stored plan keys. Do not list every optional kind as an unconditional `children_succeeded` requirement.

Result list strings are limited to 1024 bytes and the full encoded result to 256 KiB. Declarations are limited to 65,536 total list items after expanding nested bounds. A children_settled kind must declare skipped with child_id:int, reason:string and optional key:string/kind:string; its max must cover its child kinds. Child admission enforces the count and byte budget before effects, preserving room for derived failure receipts. Executor routes preserve empty strings and exact bytes by bypassing TrimStrings and ConvertEmptyStringsToNull.
