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
2. Call the gateway to authorize gateway-admin authority and resolve active
   non-gateway role-bearing nodes that are Agent-eligible.
3. Submit a start request to the gateway. The gateway persists an operation row,
   atomically reserves the `fleet:update-all` lease, returns the durable event
   stream URL promptly, and launches a one-shot runner. A concurrent start is
   rejected with `update_lease_conflict` before another runner is launched.
   When the request omits an inline manifest, the gateway defers release-manifest
   resolution to the runner's `Checking for updates` step so the CLI can keep
   visible progress while the latest version is resolved.
4. Follow the operation event journal by monotonic operation-local
   `event_sequence`. The current command uses an exact-marked transitional SSE
   adapter, where `Last-Event-ID` temporarily carries that sequence across
   gateway-service replacement. The global journal row `event_id` is durable
   identity only and is never sent as the resume cursor. The
   target transport is the private operations WebSocket/Reverb plane.
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
  the response are recorded as operation journal errors and release the
  unclaimed fleet reservation.
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
- Include active non-gateway nodes with at least one active role assignment
  only when they are Agent-eligible.
- **Exclude roleless operator identities, regardless of managed Agent intent or
  caller.** Operator workstations update locally through
  [`orbit update`](../../1_update/update.md) on each workstation.
- Exclude inactive, removed, unknown, or caller-local node records from the
  gateway-selected installation list. The local installation is updated once
  through the local target.
- Apply gateway-owned authorization before updating any installation.

The expected target shape per calling context:

| Calling context | Local target | Gateway target | Role-bearing workload targets | Other client targets |
| --- | --- | --- | --- | --- |
| Non-gateway caller with gateway-admin authority | The caller-local installation, updated as a fan-out target after the gateway phase. | Yes, when the gateway is an active node distinct from the caller. Updated first, before local and workload-role targets. | Yes, every active Agent-eligible role-bearing node selected by the rules above. Updated in parallel with the local target after the gateway phase. | Never. |
| Gateway caller | The gateway installation (via the local target). Updated as the gateway phase; the local target concept does not apply separately. | N/A — the gateway is the local target. | Yes, every active Agent-eligible role-bearing node selected by the rules above. | Never. |

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
- Followers replay persisted events after a monotonic operation-local
  `event_sequence`, then follow live frames. `update:all` currently reaches this
  contract through an exact-marked transitional gateway SSE adapter, where
  `Last-Event-ID` carries that sequence. The global journal row `event_id` is
  never a resume position. Duplicate events after reconnect must not be
  rendered twice. The adapter is removed when this command migrates to the operations
  WebSocket/Reverb plane.

## Lease Rules

- Every update entry point that mutates fleet state must acquire an expiring
  update lease before side effects.
- The start request atomically reserves `fleet:update-all` before returning 202.
  An active reservation or runner lease rejects a concurrent start before a
  second runner is launched.
- The launched runner claims that exact reservation by rotating its owner token
  once. A duplicate runner cannot claim or release the active lease. A missing,
  released, mismatched, or already-claimed reservation records a terminal
  operation failure without releasing a lease owned by another runner.
- The runner holds `fleet:update-all` across deferred schema preparation,
  release-manifest resolution, both check steps, gateway replacement, scheduler
  update, workload node updates, and final verification.
- A dedicated heartbeat process renews the fleet lease and every active nested
  lease belonging to the same operation behind the fleet owner-token fence. It
  stops when the runner exits or the operation becomes terminal. The runner
  checks that process at a bounded interval shorter than the lease lifetime.
  Heartbeat exit or renewal failure interrupts the active runner callback,
  records a terminal operation failure, and releases only leases fenced to that
  runner. `SIGTERM` follows the same controlled failure path. If both processes
  disappear before either can record the failure, the bounded lease lifetime
  allows a later start to recover.
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
- Gateway database daemons update stop-first. Scale `orbit-scheduler` and an
  existing `orbit-runtime-hibernator` to zero, run migrations through the
  target gateway image, verify `orbit-gateway`, then start both daemons on the
  matching image. The stack convergence step creates the hibernator during an
  upgrade from a release that does not have that service yet.
