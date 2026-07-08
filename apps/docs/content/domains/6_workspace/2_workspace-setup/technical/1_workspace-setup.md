# Technical Contract: `orbit workspace:setup`

**Owner:** `workspace`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target workspace or
  parent app.
- The gateway can reach the owning node over SSH.

[Back to the public command page.](../workspace-setup.md)

## Signature

```bash
orbit workspace:setup [name] [--app=<app>] [--path=<path>] [--node-transport=<transport>] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `name` | `[name]` | When local workspace context cannot resolve it. | Local workspace context when available. | Workspace slug (lowercase letters, digits, and hyphens; max 63 chars independent of the parent app slug; cannot start/end with hyphen). |
| `--app` | `text` | No local context or default. | Local app default | Valid parent app slug or app-instance selector such as `happie.nmbp`. |
| `--path` | `text` | Adopting an unmanaged path. | Caller's current directory resolved to an absolute path on the owning node. | Absolute path on the owning node. See `--path` rules below. |
| `--node-transport` | `text` | Optional. | `auto` | One of `auto`, `agent-push`, or `transitional-ssh-fallback`. |
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
   - Explicit `[name]` positional + explicit `--app`.
   - **CWD path-ownership lookup (gateway-authoritative):** when `[name]`
     is missing, Orbit asks the gateway to resolve the caller's absolute
     current directory against registered app and workspace paths for the
     caller's node identity. The lookup returns one of four outcomes:
     - `workspace` — CWD is inside a registered workspace path. The gateway
       returns the workspace name, parent app slug, selected app instance
       when present, and stored workspace path. The command proceeds with
       these values; `--app` and `--path` must agree if also supplied,
       otherwise the command fails with
       `error.code=validation_failed`/`error.meta.field=app|path` before
       side effects.
     - `app_root` — CWD is a registered app's own path, not a workspace
       path under it. The command fails before side effects with
       `error.code=workspace.path_is_app_root`,
       `error.meta.app=<app>`, and
       `error.meta.next_command=orbit workspace:new`. The app root is not
       a workspace and `workspace:setup` does not promote it to one.
     - `inside_app` — CWD is under a registered app or app-instance path but
       does not match any registered workspace path under that target. The
       parent app and app instance, when safely inferable, are resolved from
       the lookup; the workspace name is resolved through the adapter probe
       below, or through interactive prompts.
     - `unregistered` — CWD does not match any known app or workspace path.
       The adapter probe below runs across all of the caller node's
       configured adapters; otherwise fall through to local-context
       defaults (`.orbit/config` marker for `--app`) and interactive
       prompts. Non-interactive mode without an adapter resolution fails
       fast with `validation_failed`.
   - **Agent-IDE adapter probe** (after the CWD lookup, when `[name]` is
     still missing and the lookup outcome was `inside_app` or
     `unregistered`):
     - The CLI gathers the **effective adapters** to probe:
       - On `inside_app`, only the parent app's effective adapter.
       - On `unregistered`, every adapter currently effective for any app
         owned by the caller's node.
     - Each effective adapter that exposes the `workspace_path_resolution`
       capability is asked to resolve the absolute CWD to one of its
       managed workspaces. The adapter returns either no match or a
       descriptor with workspace name, parent app slug, absolute path, and
       adapter workspace id.
     - Outcomes:
       - Exactly one adapter returns a match → use the returned workspace
         name and parent app for identity. Explicit `--app` and `[name]`,
         if supplied, must agree with the adapter; mismatches fail with
         `error.code=validation_failed`,
         `error.meta.field=app|name`, `error.meta.reason=adapter_mismatch`
         before side effects.
       - Multiple adapters return a match → fail with
         `error.code=validation_failed`, `error.meta.field=app`,
         `error.meta.reason=adapter_ambiguous`,
         `error.meta.adapters=[…]`. The operator disambiguates with
         `--app`.
       - No adapter returns a match → continue to prompts / non-interactive
         failure.
       - An adapter errors during probe (transport, auth, unexpected
         response) → fail with
         `error.code=workspace.agent_ide_path_resolution_failed`,
         `error.meta.adapter=<name>`, `error.meta.reason=<short>`. The
         probe does not silently fall through on adapter errors so the
         operator does not get a confusingly different identity from a
         partial probe.
   - Interactive prompt for missing `[name]` when no CWD outcome or adapter
     probe resolved it; non-interactive failure if no prompt is available.
2. **Resolve Path**:
   - Explicit `--path` (must be absolute).
   - Workspace `path` returned by the CWD path-ownership lookup or stored on
     the existing workspace row.
   - Caller's current directory, resolved to an absolute path on the owning
     node, when adopting an unregistered path.
3. **Validate Eligibility**:
   - Target node must be reachable.
   - Path must be a workspace source path, not the parent app root. Explicit
     `--path` adoption may register paths outside the parent app path,
     including external agent worktree directories.
   - Path must exist on the node (created by `workspace:new` or manual
     provisioning before adoption).
   - Adoption is based on explicit command input and gateway path policy only.
     `workspace:setup` does not inspect project files such as `composer.json`,
     `package.json`, or `.php-version` to infer workspace identity, app
     ownership, or PHP version. Project-file adoption hints belong only to
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
   - Connects to the resolved workspace node via SSH.
   - Applies workspace-specific runtime artifacts (runtime container, environment).
   - Hands proxy backend artifact convergence to the `proxy` family.
4. **Setup Steps** (`phase=setup_steps`):
   - Reads configured setup step definitions for the parent app.
   - Executes steps sequentially in the workspace directory on the node.
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

`workspace:setup` always re-applies management. Re-running on a workspace that is already set up
re-renders artifacts and verifies command-owned application. The outcome layer reports which path was taken via
`success.data.result.action`:

- `set_up` — first-time setup of a workspace path that is already in gateway
  configuration (typically just created by `workspace:new`).
- `adopted` — first-time setup where the path existed on the node but was
  unmanaged. Identity may come from explicit input or from an agent-IDE
  adapter probe (for example, a PolyScope worktree the adapter manages but
  Orbit did not yet know about). The durable `workspace.adopted` boolean is
  set to `true` for this run; subsequent re-runs report
  `result.action=converged` with `workspace.adopted=true` preserved. When
  the adapter resolved identity, the workspace row records `agent_ide` and
  `agent_ide_workspace_id` from the adapter descriptor.
- `converged` — idempotent re-application of an already-managed workspace
  where no observable artifact change was needed.

This separation keeps durable adoption state on the workspace entity while
letting `result.action` describe what this run did, mirroring the
`app:register` exemplar.

## Renderer Contracts

- [`technical/6.1_workspace-setup_output-render_human.md`](6.1_workspace-setup_output-render_human.md)
- [`technical/6.2_workspace-setup_output-render_json.md`](6.2_workspace-setup_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

- **Path Is App Root**: The resolved CWD is a registered app's own path, not
  a workspace path under it. Fails before side effects with
  `error.code=workspace.path_is_app_root`, `error.meta.app=<app>`,
  `error.meta.path=<cwd>`, and
  `error.meta.next_command=orbit workspace:new`. The app root is not a
  workspace and `workspace:setup` never promotes it to one. The hint points
  the operator at [`workspace:new`](../../1_workspace-new/workspace-new.md).
- **Agent IDE Path Resolution Failed**: An effective agent-IDE adapter
  errored while resolving the CWD to a managed workspace (transport, auth,
  or unexpected adapter response). Fails before side effects with
  `error.code=workspace.agent_ide_path_resolution_failed`,
  `error.meta.adapter=<name>`, and `error.meta.reason=<short>`. The probe
  does not silently fall through on adapter errors.
- **Path Is App Root (Explicit `--path`)**: The supplied `--path` equals the
  parent app's own root path. Fails before side effects with
  `error.code=workspace.path_is_app_root`, `error.meta.app=<app>`,
  `error.meta.path=<path>`, and
  `error.meta.next_command=orbit workspace:new`.
- **Remote Failures**: SSH timeout, permission denied, or remote command
  termination that prevents Orbit from classifying the remaining artifact
  state (`error.code=workspace.enactment_failed`, `error.meta.phase`,
  `error.meta.node`). Retryable runtime container or runtime artifact drift is
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
(`1`). This command defines no numeric exit codes specific to it.

## Doctor Relationship

- `workspace:setup` resolves drift detected by `doctor --family=workspace`.
- Family doctor behavior is documented in [`workspace-doctor.md`](../../workspace-doctor.md).
- HTTP probe results from setup time are command metadata. The warning code
  `workspace.http_probe_unhealthy` is not owned by the workspaces doctor
  family.
- Failed setup-step runs are visible through `workspace:history` and
  `workspace:log`; doctor verifies current workspace reality and does not
  rewrite past runs.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php` | Configuration convergence, adoption logic, step-tree orchestration, `result.action` selection across `set_up`/`adopted`/`converged` paths, `success.meta.warnings[]` payloads, and per-phase failure metadata. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceSetupTargetResolverTest.php` | Explicit `--path` adoption outside the parent app path and parent-app-root rejection before side effects. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceWriteCommandTest.php` | Gateway forwarding, local-workflow setup paths, and `workspace:setup` validation before opening a stream. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStreamCommandTest.php` | Streamed setup rendering, gateway progress, and failure output paths. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceSetupStepRunnerTest.php` | Sequential execution, lifecycle environment exposure, fail-fast on non-zero exit, and `error.meta.phase=setup_steps` propagation. |
| `apps/e2e/tests/Feature/Commands/WorkspaceSetupTest.php` | Real-node setup, adoption, and idempotent re-apply refresh including non-rollback retry path. |

Role-specific behavior and test mapping live in:

- [`2_workspace-setup_on-client.md`](2_workspace-setup_on-client.md)
- [`3_workspace-setup_on-gateway-node.md`](3_workspace-setup_on-gateway-node.md)
