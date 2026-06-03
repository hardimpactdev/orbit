# Workspace Concepts

This document defines workspace-family vocabulary and invariants. It supports
the workspace command contracts and the
[workspace doctor](workspace-doctor.md); it does not override the
[Architecture](../../architecture.md).

## Identity

The terms below define the core identity vocabulary for the workspace family.

- **Workspace:** Gateway-owned working copy of an app, bound to one parent app
  on the app's owning node, with a canonical workspace name, workspace path,
  and one workspace route lifecycle.
- **Workspace identity slug:** Lowercase identity slug used as the workspace
  name. Unique within the parent app, maximum 63 characters, independent of the
  parent app slug.
- **Workspace hostname:** Hostname formed by prepending the workspace slug as
  its own DNS label to the parent app's primary hostname. For development apps
  this yields `{workspace}.{app}.{tld}`.
- **Workspace path:** Absolute path on the owning node where workspace
  files live. Derived from gateway configuration and applied over SSH.
- **Workspace lifecycle status:** Registry configuration lifecycle field,
  currently `expected` or `setup-pending`. It is not setup-run status and not a
  live readiness result.
- **Workspace runtime container:** Docker container derived from workspace
  configuration, parent app configuration, and the selected PHP image. It
  serves the workspace's web route through FrankenPHP. Ad-hoc workspace
  PHP/Composer/Artisan run on the node's host PHP toolchain, not inside the
  container. The lifecycle-managed concrete workspace web runtime is
  represented as a process with Docker runtime.
- **Host cwd context:** Caller-side working-directory hint used only to resolve
  defaults such as app and workspace identity. It is not an authorization
  source and does not make the CLI operate on local artifacts directly.

## PHP Version

These terms describe how PHP version is resolved for workspaces.

- **Workspace PHP override:** Optional gateway-tracked PHP version stored on the
  workspace row. When absent, the workspace inherits the parent app PHP version
  and JSON renderers report `php_inherited=true`. The selected version chooses
  the workspace runtime container image; it does not install host PHP or render
  host FPM pools.
- **Workspace PHP inheritance flag:** Boolean entity field that records whether
  a workspace's effective PHP version comes from the parent app (`true`) or
  from its own override (`false`). Exposed in JSON as `php_inherited`.
- **Workspace agent IDE adapter:** Effective agent IDE adapter for a workspace,
  resolved per session through the chain `app override → owning node default →
  none`. Exposed in JSON as `agent_ide.adapter`. Reserved for the future
  workspace-level override.
- **Workspace agent IDE identifier:** Adapter-specific identifier the agent IDE
  uses to reference a workspace, such as the OpenCode project id. Exposed in
  JSON as `agent_ide.workspace_id`. `null` when no agent IDE is in effect or
  the adapter does not expose a workspace identifier.

## Setup and teardown

The terms below describe setup and teardown step vocabulary.

- **Setup step definition:** A workspace policy record owned by the gateway, created by
  `workspace-setup-step:add` and ordered by setup-step commands.
- **Setup steps phase:** The `workspace:new` and `workspace:setup` execution
  phase that runs setup step definitions sequentially after core workspace
  artifacts are prepared. Failures during this phase use `phase=setup_steps` in
  JSON output.
- **Teardown step definition:** A workspace policy record owned by the gateway, created
  by `workspace-teardown-step:add` and run during workspace removal.
- **Lifecycle step environment:** `ORBIT_*` and `VITE_*` variables exposed to
  setup and teardown step commands. Separate from process runtime unit
  environment.

## Lifecycle

The terms below describe how a workspace moves through its active states.

- **Workspace adoption:** Result of `workspace:setup` against an existing path
  that was not previously Orbit-managed. The resulting workspace entity reports
  `adopted=true`.
- **Workspace adoption flag:** Boolean entity field that records whether a
  workspace was adopted from an existing path (`true`) or created fresh
  (`false`). Exposed in JSON as `adopted`.
- **Workspace history:** Durable record of workspace setup and teardown step
  runs, read through `workspace:history` and `workspace:log`. It is not
  workspace-unit configuration.

## Boundaries

These boundaries define what the workspace family owns and what belongs to other families.

- **Workspace-owned route:** Proxy route whose lifecycle is owned by the
  workspace and surfaced as inventory by the `proxy` family.
- **Workspace-family boundaries:** Workspace commands own workspace identity,
  setup and teardown policy, workspace PHP override, workspace history, and
  workspace-derived hostname configuration. Workspace web runtimes and
  long-running development commands are represented as processes scoped to the
  workspace. The workspace family owns branch/path/setup state; the process
  family owns start/stop/restart/log lifecycle for the long-running units. They
  do not own proxy route convergence, process-unit convergence, app
  configuration, or node-level firewall policy. Workspace commands do not
  install host PHP, Composer, or Caddy.
