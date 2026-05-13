# Workspace Concepts

This document defines workspace-family vocabulary and invariants. It supports
the workspace command contracts and the
[workspace doctor](workspace-doctor.md); it does not override the
[Architecture](../../ARCHITECTURE.md).

## Identity

- **Workspace:** Gateway-owned working copy of an app, bound to one parent app
  on the app's owning node, with a canonical workspace name, workspace path,
  and one workspace route lifecycle.
- **Workspace identity slug:** Lowercase identity slug used as the workspace
  name. Unique within the parent app, maximum 63 characters, independent of the
  parent app slug.
- **Workspace hostname:** Hostname formed by prepending the workspace slug as
  its own DNS label to the parent app's primary hostname. For development apps
  this yields `{workspace}.{app}.{tld}`.
- **Workspace path:** Absolute path on the owning app node where workspace
  files live. Derived from gateway intent and enacted over SSH.
- **Workspace lifecycle status:** Registry intent lifecycle field, currently
  `expected` or `setup-pending`. It is not setup-run status and not a live
  readiness result.

## PHP Version

- **Workspace PHP override:** Optional gateway-tracked PHP version stored on the
  workspace row. When absent, the workspace inherits the parent app PHP version
  and JSON renderers report `php_inherited=true`.

## Setup And Teardown

- **Setup step definition:** Gateway-owned workspace policy record created by
  `workspace-setup-step:add` and ordered by setup-step commands.
- **Setup steps phase:** The `workspace:new` and `workspace:setup` execution
  phase that runs setup step definitions sequentially after core workspace
  artifacts are prepared. Failures during this phase use `phase=setup_steps` in
  JSON output.
- **Teardown step definition:** Gateway-owned workspace policy record created
  by `workspace-teardown-step:add` that runs during workspace removal.
- **Lifecycle step environment:** `ORBIT_*` and `VITE_*` variables exposed to
  setup and teardown step commands. Separate from process runtime unit
  environment.

## Lifecycle

- **Workspace adoption:** Result of `workspace:setup` against an existing path
  that was not previously Orbit-managed. The resulting workspace entity reports
  `adopted=true`.
- **Workspace history:** Durable record of workspace setup and teardown step
  runs, read through `workspace:history` and `workspace:log`. It is not
  workspace-unit intent.

## Boundaries

- **Workspace-owned route:** Proxy route whose lifecycle is owned by the
  workspace and surfaced as inventory by the `proxy` family.
- **Workspace-family boundaries:** Workspace commands own workspace identity,
  setup and teardown policy, workspace PHP override, workspace history, and
  workspace-derived hostname intent. They do not own proxy route convergence,
  inherited process-unit convergence, app intent, or node-level firewall
  policy.
