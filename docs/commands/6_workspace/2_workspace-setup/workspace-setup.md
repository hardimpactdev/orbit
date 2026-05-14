# `orbit workspace:setup [name]`

[Back to Workspaces commands.](../README.md)

Set up, adopt, or re-converge a workspace from explicit input.

`workspace:setup` orchestrates workspace management by resolving workspace
input and applying necessary infrastructure. It ensures gateway configuration
exists, configures proxy routes, applies runtime artifacts on the owning app
node, and runs configured setup steps.

## Usage

```bash
# Re-converge an already-managed workspace (identity resolved from CWD)
cd /var/www/my-app/.worktrees/feature-a
orbit workspace:setup

# Register and set up an adapter-managed worktree (e.g. a Polyscope worktree
# Orbit does not yet know about): identity discovered from the adapter
cd /var/www/my-app/.worktrees/feature-a
orbit workspace:setup

# Set up or adopt a workspace by explicit identity
orbit workspace:setup feature-a --app=my-app

# Adopt an existing path as a workspace
orbit workspace:setup feature-a --app=my-app --path=/var/www/my-app/.worktrees/feature-a
```

## Arguments and options

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

## Path Awareness

`workspace:setup` resolves its target from the caller's current directory
when explicit input is not supplied. The gateway path-ownership lookup keyed
on (caller node identity, absolute CWD) returns one of:

- **A registered workspace path** — the workspace identity (`name` and
  parent `app`) and `path` are resolved from gateway configuration. The
  command proceeds as a re-converge or repair of that workspace.
- **A registered app's own root path** — the CWD is the parent app's own
  path, not a workspace path under it. The command fails before side effects
  with `error.code=workspace.path_is_app_root` and a hint to run
  [`workspace:new`](../1_workspace-new/workspace-new.md) instead. Use
  `workspace:setup` only when the CWD is (or will be adopted as) a workspace
  path; the app root is never itself a workspace.
- **A path inside a registered app** — the CWD is under a known app's path
  but not a registered workspace. The parent app is resolved from the lookup.
  The workspace identity is filled in through the adapter probe described in
  the next subsection, or falls through to prompts.
- **An unregistered path** — the CWD does not match any known app or
  workspace path. The agent-IDE adapter probe runs across the caller's
  configured adapters; on a single match, identity is resolved from the
  adapter. Otherwise the command falls through to explicit input (`[name]`,
  `--app`, `--path`) or interactive prompts.

### Agent-IDE adapter probe

`workspace:setup` registers adapter-managed worktrees on first run. Some
adapters expose a `workspace_path_resolution` capability. Polyscope is one.
An adapter with this capability answers a single question: which managed
workspace does this absolute path belong to?

Orbit consults the probe when `[name]` and `--app` are both missing and the
gateway lookup returned `inside_app` or `unregistered`. A successful probe
fills in the workspace name and parent app. The adapter also returns its
own id for the workspace, which Orbit stores. Adoption then proceeds as
usual (`result.action=adopted`).

See
[`agent-ide-concepts.md`](../../15_agent-ide/agent-ide-concepts.md#adapter-model)
for the capability definition.

Probe outcomes:

- **One adapter resolves the path** — adoption proceeds using the adapter's
  answer. `--app` and `[name]`, if also supplied, must agree with the
  adapter or the command fails with `validation_failed`.
- **No adapter resolves the path** — fall through to prompts (interactive)
  or `validation_failed` (non-interactive).
- **Multiple adapters claim the path** — fails with `validation_failed`
  asking the operator to disambiguate with `--app`.

## Behavior Summary

The following steps describe what the command does during a successful run.

- **Input Resolution**: Resolves the workspace from `[name]`, local context,
  or interactive prompts.
- **Idempotent Set-Up Or Adoption**: Sets up a workspace path created by
  `workspace:new`, or adopts an unmanaged path that already satisfies the
  parent app's workspace policy. Adoption is based on the explicit `[name]`,
  `--app`, and `--path` inputs; `workspace:setup` does not inspect project
  files to discover workspace identity.
- **Gateway Configuration**: Ensures the gateway workspace record exists.
- **Proxy Routing**: Ensures a workspace-owned route record exists in
  `proxy`.
- **Artifact Apply**: Applies runtime and proxy backend artifacts on the
  app node via SSH.
- **Setup Steps**: Runs setup steps when configured.
- **HTTP Probe**: Performs a setup-time HTTP probe against the workspace URL.
  A failed probe is reported as a command warning, not as setup failure or a
  doctor issue code.

This command is idempotent. Re-running on an already-managed workspace
re-renders artifacts and verifies command-owned application. The result
reports which path the run took (`set_up`, `adopted`, or `converged`) so
operators and agents can see what changed.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target workspace or
  parent app.
- The gateway can reach the owning app node over SSH.

## Output Summary

The output format depends on whether `--json` is passed.

- **Human**: A step tree showing progress of artifact application and setup
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
