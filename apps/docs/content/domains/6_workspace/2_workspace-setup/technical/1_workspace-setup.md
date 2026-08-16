# Technical Contract: `orbit workspace:setup`

**Owner:** `workspace`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target workspace or
  parent app.
- The gateway can reach the owning node through Agent push.

[Back to the public command page.](../workspace-setup.md)

## Signature

```bash
orbit workspace:setup [name] [--instance=<app.instance>] [--path=<path>] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `--instance` | `text` | No local context or default. | Local instance default. | Valid parent app slug or instance selector such as `happie.nmbp`. A bare app slug must resolve to exactly one concrete instance or fail with `error.meta.reason=instance_required`. |
| `--path` | `text` | Adopting an unmanaged path. | Caller's current directory resolved to an absolute path on the owning node. | Absolute path on the owning node. See `--path` rules below. |
| `--json` | `flag` | Optional. | `false` | n/a |
| `--stream-json` | `flag` | Optional. | `false` | Forces non-interactive mode and emits newline-delimited progress JSON. Mutually exclusive with `--json`. |

The `--path` value must be an absolute path on the owning node. A relative
or non-absolute value fails before side effects with
`error.code=validation_failed`, `error.meta.field=path`. The path must exist
on the node and must be distinct from the parent app root. It may live outside
the parent app path, including external agent worktree directories.

## Input Resolution

1. **Resolve Workspace Identity**: Resolve `[name]` and parent `app` in this
   order:
   - Explicit `[name]` positional + explicit `--instance`.
   - **Explicit `--path` Codex metadata:** when `[name]` is missing and
     `--path` is a Codex-managed Git worktree at
     `~/.codex/worktrees/<key>/<repo>`, the local CLI reads the worktree's
     `.git` pointer and structured metadata files without shell execution. A
     synced `codex-synced-branch.json` value of `refs/heads/<branch>` resolves
     to that branch's normalized workspace slug. When no synced branch is
     available, a valid `codex-thread.json` resolves to `codex-<key>`. The
     resulting slug is deterministic, valid, and at most 63 characters.
     Explicit `[name]` takes precedence; paths lacking valid Codex metadata
     continue to the path-basename fallback and gateway lookup flow. Codex
     metadata is local tool resolution only — not a workspace source driver.
   - **Explicit `--path` basename fallback:** when `[name]` is missing and
     `--path` plus `--instance` are supplied without usable Codex metadata,
     the workspace name may be derived from the path basename when that
     basename is a valid workspace slug. An instance selector such as
     `happie.nmbp` selects the instance explicitly; a bare app selector
     must resolve to exactly one instance before the workspace may be adopted.
   - **CWD path-ownership lookup (gateway-authoritative):** when `[name]`
     is missing, Orbit asks the gateway to resolve the caller's absolute
     current directory against registered app, instance, and workspace
     paths for the caller's node identity. The lookup returns one of four
     outcomes:
     - `workspace` — CWD is inside a registered workspace path. The gateway
       returns the workspace name, parent app slug, selected instance,
       and stored workspace path. The command proceeds with these values;
       `--instance` and `--path` must agree if also supplied, otherwise the
       command fails with
       `error.code=validation_failed`/`error.meta.field=instance|path` before
       side effects.
     - `app_root` — CWD is a registered instance's own path, not a workspace
       path under it. The command fails before side effects with
       `error.code=workspace.path_is_app_root`,
       `error.meta.app=<app>`, and
       `error.meta.next_command=orbit workspace:new`. The instance root is not
       a workspace and `workspace:setup` does not promote it to one.
     - `inside_app` — CWD is under a registered app or instance path but
       does not match any registered workspace path under that target. The
       parent app and exactly one concrete instance are resolved from the
       lookup. Zero or multiple instance matches fail with
       `error.meta.reason=instance_required`; the workspace name is
       resolved through local Codex Git-worktree metadata when available,
       path-basename fallback when valid, or interactive prompts.
     - `unregistered` — CWD does not match any known app, instance, or
       workspace path. Local Codex Git-worktree metadata may still resolve
       `[name]` when available. Non-interactive mode without a resolved name
       fails fast with `validation_failed`.
   - Interactive prompt for missing `[name]` when no CWD outcome or local
     Codex/path resolution supplied it; non-interactive failure if no prompt
     is available.
   - Explicit and derived workspace names must not be `main`. That name is
     reserved for the parent app source. Validation fails before side effects
     with `error.code=validation_failed`, `error.meta.field=name`, and
     `error.meta.reason=reserved_name`.
2. **Resolve Path**:
   - Explicit `--path` (must be absolute).
   - Workspace `path` returned by the CWD path-ownership lookup or stored on
     the existing workspace row.
   - Caller's current directory, resolved to an absolute path on the owning
     node, when adopting an unregistered path.
3. **Validate Eligibility**:
   - Target node must be reachable and carry an active `app-dev` role. An
     `app-prod` target fails before side effects with
     `workspace.unsupported_for_production`.
   - Path must be a workspace source path, not the parent app root. Explicit
     `--path` adoption may register paths outside the parent app path,
     including external agent worktree directories.
   - Path must exist on the node (created by `workspace:new` or manual
     provisioning before adoption).
   - Adoption is based on explicit command input, local Codex Git-worktree
     metadata when `[name]` is omitted, path-basename fallback, and gateway
     path policy only. `workspace:setup` does not inspect project files such as
     `composer.json`, `package.json`, or `.php-version` to infer workspace
     identity, app ownership, or PHP version. The narrowly scoped Codex
     Git-worktree metadata read above is local tool metadata, not project-file
     inspection. Project-file adoption hints belong only to
     `doctor --family=workspace --adopt` as documented in the Workspaces
     README.

## Input Mode Contracts

- [`5.1_workspace-setup_input-mode_interactive.md`](5.1_workspace-setup_input-mode_interactive.md)
- [`5.2_workspace-setup_input-mode_non-interactive.md`](5.2_workspace-setup_input-mode_non-interactive.md)

## Behavior Contract

`workspace:setup` is an idempotent set-up-or-adopt-or-converge command.
Phases are ordered by reversibility cost; configuration is durable across
phase boundaries.

Authorization is checked once for `workspace:setup` on the resolved
workspace's owning node before setup side effects begin. Sub-actions inside
the setup run — proxy route convergence, remote artifact application, setup
step execution, process starts, and HTTP probing — do not perform additional
grant checks. Their authority derives from the single `workspace:setup`
authorization decision.

1. **Registry Convergence** (`phase=registry`):
   - Ensures a gateway workspace record exists for the resolved identity.
   - If the path is new to Orbit, the workspace is *adopted* under the
     specified app and the durable `workspace.adopted` boolean is set to
     `true` for this run.
2. **Proxy Routing** (`phase=routing`):
   - Ensures a workspace-owned route record exists in `proxy`.
   - Updates the record if configuration has changed.
3. **Artifact Apply** (`phase=artifacts`):
   - Dispatches to the resolved workspace node through Agent push.
   - Preserves an existing `<workspace path>/.env`. If the file is missing,
     initializes it from `<workspace path>/.env.example` when present and then
     overlays the effective workspace env. It never reads or copies the parent
     app `.env`.
   - Applies workspace-specific runtime artifacts (runtime container, environment).
   - Hands proxy backend artifact convergence to the `proxy` family.
4. **Setup Steps** (`phase=setup_steps`):
   - Reads configured setup step definitions owned by the workspace's selected
     instance.
   - Executes steps sequentially in the workspace directory on the node through typed `internal:workspace-setup-step` over agent-push on agent-capable nodes.
   - Setup environment values travel only in the token-bound stdin payload, not in transport metadata or activity summaries.
   - A stored setup command that directly consumes `$ORBIT_APP_PATH/.env` is
     rejected before execution. References to parent `.env.example` remain
     allowed.
   - Upgrade migration removes stored setup and teardown rows that directly
     consume `$ORBIT_APP_PATH/.env`. Teardown also rechecks this boundary and
     skips any unsafe row that bypassed normal writes.
   - Steps receive the lifecycle environment defined in the
     [Workspaces README](../../README.md#lifecycle-step-environment).
5. **Processes** (`phase=processes`):
   - Starts processes inherited from the selected instance if they are
     configured to start on setup.
6. **HTTP Probe** (`phase=http_probe`):
   - Performs an HTTP request to the workspace URL.
   - Passes if status `< 500` within a 10s budget.
   - Reports the result under `success.meta.http_probe`; it does not write
     durable workspace state.

### Idempotence (Re-apply Refresh)

`workspace:setup` always re-applies management. Re-running on a workspace that is already set up
re-renders artifacts and verifies command-owned application. The outcome layer reports which path was taken via
`success.data.result.action`:

- `set_up` — first-time setup of a workspace path that is already in gateway
  configuration (typically just created by `workspace:new`).
- `adopted` — first-time setup where the path existed on the node but was
  unmanaged. Identity may come from explicit input, local Codex Git-worktree
  metadata for an explicit `--path`, or path-basename fallback. The durable
  `workspace.adopted` boolean is set to `true` for this run; subsequent re-runs
  report `result.action=converged` with `workspace.adopted=true` preserved.
- `converged` — idempotent re-application of an already-managed workspace
  where no observable artifact change was needed.

This separation keeps durable adoption state on the workspace entity while
letting `result.action` describe what this run did, mirroring the
`instance:register` exemplar.

## Renderer Contracts

- [`technical/6.1_workspace-setup_output-render_human.md`](6.1_workspace-setup_output-render_human.md)
- [`technical/6.2_workspace-setup_output-render_json.md`](6.2_workspace-setup_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

`workspace:setup` rejects an instance served by `app-prod` before registry,
Agent-push, or runtime effects. This failure uses
`error.code=workspace.unsupported_for_production`.

- **Path Is Instance Root**: The resolved CWD is a registered instance's own path, not
  a workspace path under it. Fails before side effects with
  `error.code=workspace.path_is_app_root`, `error.meta.app=<app>`,
  `error.meta.path=<cwd>`, and
  `error.meta.next_command=orbit workspace:new`. The app root is not a
  workspace and `workspace:setup` never promotes it to one. The hint points
  the operator at [`workspace:new`](../../1_workspace-new/workspace-new.md).

- **Path Is Instance Root (Explicit `--path`)**: The supplied `--path` equals the
  parent app's own root path. Fails before side effects with
  `error.code=workspace.path_is_app_root`, `error.meta.app=<app>`,
  `error.meta.path=<path>`, and
  `error.meta.next_command=orbit workspace:new`.
- **Remote Failures**: Agent-push timeout, permission denied, or remote command
  termination that prevents Orbit from classifying the remaining artifact
  state (`error.code=workspace.enactment_failed`, `error.meta.phase`,
  `error.meta.node`, and a stable `error.meta.reason`). Process-start failures
  use `process_start_failed`; plan construction uses
  `plan_construction_failed`; progress-tree initialization uses
  `reporter_initialization_failed`; other unclassified failures use
  `unexpected_failure`.
  Retryable runtime container or runtime artifact drift is reported as
  `success.meta.warnings[]` with the owning family code. Public warnings do not
  contain raw exception details.
- **Setup Step Failure**: A sequential setup step returned non-zero. Reported
  as `error.code=workspace.setup_step_failed` with
  `error.meta.{step, exit_code, node, path, phase=setup_steps,
  reason=setup_step_failed}`. Detailed output remains in workspace run history
  and is not returned in public JSON or stream JSON. **No rollback**: registry,
  routing, and artifact phases that completed before the step failure remain in
  place. The retry path is re-running `workspace:setup`; app-side scripts are
  expected to be re-runnable.
- **HTTP Probe Warning**: The workspace page returns `>= 500`, a Vite asset
  returns `>= 400`, or a page or asset request fails. Reported as a non-fatal
  warning under `success.meta.warnings[]` with
  `code=workspace.http_probe_unhealthy` and a retry command. The command
  itself succeeds because management-command success does not assert
  application HTTP health. This warning is command-owned metadata, not a
  `workspace` doctor issue code.

### Non-Rollback Policy

When a phase after `phase=registry` fails, configuration and gateway-managed
artifacts already written remain in place. This is the same convergence
policy used by `instance:register` for production-domain activation: configuration
persists, retry by re-running the same command. Setup-step failures are
*not* converted into `success.meta.warnings[]` because doctor cannot fix a
failing app script; they belong to command outcome and workspace
history.

### Exit Status

Uses the shared exit status policy. Success and success-with-warnings exit `0`;
all documented command failures exit with the standard command failure status
(`1`). This command defines no numeric exit codes specific to it.

## Doctor Relationship

- `workspace:setup` resolves drift detected by `doctor --family=workspace`.
- Family doctor behavior is documented in [`workspace-doctor.md`](../../workspace-doctor.md).
- HTTP probe results from setup time are command metadata. The warning code
  `workspace.http_probe_unhealthy` is not owned by the workspaces doctor
  family.
- Failed setup-step runs are visible through `workspace:history` and
  `workspace:run:log`; doctor verifies current workspace reality and does not
  rewrite past runs.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php` | Configuration convergence, adoption logic, step-tree orchestration, `result.action` selection across `set_up`/`adopted`/`converged` paths, `success.meta.warnings[]` payloads, and per-phase failure metadata. |
