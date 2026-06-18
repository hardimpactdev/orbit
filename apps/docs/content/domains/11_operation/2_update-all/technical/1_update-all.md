# Technical Contract: `orbit update:all`

[Back to public `update:all` documentation.](../update-all.md)

**Owner:** `operation`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin authority
  (`*` on the active gateway node).
- The gateway can reach each selected non-local installation through its node execution path.
- The gateway can write operation rows, event journal rows, immutable update
  plans, and expiring update leases.

## Signature

```bash
orbit update:all [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer.
2. Call the gateway to authorize gateway-admin authority and resolve selected
   non-local managed Orbit installations from active gateway node configuration.
3. Submit a start request to the gateway. The gateway persists an operation row
   and an immutable update plan keyed by `operation_run_id`, then launches a
   one-shot runner from the target `orbit-gateway` image.
4. Follow the operation event stream, reconnecting with `Last-Event-ID` when the
   gateway service is replaced.
5. After the gateway phase succeeds, update the caller-local CLI as a fan-out
   target alongside the remote workload nodes. The gateway is the version
   ceiling, so the local CLI is never updated ahead of the gateway; if the
   gateway phase fails, the local update does not run.

The command has no required fields and does not prompt. Renderer-specific
execution details live in the renderer contracts.

## Behavior Contract

### Version check and fleet version probe

- `update:all` runs a `Checking for updates` step first, resolving the latest
  available release version from the configured release source.
- It then runs a `Checking fleet versions` step that probes each selected
  installation's current orbit version (read-only `orbit --version` over
  `RemoteShell`) and counts how many are behind the latest release.
- When every node is already on the latest release (0 outdated), the command
  short-circuits: the gateway/workload/verification phases are skipped entirely
  and the operation terminates with `Skipped: <version> is already installed on
  all nodes`.
- A node already on the latest release is skipped (it renders
  `Skipped: already up to date`) and runs no download. Only outdated nodes run
  the update script.
- Each updated node advances through per-node sub-stages: `Downloading <v>` →
  `Replacing cli binary` → `Running doctor`. The gateway node additionally runs
  `Updating gateway app` after download and before `Replacing cli binary`. Each
  updated node emits these as ordered journal sub-steps so the renderer can show
  the active sub-stage in the node row.
- Each updated node runs a post-update `orbit doctor` verify (`Running doctor`
  sub-stage); the issue count is surfaced in the node result and is non-fatal.
- The gateway is the fleet version ceiling: it updates first, before any
  workload node is updated, so no node is ever taken past the gateway's version.

### Fleet Selection Rules

- Include the caller's local Orbit installation.
- Include active non-local managed Orbit installations from gateway node
  configuration when the gateway has both an Orbit installation path and enough
  `RemoteShell` transport metadata to reach the node.
- **Exclude operator identities, regardless of caller.** Operator
  workstations update locally through
  [`orbit update`](../../1_update/update.md) on each workstation.
- Exclude inactive, removed, unknown, or caller-local node records from the
  gateway-selected installation list. The local installation is updated once
  through the local target.
- Apply gateway-owned authorization before updating any installation.

The expected target shape per calling context:

| Calling context | Local target | Gateway target | App-role targets | Other client targets |
| --- | --- | --- | --- | --- |
| Non-gateway caller with gateway-admin authority | The caller-local installation, updated as a fan-out target after the gateway phase. | Yes, when the gateway is an active node distinct from the caller. Updated first, before local and app-role targets. | Yes, every active node selected by the rules above. Updated in parallel with the local target after the gateway phase. | Never. |
| Gateway caller | The gateway installation (via the local target). Updated as the gateway phase; the local target concept does not apply separately. | N/A — the gateway is the local target. | Yes, every active node selected by the rules above. | Never. |

### Durable Operation Rules

- The gateway start request creates an `operation_runs` row whose id is the
  `operation_run_id` for the whole update.
- The gateway persists an ordered operation event journal before side effects
  begin. Event payloads are redacted through the operation result boundary and
  must never contain secrets, raw command output containing secrets, private
  keys, release credentials, or operation tokens.
- The gateway persists an immutable update plan keyed by `operation_run_id`.
  The plan includes target version, gateway image registry/tag/digest,
  manifest source, manifest version, manifest snapshot, CLI artifact URLs and
  hashes, and required role image references. The runner must read this plan
  and must not fetch a fresh manifest during the run.
- The gateway launches the one-shot runner from the target
  `orbit-gateway` image with the gateway config root and Docker socket mounted.
  The runner survives replacement of the long-running `orbit-gateway` service
  and owns the rest of the fleet update.
- Followers read events through the gateway SSE API. A follower may replay from
  the beginning or continue from `Last-Event-ID`. Duplicate events after
  reconnect must not be rendered twice.

### Lease Rules

- Every update entry point that mutates fleet state must acquire an expiring
  update lease before side effects.
- The runner holds the `fleet:update-all` lease for the entire run: gateway
  replacement, scheduler update, workload node updates, and final verification.
- Gateway and scheduler leases are scoped to the gateway phase.
- Node leases are scoped per workload node fan-out task.
- Lease acquisition must map active-lease conflicts, including
  unique-constraint races, to a typed update lease conflict. Expired leases may
  be taken over and must not leave a node permanently blocked.
- A workload node lease conflict stops workload fan-out before the conflicting
  node mutation and records terminal durable error data with code
  `update.node_locked`, the locked resource, the conflicting operation id, and
  the lease expiry time.

### Per-Installation Update Rules

- The gateway updates first as the version ceiling. The gateway phase runs
  before the local caller update or any workload fan-out. If the gateway phase
  fails, the local update does not run.
- The caller-local installation is updated as a fan-out target after the gateway
  phase succeeds, in parallel with the remote workload nodes. Production installs
  update the native CLI binary artifact; source-dev Docker/Incus topology nodes
  keep `/usr/local/bin/orbit` pointed at `<source>/apps/cli/orbit`. A local
  update failure after the fleet has been updated is a partial failure: the
  fleet is updated, the operator-local install failed, and the operator
  re-runs `orbit update` to recover.
- Gateway replacement uses the digest-pinned `orbit-gateway` image from the
  immutable update plan. No-source production gateway hosts acquire the image by
  loading `ORBIT_GATEWAY_IMAGE_ARCHIVE` or pulling the digest-pinned
  `ORBIT_GATEWAY_IMAGE` before deploy commands that use `--pull never`.
- The scheduler update is stop-first: scale `orbit-scheduler` to zero, run
  migrations through the target gateway image, verify `orbit-gateway`, then
  start `orbit-scheduler` on the matching image.
- If migrations or gateway health fail, restore `orbit-scheduler` to one
  replica on the previous known-good image when possible. If scheduler recovery
  also fails, emit an explicit terminal failure event and name the recovery
  command the operator should run.
- After the gateway phase succeeds, selected remote app/workload-role
  installations and the caller-local installation are updated in parallel, up to
  four targets at a time. Production artifact targets run the binary-update path.
  Source-dev targets keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`.
