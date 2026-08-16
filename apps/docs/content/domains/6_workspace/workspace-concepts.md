# Workspace Concepts

This document defines workspace-family vocabulary and invariants. It supports
the workspace command contracts and the
[workspace doctor](workspace-doctor.md); it does not override the
[Architecture](../../architecture.md).

## Identity

The terms below define the core identity vocabulary for the workspace family.

- **Workspace:** A development working copy of an app whose durable record
  belongs to the gateway. It is bound to exactly one instance on an active
  `app-dev` node, with a canonical workspace name, workspace path, and one
  workspace route lifecycle.
- **Concrete instance ownership:** Every workspace row stores a non-null
  `instance_id`. A bare parent-app selector or parent-app path is only a
  shorthand resolver: it succeeds when exactly one registered instance
  matches and otherwise fails with `instance_required`. It never creates a
  parent-app-only workspace. Every workspace ProxyRoute stores both its
  `workspace_id` and the same concrete `instance_id` as the Workspace. App and
  workload-node identity are derived through that Instance. A missing,
  deleted, or mismatched Workspace/Instance owner is invalid intent.
- **Workspace identity slug:** Lowercase identity slug used as the workspace
  name. Unique within the parent app, maximum 63 characters, independent of the
  parent app slug.
- **Workspace hostname:** Workspace-owned hostname formed by prepending the
  workspace slug as its own DNS label to the parent instance's primary
  hostname. For development instances this yields
  `{workspace}.{instance-hostname}` (for example `{workspace}.{app}.{tld}`
  when the instance hostname is `{app}.{tld}`). App identity does not
  own hostname composition.
- **Workspace path:** Absolute path on the owning instance node where
  workspace files live. Derived from gateway configuration and applied through
  Agent push.
- **Workspace env:** Non-secret gateway-owned key/value intent scoped to one
  workspace. The effective workspace env merges Orbit-derived workspace URL
  and Vite values, explicit workspace values, and database connections attached
  to that workspace. Applying it writes only `<workspace path>/.env`.
- **Workspace lifecycle status:** Registry configuration lifecycle field,
  currently `expected` or `setup-pending`. It is not setup-run status and not a
  live readiness result.
- **Workspace runtime container:** Docker container derived from workspace
  configuration, parent app configuration, and the selected PHP image. It
  serves the workspace's web route through FrankenPHP. Workspace setup and
  teardown run through the selected app user's host tool path, not inside the
  container; PHP/Composer/Artisan commands include the node's versioned host PHP
  toolchain. Workspaces whose parent app is on an `app-dev` node receive the
  same dev-only packages mount as app runtimes:
  `/home/<node-user>/packages` on the owning node appears at `/packages` in the
  container.

  Production instances do not have workspace runtime containers; selecting
  one fails with `workspace.unsupported_for_production` before runtime work.

  The workspace source is mounted both at `/app` and at its original absolute
  path on the owning node. That keeps source-local absolute paths, such as
  SQLite database files, available inside the runtime container.

  Workspaces inherit configurable runtime mounts from their selected app
  instance through `instance:mount`; the workspace family does not own separate
  runtime mount intent.
  Workspace FrankenPHP XDG state is ephemeral inside the container under
  `/tmp/orbit-frankenphp`, matching app runtimes, and is not stored in the
  workspace checkout, `~/.config/orbit`, or `/var/lib/orbit`.

  The concrete workspace web runtime, managed through the process lifecycle,
  is represented as a process with Docker runtime.
- **Host cwd context:** Caller-side working-directory hint used only to resolve
  defaults such as app and workspace identity. It is not an authorization
  source and does not make the CLI operate on local artifacts directly.

## PHP Version

These terms describe how PHP version is resolved for workspaces.

- **Workspace PHP override:** Gateway-tracked PHP version stored on the
  workspace row, copied from the owning instance at creation unless an explicit
  version is supplied. When the row keeps no version of its own, the workspace
  resolves through its owning instance and then the app creation template, and
  JSON renderers report `php_inherited=true` for exactly those rows. The selected
  version chooses the workspace runtime container image; it does not install
  host PHP or render host FPM pools.
- **Workspace PHP inheritance flag:** Boolean entity field that records whether
  a workspace's effective PHP version is resolved from an owning row (`true`) or
  stored on the workspace itself (`false`). Exposed in JSON as `php_inherited`.

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
  environment. Lifecycle steps may inspect parent source through
  `ORBIT_APP_PATH`, but commands that directly read or copy
  `$ORBIT_APP_PATH/.env` are rejected because parent env values are not
  workspace configuration. Upgrade migration removes existing unsafe rows, and
  teardown skips any unsafe row that bypassed normal writes.

## Lifecycle

The terms below describe how a workspace moves through its lifecycle states.

- **Workspace adoption:** Result of `workspace:setup` against an existing path
  with no Orbit management. The resulting workspace entity reports `adopted=true`.
- **Workspace adoption flag:** Boolean entity field that records whether a
  workspace was adopted from an existing path (`true`) or created fresh
  (`false`). Exposed in JSON as `adopted`.
- **Workspace history:** Durable record of workspace setup and teardown step
  runs, read through `workspace:history` and `workspace:run:log`. It is not
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
  configuration, app runtime mount intent, or node-level firewall policy.
  Workspace commands do not install host PHP, Composer, or Caddy.
  Workspace setup initializes a missing workspace `.env` from that workspace's
  own `.env.example`, then overlays the effective workspace env. It preserves
  an existing workspace `.env` and never seeds from the parent app `.env`.