- If migrations or gateway health fail, restore both stopped daemons to one
  replica on their previous known-good images when possible. If either recovery
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
  repeated installs of the exact same candidate artifact. The gateway terminal
  result carries the caller-local artifact URL and SHA-256 from the immutable
  plan; the CLI verifies that hash and the target version before replacement.
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
  install root and relink the configured owner user's
  `$HOME/.local/bin/orbit` launcher. Managed Linux nodes usually use the
  `orbit` owner user, while self-managed macOS nodes use the configured local
  owner user such as `nckrtl`. Normal workload update flows do not write or
  refresh protected launchers such as `/usr/local/bin/orbit`; stale protected
  launchers are drift for doctor/deployment adoption, not implicit update
  side effects.
- Production workload updates verify the downloaded binary hash before install
  and verify the installed binary hash after relinking the launcher. The gateway
  writes `installed_cli` for that node only after the remote replacement command
  exits successfully.
- Workload installs that need Agent config, Agent artifacts, or role-image work
  use a two-stage `internal:fleet-update:install-cli` contract.
  - Stage 1 invokes the currently installed CLI with a CLI-only payload:
    artifact URL/hash, install root, and launcher path only. It omits
    `agent_artifact`, `agent_service`, `role_images`, `role_image_artifacts`, and
    `role_image_aliases` so the candidate CLI binary is installed atomically by
    the still-running process that received the dispatch.
  - Stage 2 invokes the same internal command again through the newly installed
    CLI with the full original payload so Agent config, Agent binary, and
    role-image work run under the candidate implementation.
  - Self-update disconnect handling and empty exit code `255` retry remain per
    stage. Final result validation still requires Agent install confirmation
    when the full payload includes an Agent artifact.
  - A CLI-only payload (no Agent or role-image work) stays a single install call.
- After a successful `internal:fleet-update:install-cli` shell install, the
  local install action runs the installed launcher with
  `orbit --version --local --json` (as the final install-script verify) and
  records install metadata only from parseable structured version JSON.
  Successful process exit with missing or malformed version JSON fails the
  install with `fleet_update.cli_version_unstructured` and must not write
  install metadata under a version scraped from human Version table rows,
  progress noise, or any guessed fallback. Fleet verify of the CLI check
  likewise requires structured JSON version output.
- When an Orbit Agent artifact is selected, the remote update verifies the
  installed owner-user local `orbit-agent` hash and restarts a managed
  `orbit-agent` service when one is present, but only after required role image
  archives and registry fallbacks have finished so the self-restart cannot
  interrupt those side effects. If no systemd or launchd service is present but
  an unmanaged listener is running from the installed binary path, the update
  replaces that listener with the new binary and preserves the configured Agent
  endpoint. Writing or replacing managed Agent config and CA trust files during
  that install uses portable shell decoding of gateway-supplied base64 payloads
  and atomic staged install. It must not invoke host `php`. The local install
  action prepares role-image lists and aliases as base64 line records so the
  target shell can iterate them without host PHP JSON helpers.
- Agent-role nodes may expose an `agent` Unix user for tools. That user is a
  consumer user, not a second Orbit owner. Provisioning converges a shim such as
  `/home/agent/.local/bin/orbit` that executes the owner user's CLI with
  `ORBIT_CONFIG_PATH=/home/orbit/.config/orbit/config.json` and
  `ORBIT_INSTALL_METADATA_PATH=/home/orbit/.config/orbit/install.json`. The
  agent user needs read/execute access to run commands, but normal update flows
  must not update owner config or install metadata as that consumer user.
- Workload fan-out uses the same persisted manifest snapshot as the gateway
  update for CLI artifacts, Orbit Agent artifacts, and required role image
  metadata.
