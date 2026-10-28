# Convert an Orbit operation family

Swamp executes operations. Orbit validates requests and stores intent, Operations and receipts. Reads stay in Orbit.

1. Read the family's existing tests. Preserve input validation, ownership, errors and result shapes.
2. Add each write kind to its Orbit family registration with `executor: swamp`. Keep the normal CLI and MCP command. Use `StartsOperation` and the existing Operation idempotency boundary. Return 202 after recording intent and target. Never dispatch a PHP executor for the kind.
3. Keep one directory per family under `extensions/behaviors/operations/<family>/`. Put its behavior in `mod.ts`, its typed contract beside it, and its focused tests in that directory. Give its workflow a unique `workflows/workflow-operation-<family>.yaml` file. The shared runner lives in `extensions/behaviors/operation-runner/`. Its operation-dispatch workflow expands scalar Operation IDs after claims are available; families never edit this helper.
4. Register the kind and family workflow in the family's own registration file. Do not edit another family's files.
5. Use the Services-only Gateway primitives at `/api/v1/operation-executor`. Reuse the existing RemoteProgram or agent command; never send script source or create another job store.
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
- `result_fields` is a closed list of scalar leaf paths, using dots for nested fields. Include `error_code` for command refusals and `operation.cancelled`. The Gateway drops every undeclared field and object, and applies its existing secret redactor to retained values, step output and error codes before persistence or return. Never allow a container, raw stdout, environment or credential field. Tokens must never reach Operation state, steps, results or responses. Test secrets in run results, step output and completion payloads.

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

All requests are JSON POSTs under `/api/v1/operation-executor`. The caller must be the active Services peer configured by `dependency_detection.services_node_id` or an explicitly configured `operation_executor.worker_node_ids` peer, with Gateway access. The authenticated claiming Node and worker string own the lease. This does not change dependency-token authorization or grant new access. MCP tools are generated from the same API and enforce the same identity.

| Suffix | Body | Receipt |
| --- | --- | --- |
| `claim` | `kind`, `worker` | One Operation with its input, Node ID, steps, step results, worker and 120-second lease; null if no work is available. |
| `{operation}/heartbeat` | `worker` | Current Operation; renews its lease. |
| `{operation}/step` | `worker`, `key`, `name`, `status`; optional `output`, `result`, `error_code` | Appends output to the named step and records its status under the existing Operation sequence. Status is running, succeeded or failed. Failed requires an error_code matching `^[a-z0-9_.-]+$`. |
| `{operation}/run` | `worker`, `key`, `transport`, `name`, `input` | Only the kind’s declared, redacted scalar result fields from the named agent command or RemoteProgram. Use a registered transport: built-ins are agent, remote_program and gateway. The stored Operation supplies the Node; a Gateway command may have a null Node. Named-command and request deadlines apply. |
| `{operation}/complete` | `worker`, `status`; optional `result`, `error_code` | Terminal Operation and projected family intent. Status is succeeded, failed or cancelled. Failed requires an error_code matching `^[a-z0-9_.-]+$`. |

Use a stable workflow-run identity as worker. The runner appends the kind, so each claim can be replayed independently. Heartbeat every 30 seconds while a family runs. A new worker can reclaim an expired Operation. A stale worker receives `operation.lease_lost` and stops. PHP jobs and the PHP recovery sweep leave Swamp kinds alone.

Step event keys identify exact payloads. A replay appends no duplicate output. Run keys bind the named command, target and input before execution; a different payload returns `operation.idempotency_conflict`. A successful result is reused. If the process dies before saving the result, the default is an uncertain refusal with no saved result or projection. Only an explicitly resumable convergent command may repeat; other effects require a proven original receipt. The Gateway serializes invocations on an Operation with its existing attempt lease. It does not promise exactly-once execution of an arbitrary remote program. Output keeps the existing 8 KiB tail.

Persist completion intent in Swamp data before posting complete. A lost completion reply replays the same receipt; it must not start another Node command. Terminal Operations reject a different completion. The Gateway rechecks status, lease and cancellation immediately before a new Node command. It records a keyed `operation.cancelled` refusal when cancellation wins. Finish that step as failed and complete the Operation as cancelled; never retry the refused effect. Cancellation before a Node effect returns cancelled. Once an effect has a proven result, finish recording that result.

## Swamp files

Copy `extensions/behaviors/operations/firewall/` to the new family directory. Change `registration.json`, the type name, input contract, steps and named commands. The generic runner scans these registration files in sorted order and rejects duplicate kinds. Each entry supplies `kinds` and the name of its family workflow. Add one uniquely named `workflows/workflow-operation-<family>.yaml`; the pinned Swamp release discovers workflow YAML files at that level. Keep all family code and tests in the family directory. Do not edit the generic runner to add a family.

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


The kind loader refuses unsupported kind, run and completion-contract keys. Do not declare planned guarantees: `children`, `children_succeeded` and `children_settled` remain invalid until kit B implements their checks. A declaration must fail during loading when the shared kit cannot enforce it.


An uncertain refusal leaves the started run’s result unset and never calls the family projection hook. Only a proven original receipt may fill that slot. Typed agent tokens and scalar protected values contribute redaction strings; a non-null protected value without any redaction strings is refused. Sources must provide individual masks for opaque structured values. Use null where the schema permits an absent protected value; public empty maps belong in admitted public input. Transport schemas must also be non-empty; `[]` and `{}` do not supply a validation contract.

Use `OperationKinds::supports($capability)` before registering a kind that needs an optional kit guarantee. Names are `children` (including `children_succeeded`), `children_settled`, `list_of` and `receipts`; all return false in this slice, as do unknown names. Return `operation.kind_unavailable` at admission while a required capability is absent. Do not register a weaker guarantee.
