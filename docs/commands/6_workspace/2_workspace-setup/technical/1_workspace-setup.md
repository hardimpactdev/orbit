# Technical Contract: `orbit workspace:setup`

**Owner:** `workspace`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target workspace or
  parent app.
- The gateway can reach the owning app node over SSH.

[Back to the public command page.](../workspace-setup.md)

## Signature

```bash
orbit workspace:setup [name] [--app=<app>] [--path=<path>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `name` | `[name]` | When local workspace context cannot resolve it. | Local workspace context when available. | Workspace slug (lowercase letters, digits, and hyphens; max 63 chars independent of the parent app slug; cannot start/end with hyphen). |
| `--app` | `text` | No local context or default. | Local app default | Valid parent app slug. |
| `--path` | `text` | Adopting an unmanaged path. | Caller's current directory resolved to an absolute path on the owning app node. | **Absolute path on the owning app node.** A relative or non-absolute value fails before side effects with `error.code=validation_failed`, `error.meta.field=path`. The path must exist on the app node and satisfy the parent app's workspace source policy. Generic worktree paths must live under `<app path>/.worktrees/`. |
| `--json` | `flag` | Optional. | `false` | n/a |

## Authorization By Caller Role

The CLI is a thin gateway client in every case: it gathers input and forwards
to the gateway, which authenticates the caller's WireGuard peer identity and
applies authorization. The gateway then applies artifacts on the owning app
node by opening an SSH connection through RemoteShell — even when the caller
is on that same app node.

| Caller peer | Gateway authorization | Consequence |
| --- | --- | --- |
| Control peer | Allowed when authorized | The CLI forwards over HTTPS through WireGuard. The gateway writes registry state and applies artifacts on the app node via RemoteShell. |
| Gateway peer | Allowed when authorized | The CLI on the gateway invokes the local setup flow; the gateway writes registry state and applies artifacts on the owning app node via RemoteShell. |
| App-node peer | Allowed when authorized | Documented local workflow exception so developers and agents working inside a workspace can re-converge it without switching nodes. The CLI forwards configuration to the gateway over HTTPS; the gateway applies artifacts on the same app node via RemoteShell. |

The gateway authorizes app-node peers for `workspace:setup` as the current
local workflow write exception. The CLI on the app node is a stateless
gateway client; it does not own durable workspace state and never applies
workspace artifacts directly.

See also:
- [`technical/2_workspace-setup_on-control-node.md`](2_workspace-setup_on-control-node.md)
- [`technical/3_workspace-setup_on-gateway-node.md`](3_workspace-setup_on-gateway-node.md)
- [`technical/4_workspace-setup_on-app-node.md`](4_workspace-setup_on-app-node.md)

## Input Resolution

1. **Resolve Workspace Identity**: Resolve `[name]` from the positional
   argument, local workspace context, or interactive prompt.
2. **Resolve Parent App**:
   - Explicit `--app`.
   - Existing parent app if the workspace is already registered.
   - Local app context (e.g. from `.orbit/config` or current directory).
   - Interactive prompt or non-interactive failure.
3. **Resolve Path**:
   - Explicit `--path` (must be absolute).
   - Existing path if the workspace is already registered.
   - Caller's current directory, resolved to an absolute path on the owning
     app node.
4. **Validate Eligibility**:
   - Target app node must be reachable.
   - Path must satisfy the parent app's workspace source policy. Generic
     worktree paths must live under `<app path>/.worktrees/`; adapter-owned
     paths are represented by `workspace:new` through stored adapter metadata.
   - Path must exist on the app node (created by `workspace:new` or manual
     provisioning before adoption).
   - Adoption is based on explicit command input and gateway path policy only.
     `workspace:setup` does not inspect project files such as `composer.json`,
     `package.json`, or `.php-version` to infer workspace identity, app
     ownership, or PHP version. Project-file adoption hints belong only to
     `doctor --fix --family=workspace --adopt` as documented in the Workspaces
     README.

## Input Mode Contracts

- [`5.1_workspace-setup_input-mode_interactive.md`](5.1_workspace-setup_input-mode_interactive.md)
- [`5.2_workspace-setup_input-mode_non-interactive.md`](5.2_workspace-setup_input-mode_non-interactive.md)

## Behavior Contract

`workspace:setup` is an idempotent set-up-or-adopt-or-converge command.
Phases are ordered by reversibility cost; configuration is durable across
phase boundaries.

1. **Registry Convergence** (`phase=registry`):
   - Ensures a gateway workspace record exists for the resolved identity.
   - If the path is new to Orbit, the workspace is *adopted* under the
     specified app and the durable `workspace.adopted` boolean is set to
     `true` for this run.
2. **Proxy Routing** (`phase=routing`):
   - Ensures a workspace-owned route record exists in `proxy`.
   - Updates the record if configuration has changed.
3. **Artifact Apply** (`phase=artifacts`):
   - Connects to the app node via SSH.
   - Applies workspace-specific runtime artifacts (PHP-FPM pool, environment).
   - Hands proxy backend artifact convergence to the `proxy` family.
4. **Setup Steps** (`phase=setup_steps`):
   - Reads configured setup step definitions for the parent app.
   - Executes steps sequentially in the workspace directory on the app node.
   - Steps receive the lifecycle environment defined in the
     [Workspaces README](../../README.md#lifecycle-step-environment).
5. **Processes** (`phase=processes`):
   - Starts inherited app processes if they are configured to start on setup.
6. **HTTP Probe** (`phase=http_probe`):
   - Performs an HTTP request to the workspace URL.
   - Passes if status `< 500` within a 10s budget.
   - Reports the result under `success.meta.http_probe`; it does not write
     durable workspace state.

### Idempotence (Re-apply Refresh)

`workspace:setup` always re-applies management. Re-running on an already-set-up
workspace re-renders artifacts and verifies command-owned application. The
command-outcome layer reports which path was taken via
`success.data.result.action`:

- `set_up` — first-time setup of a workspace path that is already in gateway
  configuration (typically just created by `workspace:new`).
- `adopted` — first-time setup where the path existed on the app node but was
  unmanaged. The durable `workspace.adopted` boolean is set to `true` for this
  run; subsequent re-runs report `result.action=converged` with
  `workspace.adopted=true` preserved.
- `converged` — idempotent re-application of an already-managed workspace
  where no observable artifact change was needed.

This separation keeps durable adoption state on the workspace entity while
letting `result.action` describe what this run did, mirroring the
`app:register` exemplar.

## Renderer Contracts

- [`technical/6.1_workspace-setup_output-render_human.md`](6.1_workspace-setup_output-render_human.md)
- [`technical/6.2_workspace-setup_output-render_json.md`](6.2_workspace-setup_output-render_json.md)

## Failure Semantics

- **Validation Failures**: Invalid workspace name or non-absolute `--path`.
  Reported as `error.code=validation_failed` with `error.meta.field` naming
  the offending input. Fails before side effects.
- **Path Outside Policy**: The resolved generic workspace path is outside the
  parent app's `.worktrees/` policy
  (`error.code=workspace.path_outside_policy`).
  Fails before side effects.
- **Authorization Failed**: The caller is not authorized to manage the
  target workspace or parent app
  (`error.code=authorization_failed`).
- **Remote Failures**: SSH timeout, permission denied, or remote command
  termination that prevents Orbit from classifying the remaining artifact
  state (`error.code=workspace.enactment_failed`, `error.meta.phase`,
  `error.meta.node`). Retryable PHP-FPM or runtime artifact drift is
  reported as `success.meta.warnings[]` with the owning family code.
- **Setup Step Failure**: A sequential setup step returned non-zero. Reported
  as `error.code=workspace.setup_step_failed` with
  `error.meta.{step, exit_code, node, path, phase=setup_steps}`. **No
  rollback**: registry, routing, and artifact phases that completed before
  the step failure remain in place. The retry path is re-running
  `workspace:setup`; project-side scripts are expected to be re-runnable.
- **HTTP Probe Warning**: Workspace returns `>= 500` or times out. Reported as a
  non-fatal warning under `success.meta.warnings[]` with
  `code=workspace.http_probe_unhealthy` and a retry command. The command
  itself succeeds because management-command success does not assert
  application HTTP health. This warning is command-owned metadata, not a
  `workspace` doctor issue code.

### Non-Rollback Policy

When a phase after `phase=registry` fails, configuration and gateway-managed
artifacts already written remain in place. This is the same convergence
policy used by `app:register` for production-domain activation: configuration
persists, retry by re-running the same command. Setup-step failures are
*not* converted into `success.meta.warnings[]` because doctor cannot fix a
failing project script; they belong to command outcome and workspace
history.

### Exit Status

Uses the shared exit status policy. Success and success-with-warnings exit `0`;
all documented command failures exit with the standard command failure status
(`1`). This command defines no command-specific numeric exit codes.

## Doctor Relationship

- `workspace:setup` resolves drift detected by `doctor --family=workspace`.
- Family doctor behavior is documented in [`workspace-doctor.md`](../../workspace-doctor.md).
- Setup-time HTTP probe results are command metadata. The warning code
  `workspace.http_probe_unhealthy` is not owned by the workspaces doctor
  family.
- Failed setup-step runs are visible through `workspace:history` and
  `workspace:log`; doctor verifies current workspace reality instead of
  rewriting historical runs.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php` | Configuration convergence, adoption logic, step-tree orchestration, `result.action` selection across `set_up`/`adopted`/`converged` paths, and per-phase failure metadata. |
| `tests/Feature/Commands/Workspaces/WorkspaceSetupCommandTest.php` | Input resolution, `--path` absolute-validation, gateway-applied allowance for control/gateway/app-node peers, interactive prompts, JSON envelope alignment with the canonical contract, and warning payload shape for `success.meta.warnings[]`. |
| `tests/Feature/Commands/Workspaces/WorkspaceSetupCallerRoleTest.php` | App-node peer forwarding to the gateway as a documented local-workflow exception, control/gateway peer orchestration, and unauthorized-app rejection before side effects. |
| `tests/Unit/Services/Workspaces/WorkspaceSetupStepRunnerTest.php` | Sequential execution, lifecycle environment exposure, fail-fast on non-zero exit, and `error.meta.phase=setup_steps` propagation. |
| `tests/E2E/Ephemeral/WorkspaceSetupTest.php` | Real-node setup, adoption, and idempotent re-apply refresh including non-rollback retry path. |
| `tests/E2E/Ephemeral/WorkspaceSetupStepExecutionTest.php` | Real step execution with lifecycle env verification and step-failure reporting. |