- When that snapshot provides a hash-addressed archive for a required role
  image, each selected Linux role host downloads and verifies the archive,
  loads it into the local Docker image store, and confirms the digest-free local
  tag. That verified local tag is the runtime reference; Orbit does not require
  a private-registry pull for the same image. The gateway deploys its operations
  Reverb service with registry resolution disabled, then verifies the expected
  tag and one healthy replica so a Swarm rollback cannot pass as convergence.
  Workload verification also inspects the local tag. When a candidate
  FrankenPHP image needs the stable runtime name, Orbit resolves the exact local
  image ID and aliases that ID to the stable reference before final
  verification. This lets candidate channels verify private role images without
  distributing registry credentials to nodes.
- For each remote update, the gateway authorizes a typed Orbit Agent request
  and pushes it over WireGuard to the selected Agent-eligible node.
  `update:all` never selects SSH, and the gateway does not target operator
  workstations.
- Continue updating remaining installations after a target fails.
- If any workload target result is failed, the workload phase fails with
  `workload_update_failed` and preserves the selected target results before
  final verification starts.
- Preserve every target result for the selected output renderer in selected
  target order, regardless of the order in which parallel workers finish.

## Final Verification Rules

- When the manifest includes Orbit Agent artifacts, final verification waits
  through the scheduled self-restart window and confirms each updated Agent
  listener is ready before dispatching CLI, Agent-hash, or role-image checks.
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
  workload-role fan-out.
- When the caller's local installation update fails after the gateway phase
  succeeded, report a partial failure: the fleet was updated but the
  operator-local install was not. The operator re-runs `orbit update` to
  recover.
- When a selected workload node fails, do not hide successful workload updates
  and do not cancel unrelated in-flight workload updates.

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
| Gateway update failed | The gateway service update, migration, or health verification fails. | Terminal operation failure; local and workload-role targets are not started |
| Scheduler recovery failed | The scheduler could not be restored after failed migrations or gateway health. | Terminal operation failure with explicit recovery metadata |
| Runtime hibernator recovery failed | The runtime hibernator could not be restored after failed migrations or gateway health. | Terminal operation failure with explicit recovery metadata |
| Agent service missing | An Agent artifact is selected, but the target has neither an existing managed Agent service nor an unmanaged Agent listener to replace. | The target fails closed and requires bootstrap to create the first Agent service; `update:all` does not create it. |
| CLI version unstructured | After a successful CLI install shell, `orbit --version --local --json` is missing or not parseable structured version JSON. | Target install fails closed with `fleet_update.cli_version_unstructured`; no install metadata is written under a guessed version. |
| Workload update failed | One or more selected role-bearing workload installations fail to update. | Failure with partial target results |
| Final verification failed | Gateway, scheduler, runtime hibernator, CLI, or required image verification fails after updates. | Terminal operation failure with partial target results |

The shared [Exit Status](../../../README.md#exit-status) policy applies. Partial
fleet failures are Orbit-handled command failures.

## Doctor Relationship

- `update:all` changes Orbit installations.
- It does not verify state-family drift or runtime readiness.
- Run `doctor --family=<family>` after updates to verify convergence for a specific family.
- A remote update failure may leave a node on a different Orbit version.
- Such a version mismatch creates node-family drift.
- Node doctor owns later reachability or readiness diagnosis for those nodes.

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
| `apps/cli/tests/Feature/Services/GatewayOperationEventStreamClientTest.php` | Exact transitional SSE adapter decoding, journal-cursor resume through `Last-Event-ID`, idle callback cadence, TLS CA verification, and no-terminal-event handling. |
| `apps/cli/tests/Feature/Commands/Operation/UpdateAllCommandTest.php` | CLI `update:all` command-path following through reconnects and gateway start failure handling. |
| `apps/gateway/tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` | Workload node update fan-out, per-node doctor issue counts, advisory doctor failures, candidate artifact updates, and installed artifact tracking. |
| `apps/gateway/tests/Feature/Services/Operations/UpdateRunnerActivityTest.php` | Durable runner outcome activity entries for completed and failed fleet updates, including best-effort logging-failure handling. |
| `apps/e2e/tests/Feature/Commands/UpdateAllDurableOperationTest.php` | Integrated durable fleet update from an operator through gateway event replay. |