- Each updated installation emits per-node sub-stages through the operation
  journal: `Downloading <v>` → `Replacing cli binary` → `Running doctor` → `Done`
  for local/workload nodes; `Downloading <v> assets` → `Updating gateway app` →
  `Replacing cli binary` → `Running doctor` → `Done` for the gateway node.
- Each updated installation runs `orbit doctor` in verify mode as the final
  per-node sub-stage (`Running doctor`). This is verification only; a non-zero
  issue count is surfaced per node but does not by itself fail the node's update.
- Production workload updates install the binary into the node user's Orbit
  install root. When the host launcher parent directory is not writable, the
  remote update may use non-interactive `sudo -n` only to relink the system
  launcher to that user-owned binary; it must fail rather than prompt.
- Workload fan-out uses the same persisted manifest snapshot as the gateway
  update for CLI artifacts and required role image metadata.
- Remote update execution is gateway-owned node execution through `RemoteShell`.
  Clients do not SSH directly to the gateway, nodes, or other operator
  workstations as part of the command contract. The gateway does not SSH to
  operator workstations as part of the command contract.
- Continue updating remaining installations after a target fails.
- If any workload target result is failed, the workload phase fails with
  `workload_update_failed` and preserves the selected target results before
  final verification starts.
- Preserve every target result for the selected output renderer in selected
  target order, regardless of the order in which parallel workers finish.

### Final Verification Rules

- The runner emits terminal success only after verifying gateway health,
  scheduler health, selected workload CLI execution, and required role image
  availability.
- SQLite gateway event streaming uses WAL journal mode and a busy timeout so
  the runner can write operation events while the gateway API reads and streams
  them.

