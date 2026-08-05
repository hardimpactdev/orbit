# Technical Contract: `orbit workspace:new`

**Owner:** `workspace`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to run `workspace:new` on the selected
  instance's owning node.
- The gateway can reach the effective workspace node through Agent push.

[Back to the public command page.](../workspace-new.md)

## Signature

```bash
orbit workspace:new [name] [--instance=<app.instance>] [--base=<ref>] [--php-version=<version>] [--json|--stream-json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `name` | `text` | Always (can be prompted). | n/a | Workspace identity slug; `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`; maximum 63 characters. Reserved name `main` is rejected. Must not collide with an existing workspace under the same parent project. |
| `--instance` | `text` | No explicit selector or usable local context. | CWD-inferred concrete instance. | Dotted selectors choose one instance directly. A bare project or path context must resolve uniquely. |
| `--base` | `text` | Optional. | `main` | Source git ref/branch used by the worktree source driver. Orbit creates branch `<workspace>` from this ref. |
| `--php-version` | `text` | Optional. | (parent project PHP version) | Supported PHP version. When omitted, the workspace row stores `null` and inherits the parent project's PHP version. |
| `--json` | `flag` | Optional. | `false` | Forces non-interactive mode and JSON output. |
| `--stream-json` | `flag` | Optional. | `false` | Forces non-interactive mode and emits newline-delimited progress JSON. Mutually exclusive with `--json`. |

A bare parent project slug, marker, or parent path is shorthand only when exactly
one registered instance matches. Zero or multiple matches fail with
`error.meta.reason=instance_required`.

When `--base` is omitted, the default source ref is hard-coded to `main`.
Operators may supply another explicit ref with `--base=<ref>`. Inheriting an
instance-level default branch is not supported because the app domain does not yet
track a `default_branch` field on project configuration. Adding gateway-tracked
default-branch support is a future explicit feature on `app:update`/project
configuration; until then `workspace:new` does not consult project configuration
for this default.

### Input Resolution

1. **Resolve Concrete Instance (`--instance`):**
   - An explicit dotted selector such as `happie.nmbp` resolves that concrete
     instance directly.
   - An explicit bare parent project slug is shorthand only when the gateway finds
     exactly one registered instance for that project.
   - **CWD inference (gateway-authoritative):** if `--instance` is missing, Orbit
     resolves a concrete instance from gateway-tracked metadata, not from
     project file inspection:
     - **`.orbit/config` marker** installed on the caller filesystem by
       `app:new`/`instance:register` (and any workspace-installed marker),
       identifying an app or concrete instance;
     - **gateway path lookup** keyed on (caller node identity, absolute
       cwd): a path owned by an instance resolves that instance directly.
       The same applies to a workspace path owned by an instance. An instance main
       path or parent-project-only marker is shorthand and must resolve to exactly
       one registered instance.
   - If a bare selector or inferred parent project has zero or multiple concrete
     instances, fail before side effects with `error.code=validation_failed`,
     `error.meta.field=instance`, and
     `error.meta.reason=instance_required`. Do not fall back to a canonical
     instance serving node.
   - Orbit must not read `composer.json`, `package.json`, `.php-version`,
     or any other project file content during `workspace:new` to infer the
     parent project. Project-file inspection is reserved for
     `doctor --family=workspace --adopt` (`composer.json` only, and only
     for PHP-version hints during workspace adoption).
   - When no selector or usable context exists, interactive mode prompts from
     concrete instances; non-interactive mode fails. The selected result is
     always one concrete instance.
2. **Resolve Workspace Name:** Use the positional `name` argument. If
   missing, prompt in interactive mode; fail in non-interactive mode.
3. **Validate Workspace Identity (gateway-side, static):**
   - Slug regex: `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`.
   - Reserved name: `main` is reserved for the primary instance in
     runtime unit naming (`orbit_<project>_<instance>_main_<process>`); a workspace named
     `main` would collide with that backend layer.
   - Length: `workspace_slug` must not exceed 63 characters. The workspace
     hostname shape uses the workspace slug as its own DNS label
     (`{workspace}.{app}.{tld}`), so the workspace identity limit is
     independent of the parent project slug. Backend artifact renderers must
     still validate final generated names such as runtime containers, Docker
     process services, systemd process units on supported Linux nodes, launchd
     process jobs on macOS, and certificate paths before writing them.
   - Per-project uniqueness: the workspace name must not already exist for the
     resolved parent project. Workspace identity is unique within an app, not
     globally - unlike the `project` slug, which is globally unique. An instance
     selector chooses placement and URL context. It does not create a separate
     namespace for duplicate workspace names under the same parent project.
4. **Resolve PHP Version (`--php-version`):** If supplied, validate against
   Orbit's supported PHP version set with
   `error.code=validation_failed`/`error.meta.field=php_version` before any
   side effects. Node-side runtime availability is verified during
   applying, not during input resolution. If omitted, the workspace row
   stores `null`, which the gateway interprets as "inherit parent project PHP
   version."

## Input Mode Contracts

- [`5.1_workspace-new_input-mode_interactive.md`](5.1_workspace-new_input-mode_interactive.md)
- [`5.2_workspace-new_input-mode_non-interactive.md`](5.2_workspace-new_input-mode_non-interactive.md)

## Behavior Contract

### Workspace Creation Rules

`workspace:new` is an atomic creation + provisioning command. It does not
support partial-creation flags (e.g. `--keep-files`); operators who want to
register an existing path use `workspace:setup --path` (routine adoption).
`doctor --family=workspace --adopt` remains the disaster-recovery bulk path.
The command performs:

1. **Workspace Source Provisioning:** Create a generic Git worktree on the
   effective workspace node at `<selected app path>/.worktrees/<name>` by
   creating branch `<name>` from the requested `--base` ref through the
   worktree source driver (`WorktreeWorkspaceDriver`). This is the only
   product workspace source driver.
2. **Identity Write (Gateway):** Create the `Workspace` row on the gateway with
   the driver-returned `name` and physical `path`, `app_id`, a mandatory
   non-null `instance_id`, derived hostname, `php_version` (or `null` for
   inheritance), and lifecycle fields. Workspace identity uniqueness is
   enforced before any side effects and again at this step.
3. **Setup Pipeline (Remote, convergent):** Executes the same convergent
   logic as `workspace:setup`:
   - **Workspace-owned proxy route:** create or update the workspace
     proxy route record; backend artifact convergence is owned by the
     `proxy` family.
   - **Runtime container:** render and install the runtime container
     configuration specific to this workspace
     on the node.
   - **Setup steps:** execute configured workspace setup steps in the
     workspace path with the lifecycle environment defined in
     [Workspaces README](../../README.md#lifecycle-step-environment).
   - **Inherited runtime units:** render and (re)install process runtime units
     derived from the selected instance's process definitions through the
     serving node's supported backend: systemd on supported Linux and launchd
     on macOS.
   - **HTTP probe:** perform the same HTTP probe that `workspace:setup`
     performs at setup time. Probe failures are command warnings, not durable
     workspace state and not doctor issue codes.
4. **Drift Awareness (Success-with-Warnings):** Once the gateway workspace
   row is written, downstream remote apply failures are reported as
   non-fatal entries in `success.meta.warnings[]` with the canonical
   `{code, family, message, next_command}` shape (codes drawn from the
   `workspace` family, primarily `workspace.path_missing`,
   `workspace.runtime_config_missing`, and `workspace.runtime_config_mismatch`;
   the `process` family for `process.runtime_unit_missing` or
   `process.runtime_unit_mismatch`; plus `proxy` handoffs for workspace route
   drift). The operator repairs each warning through its owning family doctor.
   This matches the
   `app:new`/`instance:register` pattern: once configuration is durable, apply drift
   is convergence work, not a hard failure.
   HTTP probe failures that occur at setup time use the command-owned
   `workspace.http_probe_unhealthy` warning with `family: null`, matching
   `workspace:setup`.

## Renderer Contracts

- [`6.1_workspace-new_output-render_human.md`](6.1_workspace-new_output-render_human.md)
- [`6.2_workspace-new_output-render_json.md`](6.2_workspace-new_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

- **Parent project ineligible** — the resolved parent project is missing,
  unauthorized, or unable to own workspaces
  (`error.code=workspace.parent_project_invalid`).
- **Production app unsupported** — the selected instance is served by an
  `app-prod` node (`error.code=workspace.unsupported_for_production`). The
  command fails before source-driver, registry, Agent-push, or runtime effects.
- **Concrete instance required** — a bare project selector, parent-project marker,
  or parent project path does not resolve to exactly one concrete instance
  (`error.code=validation_failed`, `error.meta.field=instance`,
  `error.meta.reason=instance_required`). No workspace row or node artifact
  is created.
- **Agent-push failure (pre-configuration)** — gateway cannot reach the node
  *before* the gateway workspace row is written
  (`error.code=workspace.node_unreachable`).
- **Workspace source failure (pre-configuration)** — the worktree source driver
  cannot create the physical source path before the gateway row is written
  (`error.code=workspace.source_create_failed`). No configuration row is
  retained.
- **Hard apply failure** — gateway workspace row was written but a
  downstream step failed in a way that cannot be retried through
  convergence (`error.code=workspace.enactment_failed`,
  `error.meta.step`/`error.meta.reason`). Retryable conditions surface as
  `success.meta.warnings[]` instead.
- **Exit status:** Uses the shared exit status policy. Success and
  success-with-warnings exit `0`; all documented command failures exit with the
  standard command failure status (`1`). This command defines no
  numeric exit codes specific to this command.

## Doctor Relationship

- **Family:** `workspace` (see [`workspace-doctor.md`](../../workspace-doctor.md)).
- **Probe:** `doctor --family=workspace --workspace=<name> --instance=<app.instance>`
  verifies registry configuration and runtime artifacts.
- **Convergence:** Workspace doctor is report-only in the current runtime;
  `doctor --family=workspace --restore` does not auto-fix workspace codes.
  Repair missing or divergent workspace runtime artifacts with
  `workspace:setup` or explicit operator work after reading probe findings.
- **Adoption:** Register an existing path with `workspace:setup --path`;
  `doctor --family=workspace --adopt` remains the disaster-recovery bulk path.
  `workspace:new` itself never adopts unmanaged paths.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceStoreControllerTest.php` | Gateway workspace creation, mandatory instance ownership, validation, authorization, duplicate-name failures, supported PHP-version handling, and documented error.code values. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceWriteCommandTest.php` | Client-side concrete instance resolution, `instance_required` validation, and gateway stream request payload. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStreamCommandTest.php` | Workspace stream consumption, terminal JSON frame handling, human progress rendering, and malformed stream failures. |

Execution-location behavior and test mapping live in:

- [`2_workspace-new_on-client.md`](2_workspace-new_on-client.md)
- [`3_workspace-new_on-gateway-node.md`](3_workspace-new_on-gateway-node.md)

Linked routine tests do not exhaustively assert every warning payload shape for `success.meta.warnings[]`; current coverage is limited to the rows above.
