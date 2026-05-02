# Technical Contract: `orbit workspace:new`

**Owner:** `workspace`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the parent app.
- The gateway can reach the parent app's owning app node over SSH.

[Back to the public command page.](../workspace-new.md)

## Signature

```bash
orbit workspace:new [name] [--app=<app>] [--base=<ref>] [--php-version=<version>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `name` | `text` | Always (can be prompted). | n/a | Workspace identity slug; `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`; maximum 63 characters. Reserved name `main` is rejected. Must not collide with an existing workspace under the same parent app. |
| `--app` | `text` | No local context or default. | CWD-inferred parent app | Valid parent app slug. |
| `--base` | `text` | Optional. | `main` | Source git ref used to create the worktree. |
| `--php-version` | `text` | Optional. | (parent app PHP version) | Supported PHP version. When omitted, the workspace row stores `null` and inherits the parent app's PHP version. |
| `--json` | `flag` | Optional. | `false` | Forces non-interactive mode and JSON output. |

When `--base` is omitted, the default source ref is hard-coded to `main`.
Operators may supply another explicit ref with `--base=<ref>`. Inheriting an
app-level default branch is not supported because the apps domain does not yet
track a `default_branch` field on app intent. Adding gateway-tracked
default-branch support is a future explicit feature on `app:update`/app intent;
until then `workspace:new` does not consult app intent for this default.

### Input Resolution

1. **Resolve Parent App (`--app`):**
   - Explicit `--app=<slug>`.
   - **CWD inference (gateway-authoritative):** if `--app` is missing, Orbit
     resolves the parent app from gateway-tracked metadata, not from project
     file inspection:
     - **`.orbit/config` marker** installed on the caller filesystem by
       `app:new`/`app:register` (and any workspace-installed marker),
       identifying the owning app slug;
     - **gateway path lookup** keyed on (caller node identity, absolute
       cwd): the gateway returns the app slug whose registered app path or
       any registered workspace path contains the caller's cwd. Both an
       app's main path and an existing workspace path under that app
       resolve to the parent app identity.
   - Orbit must not read `composer.json`, `package.json`, `.php-version`,
     or any other project file content during `workspace:new` to infer the
     parent app. Project-file inspection is reserved for
     `doctor --family=workspace --adopt` (`composer.json` only, and only
     for PHP-version hints during workspace adoption).
   - Interactive prompt or non-interactive failure if still unresolved.
2. **Resolve Workspace Name:** Use the positional `name` argument. If
   missing, prompt in interactive mode; fail in non-interactive mode.
3. **Validate Workspace Identity (gateway-side, static):**
   - Slug regex: `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`.
   - Reserved name: `main` is reserved for the primary app instance in
     runtime unit naming (`orbit_<app>_main_<process>.service`); a workspace
     named `main` would collide with that backend layer.
   - Length: `workspace_slug` must not exceed 63 characters. The workspace
     hostname shape uses the workspace slug as its own DNS label
     (`{workspace}.{app}.{tld}`), so the workspace identity limit is
     independent of the parent app slug. Backend artifact renderers must still
     validate final generated names such as PHP-FPM pools, sockets, systemd
     units, and certificate paths before writing them.
   - Per-app uniqueness: the workspace name must not already exist for the
     resolved parent app. Workspace identity is unique within an app, not
     globally — unlike the `app` slug, which is globally unique.
4. **Resolve PHP Version (`--php-version`):** If supplied, validate against
   Orbit's supported PHP version set with
   `error.code=validation_failed`/`error.meta.field=php_version` before any
   side effects. Node-side runtime availability is verified during
   enactment, not during input resolution. If omitted, the workspace row
   stores `null`, which the gateway interprets as "inherit parent app PHP
   version."

## Caller Role Behavior

`workspace:new` follows the [Workspace Caller Role Rule](../../README.md). The
parent app is already pinned to a specific app node, so `workspace:new`
inherits that target node automatically. App-node callers are denied before
prompts, forwarding, SSH, gateway intent writes, or other side effects.

| Caller role | Validity | Consequence |
| --- | --- | --- |
| `control` | `valid` | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. See [`2_workspace-new_on-control-node.md`](2_workspace-new_on-control-node.md). |
| `gateway` | `valid` | Execute locally on the gateway and enact app-node work over SSH. See [`3_workspace-new_on-gateway-node.md`](3_workspace-new_on-gateway-node.md). |
| `app` | `invalid` | Rejected before prompts or side effects. See [`4_workspace-new_on-app-node.md`](4_workspace-new_on-app-node.md). |

## Input Mode Contracts

- [`5.1_workspace-new_input-mode_interactive.md`](5.1_workspace-new_input-mode_interactive.md)
- [`5.2_workspace-new_input-mode_non-interactive.md`](5.2_workspace-new_input-mode_non-interactive.md)

## Behavior Contract

### Workspace Creation Rules

`workspace:new` is an atomic creation + provisioning command. It does not
support partial-creation flags (e.g. `--keep-files`); operators who want to
register an existing path use
`doctor --family=workspace --adopt` instead. The command performs:

1. **Identity Write (Gateway):** Create the `Workspace` row on the gateway with
   `name`, `app_id`, derived hostname, `php_version` (or `null` for
   inheritance), and lifecycle fields. Workspace identity uniqueness is
   enforced at this step.
2. **Worktree Provisioning (Remote):** The gateway connects to the parent
   app's owning app node over SSH via `RemoteShell` and creates a git
   worktree at the derived workspace path using the requested `--base` ref.
3. **Setup Pipeline (Remote, convergent):** Executes the same convergent
   logic as `workspace:setup`:
   - **Workspace-owned proxy route:** create or update the workspace
     proxy route record; backend artifact convergence is owned by the
     `proxy` family.
   - **PHP-FPM:** render and install the workspace-specific FPM pool config
     on the app node.
   - **Inherited process units:** render and (re)install systemd units
     derived from the parent app's process definitions.
   - **Setup steps:** execute configured workspace setup steps in the
     workspace path with the lifecycle environment defined in
     [Workspaces README](../../README.md#lifecycle-step-environment).
   - **HTTP probe:** perform the same setup-time HTTP probe as
     `workspace:setup`. Probe failures are command warnings, not durable
     workspace state and not doctor issue codes.
4. **Drift Awareness (Success-with-Warnings):** Once the gateway workspace
   row is written, downstream remote enactment failures are reported as
   non-fatal entries in `success.meta.warnings[]` with the canonical
   `{code, family, message, next_command}` shape (codes drawn from the
   `workspace` family, primarily `workspace.path_missing`,
   `workspace.fpm_config_missing`, `workspace.fpm_config_mismatch`,
   `workspace.runtime_config_missing`, `workspace.runtime_config_mismatch`,
   plus `proxy` handoffs for workspace route drift). The command exits `0` and the
   operator repairs drift via `doctor --family=workspace --fix`. This
   matches the `app:new`/`app:register` pattern: once intent is durable,
   enactment drift is convergence work, not a hard failure.
   Setup-time HTTP probe failures use the command-owned
   `workspace.http_probe_unhealthy` warning with `family: null`, matching
   `workspace:setup`.

## Renderer Contracts

- [`6.1_workspace-new_output-render_human.md`](6.1_workspace-new_output-render_human.md)
- [`6.2_workspace-new_output-render_json.md`](6.2_workspace-new_output-render_json.md)

## Failure Semantics

- **Validation failure** — invalid slug, reserved name (`main`),
  workspace-name collision under the parent app, unresolved parent app, or
  unsupported `--php-version` (`error.code=validation_failed`,
  `error.meta.field=<name|php_version|app>`). Fails before any side effects.
- **Caller role not allowed** — app-node callers are rejected before prompts
  or side effects (`error.code=caller_role_not_allowed`).
- **Authorization failure** — caller is not authorized to manage the parent
  app or its node (`error.code=authorization_failed`).
- **Parent app ineligible** — the resolved parent app is missing,
  unauthorized, or unable to own workspaces
  (`error.code=workspace.parent_app_invalid`).
- **SSH failure (pre-intent)** — gateway cannot reach the app node *before*
  the gateway workspace row is written (`error.code=workspace.ssh_failure`).
- **Hard enactment failure** — gateway workspace row was written but a
  downstream step failed in a way that cannot be retried through
  convergence (`error.code=workspace.enactment_failed`,
  `error.meta.step`/`error.meta.reason`). Retryable conditions surface as
  `success.meta.warnings[]` instead.
- **Exit status:** Uses the shared exit status policy. Success and
  success-with-warnings exit `0`; all documented command failures exit with the
  standard command failure status (`1`). This command defines no
  command-specific numeric exit codes.

## Doctor Relationship

- **Family:** `workspace` (see [`workspace-doctor.md`](../../workspace-doctor.md)).
- **Probe:** `doctor --family=workspace --workspace=<name> --app=<app>`
  verifies registry intent and runtime artifacts.
- **Convergence:** `doctor --family=workspace --fix` repairs missing or
  divergent FPM, runtime configuration, and source path drift surfaced by
  `workspace:new` warnings.
- **Adoption:** `doctor --family=workspace --adopt` is the only path for
  registering an existing workspace path under a parent app;
  `workspace:new` itself never adopts unmanaged paths.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceNewCommandContractTest.php` | Input resolution, name/slug validation, reserved-`main` rejection, per-app collision rejection, `--php-version` validation, gateway intent write, drift-warning emission shape, and shared exit-status behavior. |
| `tests/Feature/Actions/Workspaces/CreateWorkspaceActionTest.php` | Internal action contract: identity write, worktree provisioning dispatch, setup-pipeline orchestration, and warning aggregation. |
| `tests/Unit/Services/Workspaces/ResolveParentAppFromCwdTest.php` | CWD inference precedence: explicit `--app` > `.orbit/config` marker > gateway path-ownership lookup; rejection of project-file content reading as a parent-app signal. |
| `tests/E2E/Ephemeral/WorkspaceNewTest.php` | End-to-end workspace creation against a real app node: worktree creation, FPM artifact installation, workspace-owned proxy route, and inherited process unit rendering. |
| `tests/E2E/Ephemeral/WorkspaceNewDriftWarningTest.php` | Real-environment success-with-warning path when the SSH-side enactment fails after the gateway workspace row is written. |