### Partial Failure Rules

- If every selected installation updates successfully, report a full fleet
  success. If one or more installations fail after side effects begin, report
  both successful and failed target results.
- When the gateway installation update fails, do not start the local or
  app-role fan-out.
- When the caller-local installation update fails after the gateway phase
  succeeded, report a partial failure: the fleet was updated but the
  operator-local install was not. The operator re-runs `orbit update` to
  recover.
- When a node with an app role fails, do not hide successful app-role updates
  and do not cancel unrelated in-flight app-role updates.

### Scope Boundaries

`update:all` must not:
- Create or remove node records.
- Mint WireGuard identities or node access grants.
- Deploy apps or run app deployment pipelines.
- Repair node, app, workspace, process, proxy route, schedule, tool, or
  firewall drift.
- Treat a successful update as doctor convergence.

## Renderer Contracts

- [Human renderer](6.1_update-all_output-render_human.md)
- [JSON renderer](6.2_update-all_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Local update failed (partial) | The caller's local CLI update fails after the fleet has already been updated by the gateway phase. | Partial failure — the fleet is updated; the operator re-runs `orbit update` to recover the local install. |
| Immutable plan missing | The gateway cannot persist or load the immutable update plan for `operation_run_id`. | Failure before side effects |
| Update lease conflict | Another active update lease owns the same fleet, gateway, scheduler, or node resource. | Failure before conflicting side effects |
| Gateway update failed | The gateway service update, migration, or health verification fails. | Terminal operation failure; local and app-role targets are not started |
| Scheduler recovery failed | The scheduler could not be restored after failed migrations or gateway health. | Terminal operation failure with explicit recovery metadata |
| App-role update failed | One or more selected app-role installations fail to update. | Failure with partial target results |
| Final verification failed | Gateway, scheduler, CLI, or required image verification fails after updates. | Terminal operation failure with partial target results |

The shared [Exit Status](../../../README.md#exit-status) policy applies. Partial
fleet failures are Orbit-handled command failures.

## Doctor Relationship

- `update:all` changes Orbit installations.
- It does not verify state-family drift or runtime readiness.
- Run `doctor --family=<family>` after updates to verify convergence for a specific family.
- A remote update failure may leave a node on a different Orbit version.
- Such a version mismatch creates node-family drift.
- Node doctor owns any later reachability or readiness diagnosis once its contract is converted in this repo.

## Activity Logging

The gateway records fleet update activity at the chokepoint; the CLI does not
emit activity entries. The `update:all` start API route records the attempt
entry through gateway activity middleware, and the durable update runner
records one outcome entry when the operation reaches a terminal state.
Activity logging is best-effort and must not change the documented command
result or the operation status.

The runner outcome entry uses these fields:

| Field | Value |
| --- | --- |
| Type | `update:all` |
| Effect | `write` |
| Subject | The `operation_run_id` for the durable fleet update operation. |
| Properties | `scope=fleet`, `operation_run_id`, `status` (`completed` or `failed`), `target_version`, gateway image digest, manifest version/source, and `failed_step` for local, gateway, scheduler, remote, or verification failures. Per-target results and summary counts live in the durable operation record, not the activity entry. No process output, SSH output, environment values, private keys, operation tokens, or secrets. |
| Description | derived |

## Test Mapping

Primary existing test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/UpdateAllCommandTest.php` | CLI fleet update contract: local update preflight, durable operation start, event-stream following and reconnects, terminal operation errors, and JSON/human rendering. |
| `apps/gateway/tests/Feature/Http/Api/UpdateAllStartControllerTest.php` | Gateway start API contract: authorization, durable operation creation, and attempt activity logging via route middleware. |
| `apps/gateway/tests/Feature/Http/Api/UpdateAllControllerTest.php` | Gateway operation read/event API contract for durable fleet updates. |
| `apps/gateway/tests/Unit/Http/Gateway/UpdateAllGatewayStreamClientTest.php` | Gateway event-stream client behavior, including reconnect handling. |
| `apps/gateway/tests/Feature/Services/Operations/UpdateRunnerActivityTest.php` | Durable runner outcome activity entries for completed and failed fleet updates, including best-effort logging-failure handling. |
| `apps/e2e/tests/Feature/Commands/UpdateAllDurableOperationTest.php` | Integrated durable fleet update from an operator through gateway event replay. |
