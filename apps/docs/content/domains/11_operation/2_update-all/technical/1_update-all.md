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
orbit update:all [--json|--stream-json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | When `--stream-json` is present. | `false`. | Selects the JSON renderer and non-interactive input mode. |
| `stream-json` | `--stream-json` | Optional. | When `--json` is present. | `false`. | Selects the stream JSON renderer and non-interactive input mode. Mutually exclusive with `--json`. |

`--json` and `--stream-json` together fail with a `validation_failed` error
envelope (`meta.fields = ["json", "stream-json"]`,
`meta.reason = "conflicting_options"`) before the gateway is contacted.

`--stream-json` follows the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Input Resolution

1. Select the output renderer.
2. Call the gateway to authorize gateway-admin authority and resolve selected
   non-local managed Orbit installations from active gateway node configuration.
3. Submit a start request to the gateway. The gateway persists an operation row,
   returns the durable event stream URL promptly, and launches a one-shot runner.
   When the request omits an inline manifest, the gateway defers release-manifest
   resolution to the runner's `Checking for updates` step so the CLI can keep
   visible progress while the latest version is resolved.
4. Follow the operation event stream, reconnecting with `Last-Event-ID` when the
   gateway service is replaced.
5. After the gateway phase succeeds, update the caller-local CLI as a fan-out
   target alongside the remote workload nodes. The gateway is the version
   ceiling, so the local CLI is never updated ahead of the gateway; if the
   gateway phase fails, the local update does not run.

The command has no required fields and does not prompt. Renderer-specific
execution details live in the renderer contracts.

## Behavior Contract

### Update plan persistence

- The immutable update plan must exist before any update side effect begins
  (gateway replacement, workload fan-out, or final verification).
- When the start request includes an inline manifest, the gateway persists the
  plan during the start request and returns it in the 202 envelope.
- When the start request omits an inline manifest, the gateway returns the
  event stream URL promptly and the runner resolves the latest release manifest
  during the `Checking for updates` step, then persists the immutable plan before
  `Checking fleet versions` or any later phase starts.
- Release manifests may come from the public `github-release` source or a
  topology-reachable `topology-candidate` source. The manifest source is part of
  the immutable plan and terminal operation result so release-candidate
  acceptance can use a non-GitHub artifact source before promotion.
- For release-candidate rehearsal, the gateway may select a release manifest URL
  from a stable artifact channel such as
  `channels/live-test/orbit-release-manifest.json`. The channel object is only a
  manifest discovery location; the runner snapshots the resolved manifest
  content into the immutable plan before any side effects begin.
- The start POST response is sent before the durable update runner is launched.
  This lets the CLI connect to the operation event stream before any runner-side
  gateway restart can interrupt the start response. Runner launch failures after
  the response are recorded as operation journal errors.
- After the plan is persisted, the runner must read only that immutable plan for
  the remainder of the run. It must not fetch or substitute a fresh manifest
  during gateway, workload, or verification phases.
- `manifest:update` stores the custom gateway manifest URL consumed by deferred
  `update:all` runs. `manifest:remove` clears that override so deferred runs
  return to the configured default release manifest URL.

### Version check and fleet version probe

- `update:all` runs a `Checking for updates` step first, resolving the latest
  available release version from the configured release source.
- It then runs a `Checking fleet versions` step that reads gateway-owned
  installed-artifact DTOs from the node records and counts how many desired
  artifacts differ from the immutable plan. Workload nodes compare the tracked
  installed CLI version, platform, and SHA-256 hash with the desired CLI
  artifact. The gateway node compares the tracked installed gateway image digest
  with the desired digest-pinned gateway image. Missing installed-artifact state
  counts as outdated so a node is updated once and verified state is recorded.
- When every selected node is already on the desired artifact set (0 outdated), the command
  short-circuits: the gateway/workload/verification phases are skipped entirely
  and the operation terminates with `Skipped: <version> is already installed on
  all nodes`.
- A node already on the desired CLI artifact is skipped (it renders
  `Skipped: already up to date`) and runs no download. Only nodes with missing
  or different installed CLI identity run the update script.
- The all-current short-circuit and per-node already-current skip apply to both
  finalized `github-release` and `topology-candidate` manifests. A
  `topology-candidate` manifest with the same semantic version still updates
  when its desired CLI hash or gateway image digest differs from tracked
  installed state.
- Each updated node advances through per-node sub-stages: `Downloading <v>` →
  `Replacing cli binary` → `Running doctor`. The gateway node additionally runs
  `Updating gateway app` after download and before `Replacing cli binary`. Each
  updated node emits these as ordered journal sub-steps so the renderer can show
  the active sub-stage in the node row.
- Each updated node runs a post-update `orbit doctor` verify (`Running doctor`
  sub-stage); the issue count is surfaced in the node result and is non-fatal.
  If that advisory doctor check cannot return a parseable count, the node
  remains completed with `doctor_issues: null`; the release gate is still the
  separate fleet doctor that runs after updates.
- The gateway is the fleet version ceiling: it updates first, before any
  workload node is updated, so no node is ever taken past the gateway's version.

## Fleet Selection Rules

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

## Durable Operation Rules

- The gateway start request creates an `operation_runs` row whose id is the
  `operation_run_id` for the whole update.
- The gateway persists an ordered operation event journal before side effects
  begin. Event payloads are redacted through the operation result boundary and
  must never contain secrets, raw command output containing secrets, private
  keys, release credentials, or operation tokens.
- The immutable update plan is keyed by `operation_run_id` and includes target
  version, gateway image registry/tag/digest, manifest source, manifest version,
  manifest snapshot, CLI artifact URLs and hashes, and required role image
  references. See [Update plan persistence](#update-plan-persistence) for when
  the gateway versus the runner persists it.
- Node records carry gateway-owned installed-artifact DTOs. `installed_cli`
  records the last verified CLI version, platform, SHA-256 hash, manifest
  source, candidate build id when present, original artifact URL, installed
  binary path, operation id, and install/verify timestamps. `installed_gateway_image`
  records the last verified gateway image version, canonical image reference,
  digest, manifest source, candidate build id when present, operation id, and
  install/verify timestamps. These DTOs are updated only after the replacement
  path has succeeded.
- The gateway launches the one-shot runner from the configured bootstrap
  `orbit-gateway` image when the plan is deferred. If no explicit bootstrap
  image is configured, it uses the currently running digest-pinned
  `orbit_orbit-gateway` service image. When the plan is already known, the
  runner launches from the target digest. In all cases, the gateway config root
  and Docker socket are mounted.
  The runner survives replacement of the long-running `orbit-gateway` service
  and owns the rest of the fleet update.
- Followers read events through the gateway SSE API. A follower may replay from
  the beginning or continue from `Last-Event-ID`. Duplicate events after
  reconnect must not be rendered twice.

## Lease Rules

- Every update entry point that mutates fleet state must acquire an expiring
  update lease before side effects.
- After the read-only check steps settle and update work is required, the runner
  holds the `fleet:update-all` lease across gateway replacement, scheduler
  update, workload node updates, and final verification.
- Gateway and scheduler leases are scoped to the gateway phase.
- Node leases are scoped per workload node fan-out task.
- Lease acquisition must map active-lease conflicts, including
  unique-constraint races, to a typed update lease conflict. Expired leases may
  be taken over and must not leave a node permanently blocked.
- A workload node lease conflict stops workload fan-out before the conflicting
  node mutation and records terminal durable error data with code
  `update.node_locked`, the locked resource, the conflicting operation id, and
  the lease expiry time.

## Per-Installation Update Rules

- The gateway updates first as the version ceiling. The gateway phase runs
  before the local caller update or any workload fan-out. If the gateway phase
  fails, the local update does not run.
- The caller-local installation is updated as a fan-out target after the gateway
  phase succeeds, in parallel with the remote workload nodes. Production installs
  update the native CLI binary artifact. Docker/Incus topology nodes that mount
  source for development keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`. A local
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
- Before the gateway phase, the runner downloads every desired CLI artifact from
  the immutable manifest, verifies each SHA-256 hash, and stages the binaries
  under the mounted gateway config root. Workload nodes download CLI binaries
  from the gateway's operation-scoped artifact endpoint. The gateway removes
  per-operation staged binaries after the run, and expired cache directories are
  cleaned opportunistically before staging.
- For `topology-candidate` manifests, the caller-local and workload binary
  update paths compare the desired CLI hash rather than semantic version alone.
  This lets a new same-version candidate build update the fleet while preventing
  repeated installs of the exact same candidate artifact.
- Each updated installation emits per-node sub-stages through the operation
  journal: `Downloading <v>` → `Replacing cli binary` → `Running doctor` → `Done`
  for local/workload nodes; `Downloading <v> assets` → `Updating gateway app` →
  `Replacing cli binary` → `Running doctor` → `Done` for the gateway node.
- Each updated installation runs `orbit doctor` in verify mode as the final
  per-node sub-stage (`Running doctor`). This is verification only; a non-zero
  issue count is surfaced per node but does not by itself fail the node's update.
  If the advisory doctor check fails, times out, or returns unparseable output,
  the update still records the node as completed with an unknown doctor issue
  count. Operators run the normal post-update fleet doctor for release gating.
- Production workload updates install the binary into the node user's Orbit
  install root. When the host launcher is system-wide under `/usr/local/bin/`,
  the remote update also publishes the binary to a shared root-owned executable
  path under `/usr/local/lib/orbit/` and links the system launcher there. This
  lets unprivileged role users execute the CLI without traversing the node
  user's home directory. The remote update may use non-interactive `sudo -n`
  only for the shared copy and symlink replacement; it must fail rather than
  prompt.
- Production workload updates verify the downloaded binary hash before install
  and verify the installed binary hash after relinking the launcher. The gateway
  writes `installed_cli` for that node only after the remote replacement command
  exits successfully.
- The remote update reconciles a shadowing launcher: when `orbit` resolves
  through the node's `PATH` to a launcher other than the relinked one and that
  launcher points at a different binary, it relinks that launcher to the new
  binary too. Best-effort: an unwritable earlier path is left unchanged and the
  node update continues.
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

## Final Verification Rules

- The runner emits terminal success only after verifying gateway health,
  scheduler health, selected workload CLI execution, and required role image
  availability.
- SQLite gateway event streaming uses WAL journal mode and a busy timeout so
  the runner can write operation events while the gateway API reads and streams
  them.

## Partial Failure Rules

- If every selected installation updates successfully, report a full fleet
  success. If one or more installations fail after side effects begin, report
  both successful and failed target results.
- When the gateway installation update fails, do not start the local or
  app-role fan-out.
- When the caller's local installation update fails after the gateway phase
  succeeded, report a partial failure: the fleet was updated but the
  operator-local install was not. The operator re-runs `orbit update` to
  recover.
- When a node with an app role fails, do not hide successful app-role updates
  and do not cancel unrelated in-flight app-role updates.

## Scope Boundaries

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
| Local update failed (partial) | The caller's local CLI update fails after the fleet has already been updated by the gateway phase. | Partial failure. The fleet is updated. The operator re-runs `orbit update` to recover the local install. |
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
| Properties | `scope=fleet`, `operation_run_id`, `status`, `target_version`, gateway image digest, manifest version/source, and `failed_step`. |
| Description | derived |

`status` is `completed` or `failed`. `failed_step` names local, gateway,
scheduler, remote, or verification failures. Per-target results and summary
counts live in the durable operation record, not the activity entry. No process
output, SSH output, environment values, private keys, operation tokens, or
secrets are recorded.

## Test Mapping

Primary existing test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/UpdateAllCommandTest.php` | CLI fleet update contract: local update preflight, durable operation start, event-stream following and reconnects, terminal operation errors, and JSON/human rendering. |
| `apps/gateway/tests/Feature/Http/Api/UpdateAllStartControllerTest.php` | Gateway start API contract: authorization, durable operation creation, and attempt activity logging via route middleware. |
| `apps/gateway/tests/Feature/Http/Api/UpdateAllControllerTest.php` | Gateway operation read/event API contract for durable fleet updates. |
| `apps/gateway/tests/Unit/Http/Gateway/UpdateAllGatewayStreamClientTest.php` | Gateway event-stream client behavior, including reconnect handling. |
| `apps/gateway/tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` | Workload node update fan-out, per-node doctor issue counts, advisory doctor failures, candidate artifact updates, and installed artifact tracking. |
| `apps/gateway/tests/Feature/Services/Operations/UpdateRunnerActivityTest.php` | Durable runner outcome activity entries for completed and failed fleet updates, including best-effort logging-failure handling. |
| `apps/e2e/tests/Feature/Commands/UpdateAllDurableOperationTest.php` | Integrated durable fleet update from an operator through gateway event replay. |
