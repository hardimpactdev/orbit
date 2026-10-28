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

All requests are JSON POSTs under `/api/v1/operation-executor`. The caller must be the active Services peer configured by `dependency_detection.services_node_id`, and must have Gateway access. The existing dependency-token setting is reused; this conversion creates no new credential or access grant. MCP tools are generated from the same API and enforce the same identity.

| Suffix | Body | Receipt |
| --- | --- | --- |
| `claim` | `kind`, `worker` | One Operation with its input, Node ID, steps, step results, worker and 120-second lease; null if no work is available. |
| `{operation}/heartbeat` | `worker` | Current Operation; renews its lease. |
| `{operation}/step` | `worker`, `key`, `name`, `status`; optional `output`, `result`, `error_code` | Appends output to the named step and records its status under the existing Operation sequence. Status is running, succeeded or failed. Failed requires an error_code matching `^[a-z0-9_.-]+$`. |
| `{operation}/run` | `worker`, `key`, `transport`, `name`, `input` | Only the kind’s declared, redacted scalar result fields from the named agent command or RemoteProgram. Transport is agent or remote_program. The existing named command or program deadline and Gateway request deadline apply. |
| `{operation}/complete` | `worker`, `status`; optional `result`, `error_code` | Terminal Operation and projected family intent. Status is succeeded, failed or cancelled. Failed requires an error_code matching `^[a-z0-9_.-]+$`. |

Use a stable workflow-run identity as worker. The runner appends the kind, so each claim can be replayed independently. Heartbeat every 30 seconds while a family runs. A new worker can reclaim an expired Operation. A stale worker receives `operation.lease_lost` and stops. PHP jobs and the PHP recovery sweep leave Swamp kinds alone.

Step event keys identify exact payloads. A replay appends no duplicate output. Run keys bind the named command, target and input before execution; a different payload returns `operation.idempotency_conflict`. A successful result is reused. If the process dies before saving the result, the family must repeat a convergent command or reconcile the original effect. The Gateway serializes invocations on an Operation with its existing attempt lease. It does not promise exactly-once execution of an arbitrary remote program. Output keeps the existing 8 KiB tail.

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
