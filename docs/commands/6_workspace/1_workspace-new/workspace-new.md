# `orbit workspace:new [name]`

[Back to Workspaces commands.](../README.md)

Create a new workspace for an app.

`workspace:new` writes initial gateway workspace configuration for an
application, creates the workspace source through the effective workspace
source driver, and then runs the workspace setup pipeline. Use it to organize
development environments and to establish a scope for shared resources and
configurations.

## Usage

```bash
# From inside an app path, with no arguments: prompts for workspace name
cd /var/www/my-app
orbit workspace:new

# Explicit name on the app my-app
orbit workspace:new feature-a --app=my-app

# Branched from a non-default source ref
orbit workspace:new bugfix-1 --app=my-app --base=production
```

## Arguments and options

- `name`: workspace slug; lowercase letters, digits, and hyphens only, up to
  63 characters. The reserved name `main` is rejected. Must be unique within
  the parent app. Prompted in interactive mode when omitted.
- `--app=<app>`: parent app. When omitted, Orbit infers the parent app from
  gateway-authoritative metadata: an `.orbit/config` marker on the caller
  filesystem, or a gateway path-ownership lookup that matches the current
  working directory against registered app and workspace paths. Prompted
  interactively when neither resolves.
- `--base=<ref>`: source git ref used to create the worktree. Defaults to
  `main` (not prompted).
- `--php-version=<version>`: workspace PHP version override. When omitted, the
  workspace inherits the parent app's PHP version.
- `--json`: output structured JSON (forces non-interactive mode).

## Path Awareness

`workspace:new` resolves the parent app from the caller's current directory
when `--app` is not supplied. The gateway path-ownership lookup keyed on
(caller node identity, absolute CWD) accepts both an app's main path and any
of its registered workspace paths and returns the parent app slug. From any
path under a registered app, running `orbit workspace:new` with no
arguments is enough to start the create flow; the workspace name is the only
required field and is prompted in interactive mode.

Project files (`composer.json`, `package.json`, `.php-version`) are not
inspected to infer the parent app. Path inference is gateway-authoritative.

## Behavior Summary

The following steps describe what the command does during a successful run.

- **Gateway Configuration**: Creates initial workspace configuration on the
  gateway.
- **Workspace Source**: Creates a new workspace source for the parent app on
  the owning app node. Generic and OpenCode-backed sources use git worktrees;
  Polyscope-backed sources are provisioned through the Polyscope SDK.
- **Setup Pipeline**: Runs the same setup behavior exposed by
  [`workspace:setup`](../2_workspace-setup/workspace-setup.md). The pipeline
  creates workspace-owned proxy routes, renders workspace PHP-FPM artifacts,
  converges inherited process artifacts, executes configured workspace setup
  steps, and performs the setup-time HTTP probe.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target app.
- The gateway can reach the owning app node over SSH.

## Output Summary

The output format depends on whether `--json` is passed.

- **Human**: Progress covering worktree creation, runtime artifacts, proxy
  route registration, and setup step execution.
- **JSON**: A machine-readable result including the new workspace record.

## Related

- [`workspace:setup`](../2_workspace-setup/workspace-setup.md): Re-converge or
  repair an existing workspace.
- [`workspace:remove`](../5_workspace-remove/workspace-remove.md): Delete a
  workspace and its artifacts.
- [`doctor --family=workspace`](../workspace-doctor.md): Verify workspace
  reality.

***

**Technical Contract:** [technical/1_workspace-new.md](technical/1_workspace-new.md)
