# `orbit workspace:setup [name]`

[Back to Workspaces commands.](../README.md)

Set up, adopt, or re-converge a workspace from explicit input.

`workspace:setup` orchestrates workspace management by resolving workspace input
and enacting necessary infrastructure. It ensures gateway intent exists,
configures proxy routes, enacts runtime artifacts on the owning app node, and
runs configured setup steps.

## Usage

```bash
# Set up the current directory as a workspace for an app
orbit workspace:setup feature-a --app=my-app

# Adopt an existing path as a workspace
orbit workspace:setup feature-a --app=my-app --path=/var/www/my-app/.worktrees/feature-a

# Re-converge an already-managed workspace (idempotent no-op refresh)
orbit workspace:setup feature-a
```

## Arguments And Options

- `name`: The workspace identity slug. Required unless local workspace context
  can resolve it; can be prompted in interactive mode.
- `--app=<app>`: The parent app slug. Defaults to the existing workspace's
  parent app, the local app context, or an interactive prompt.
- `--path=<path>`: Absolute path to the workspace on the owning app node.
  Defaults to the caller's current directory resolved to an absolute path on
  that node. Generic worktree paths must live under the parent app's
  `.worktrees/` directory. A relative or non-absolute value fails before side
  effects.
- `--json`: Output JSON.

## Behavior Summary

- **Input Resolution**: Resolves the workspace from `[name]`, local context,
  or interactive prompts.
- **Idempotent Set-Up Or Adoption**: Sets up a workspace path created by
  `workspace:new`, or adopts an unmanaged path that already satisfies the
  parent app's workspace policy. Adoption is based on the explicit `[name]`,
  `--app`, and `--path` inputs; `workspace:setup` does not inspect project
  files to discover workspace identity.
- **Gateway Intent**: Ensures the gateway workspace record exists.
- **Proxy Routing**: Ensures a workspace-owned route record exists in
  `proxy`.
- **Artifact Enactment**: Enacts runtime and proxy backend artifacts on the
  app node via SSH.
- **Setup Steps**: Runs setup steps when configured.
- **HTTP Probe**: Performs a setup-time HTTP probe against the workspace URL.
  A failed probe is reported as a command warning, not as setup failure or a
  doctor issue code.

This command is idempotent. Re-running on an already-managed workspace
re-renders artifacts and verifies command-owned enactment. The result reports
which path the run took (`set_up`, `adopted`, or `converged`) so operators
and agents can see what changed.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target workspace or
  parent app.
- The gateway can reach the owning app node over SSH.

## Output Summary

- **Human**: A step tree showing progress of artifact enactment and setup
  steps, concluding with a `result.action`-keyed success line and the
  workspace URL.
- **JSON**: A `success` envelope with `success.data.result.action`
  (`set_up`/`adopted`/`converged`) and the workspace's registry data
  including a durable `workspace.adopted` flag set when the path was first
  adopted via setup. The setup-time HTTP probe result is reported under
  `success.meta.http_probe`.

## Related

- [`workspace:new`](../1_workspace-new/workspace-new.md) (Companion command;
  runs the same setup behavior exposed by `workspace:setup` after creating
  the workspace path.)
- [`workspace:remove`](../5_workspace-remove/workspace-remove.md)
- [`doctor --family=workspace`](../workspace-doctor.md)

***

**Technical Contract:** [technical/1_workspace-setup.md](technical/1_workspace-setup.md)
