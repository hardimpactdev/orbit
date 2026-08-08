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

# Bare parent-app shorthand; succeeds only when my-app has one instance
orbit workspace:new feature-a --instance=my-app

# Explicit name on one concrete instance
orbit workspace:new recipes --instance=happie.nmbp

# Branched from a non-default source ref on one concrete instance
orbit workspace:new bugfix-1 --instance=my-app.development --base=production

# Stream progress as newline-delimited JSON
orbit workspace:new feature-a --instance=my-app.development --stream-json
```

## Arguments and options

- `name`: workspace slug; lowercase letters, digits, and hyphens only, up to
  63 characters. The reserved name `main` is rejected. Must be unique within
  the parent app. Prompted in interactive mode when omitted.
- `--instance=<app.instance>`: instance selector or bare parent-app shorthand.
  - Dot notation such as `happie.nmbp` selects one concrete instance
    directly.
  - A bare app slug, parent-app marker, or parent app path succeeds only when it
    resolves uniquely to one registered instance.
  - When omitted, Orbit infers an instance from an `.orbit/config` marker or a
    gateway path-ownership lookup against registered paths.
  - Ambiguous or missing concrete ownership fails with the
    `instance_required` validation reason. Interactive mode prompts for an
    instance only when neither a selector nor usable path context was supplied.
    See the
    [JSON renderer contract](technical/6.2_workspace-new_output-render_json.md)
    for structured failure metadata.
- `--base=<ref>`: source git ref used to create the worktree. Defaults to
  `main` (not prompted).
- `--php-version=<version>`: workspace PHP version override. When omitted, the
  workspace copies its owning instance's PHP version at creation.
- `--json`: output structured JSON (forces non-interactive mode).
- `--stream-json`: stream newline-delimited progress JSON. Mutually exclusive
  with `--json` and forces non-interactive mode.

## Path Awareness

`workspace:new` resolves one concrete instance from the caller's current
directory when `--instance` is not supplied. The gateway path-ownership lookup keyed
on (caller node identity, absolute CWD) accepts registered instance and
workspace paths directly. An instance's main path or parent-app marker is only
shorthand: it must map to exactly one registered instance. Zero or multiple
matches fail with `validation_failed` and
the `instance_required` reason. Orbit never falls back to an app-level
node or creates a parent-app-only workspace. See the
[JSON renderer contract](technical/6.2_workspace-new_output-render_json.md)
for the exact envelope.

Project files (`composer.json`, `package.json`, `.php-version`) are not
inspected to infer the parent app. Path inference is gateway-authoritative.

## Behavior Summary

The following steps describe what the command does during a successful run.

- **Gateway Configuration**: Creates initial workspace configuration on the
  gateway with a non-null `instance_id`.
- **Workspace Source**: Creates a new Git worktree for the selected concrete
  instance on its owning node through the worktree source driver.
- **Setup Pipeline**: Runs the same setup behavior exposed by
  [`workspace:setup`](../2_workspace-setup/workspace-setup.md). The pipeline
  creates workspace-owned proxy routes, renders workspace runtime container artifacts,
  converges inherited process artifacts, executes configured workspace setup
  steps, and performs the setup-time HTTP probe.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to run `workspace:new` on the selected
  instance's owning node.
- The gateway can reach the effective workspace node through Agent push.

## Output Summary

The output format depends on whether `--json` is passed.

- **Human**: Progress covering worktree creation, runtime artifacts, proxy
  route registration, and setup step execution.
- **JSON**: A machine-readable result including the new workspace record.
- **Stream JSON**: Newline-delimited progress JSON for non-interactive agents.

## Related

- [`workspace:setup`](../2_workspace-setup/workspace-setup.md): Re-converge or
  repair an existing workspace.
- [`workspace:remove`](../5_workspace-remove/workspace-remove.md): Delete a
  workspace and its artifacts.
- [`doctor --family=workspace`](../workspace-doctor.md): Verify workspace
  reality.

***

**Technical Contract:** [technical/1_workspace-new.md](technical/1_workspace-new.md)