| `apps/gateway/tests/Feature/Actions/Workspaces/WorkspacePlanParityTest.php` | One ordered setup plan plus controller-level JSON/SSE success and failure envelopes, including environment initialization, ordered phase events, exact errors, non-rollback state, and final-result parity. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceSetupTargetResolverTest.php` | Explicit `--path` adoption outside the parent app path, Codex/path-basename identity for path setup without a positional name, and parent-instance-root rejection before side effects. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceWriteCommandTest.php` | Gateway forwarding, local-workflow setup paths, and `workspace:setup` validation before opening a stream. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStreamCommandTest.php` | Streamed setup rendering, gateway progress, and failure output paths. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceSetupStepRunnerTest.php` | Sequential execution, agent-push dispatch, lifecycle environment exposure, fail-fast on non-zero exit, and `error.meta.phase=setup_steps` propagation. |
| `apps/cli/tests/Feature/InternalWorkspaceSetupStepCommandTest.php` | Token rejection, payload validation, success/failure output capture, and forbidden env keys. |
| `apps/e2e/tests/Feature/Commands/WorkspaceSetupTest.php` | Real-node setup, adoption, and idempotent re-apply refresh including non-rollback retry path. |

Role-specific behavior and test mapping live in:

- [`2_workspace-setup_on-client.md`](2_workspace-setup_on-client.md)
- [`3_workspace-setup_on-gateway-node.md`](3_workspace-setup_on-gateway-node.md)
