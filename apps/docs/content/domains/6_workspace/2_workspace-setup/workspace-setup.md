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

# Set up or adopt a workspace by explicit identity
orbit workspace:setup feature-a --instance=my-app

# Target a concrete instance with dot notation
orbit workspace:setup recipes --instance=happie.nmbp --path=/Users/nckrtl/.codex/worktrees/a59f/happie

# Let local Codex worktree metadata resolve the workspace identity for a path
orbit workspace:setup --instance=happie.nmbp --path=/Users/nckrtl/.codex/worktrees/a59f/happie

# Adopt an existing path as a workspace
orbit workspace:setup feature-a --instance=my-app --path=/var/www/my-app/.worktrees/feature-a

# Stream setup progress as newline-delimited JSON
orbit workspace:setup feature-a --instance=my-app --stream-json

```

## Arguments and options

- `name`: The workspace identity slug. Required unless local workspace context
  or structured Codex Git-worktree metadata for an explicit `--path` can
  resolve it; can be prompted in interactive mode.
- `--instance=<project.instance>`: The parent project slug or instance selector. Use dot
  notation such as `happie.nmbp` to target the `nmbp` instance of `happie`.
  A bare project slug is shorthand only when it resolves to exactly one concrete
  instance; zero or multiple instances fail with `instance_required`.
  Defaults to the existing workspace's selected instance, the local app
  context when it resolves one instance, or an interactive prompt.
- `--path=<path>`: Absolute path to the workspace on the owning node.
  Defaults to the caller's current directory resolved to an absolute path on
  that node. The path may live outside the parent project path, including external
  agent worktrees such as Codex or Claude worktree directories. The parent project
  root itself is not a valid workspace path. A relative or non-absolute value
  fails before side effects.
- `--json`: Output JSON.
- `--stream-json`: Stream newline-delimited progress JSON. Mutually exclusive
  with `--json` and forces non-interactive mode.

## Path Awareness

`workspace:setup` resolves its target from the caller's current directory
when explicit input is not supplied. The gateway path-ownership lookup keyed
on (caller node identity, absolute CWD) returns one of:

- **A registered workspace path** — the workspace identity (`name` and
  parent `project`, required selected instance, and `path` are resolved
  from gateway configuration. The
  command proceeds as a re-converge or repair of that workspace.
- **A registered app's own root path** — the CWD is the parent project's own
  path, not a workspace path under it. The command fails before side effects
  with a hint to run
  [`workspace:new`](../1_workspace-new/workspace-new.md) instead. Use
  `workspace:setup` only when the CWD is (or will be adopted as) a workspace
  path; the app root is never itself a workspace.
- **A path inside a registered app** — the CWD is under a known app's path
  but not a registered workspace. The parent project is resolved from the lookup.
  The lookup must also resolve exactly one concrete instance or fail with
  `instance_required`. The workspace identity is filled in through local Codex
  Git-worktree metadata when available, or falls through to prompts / explicit
  input.
- **An unregistered path** — the CWD does not match any known app or
  workspace path. The command falls through to explicit input (`[name]`,
  `--instance`, `--path`), local Codex Git-worktree metadata for an absolute
  path when present, or interactive prompts.

### Local Codex worktree metadata

When `[name]` is omitted and `--path` names a Codex-managed Git worktree at
`~/.codex/worktrees/<key>/<repo>`, the local CLI reads that worktree's Git
metadata without executing a shell command. A synced
`codex-synced-branch.json` `refs/heads/<branch>` value becomes the normalized
workspace slug. Otherwise, a valid `codex-thread.json` identifies the worktree
and the CLI uses `codex-<key>`. The result is deterministic, valid, and at most
63 characters. Explicit `[name]` always wins, and paths without valid Codex
metadata require an explicit workspace name or interactive prompt.

## Behavior Summary

The following steps describe what the command does during a successful run.

- **Input Resolution**: Resolves the workspace from `[name]`, local context,
  Codex Git-worktree metadata for an explicit path, or interactive prompts.
- **Idempotent Set-Up Or Adoption**: Sets up a workspace path created by
  `workspace:new`, or adopts an unmanaged absolute path supplied explicitly.
  Adoption is based on explicit command input and, when `[name]` is omitted,
  local Codex Git-worktree metadata for an explicit `--path`.
  `workspace:setup` does not consult a external workspace adapter service and does
  not inspect project files to invent workspace identity.
- **Gateway Configuration**: Ensures the gateway workspace record exists.
- **Proxy Routing**: Ensures a workspace-owned route record exists in
  `proxy`.
- **Artifact Apply**: Applies runtime and proxy backend artifacts on the
  selected workspace node through Agent push. Every workspace inherits the
  selected instance's node, base path, document root, and domain.
- **Environment Initialization**: Preserves an existing workspace `.env`.
  When missing, initializes it from the workspace's own `.env.example` when
  present, then overlays the effective `workspace:env` values. The parent project
  `.env` is never copied.
- **Setup Steps**: Runs setup steps when configured.
- **HTTP Probe**: Performs a setup-time HTTP probe against the workspace URL.
  A failed probe is reported as a command warning, not as setup failure or a
  doctor issue code.

This command is idempotent. Re-running it on a workspace that is already
managed re-renders artifacts and verifies command-owned application. The result
reports which path the run took (`set_up`, `adopted`, or `converged`) so
operators and agents can see what changed.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target workspace or
  parent project.
- The gateway can reach the owning node through Agent push.

## Output Summary

The output format depends on whether `--json` is passed.

- **Human**: Progress showing artifact application and setup
  steps, concluding with a `result.action`-keyed success line and the
  workspace URL.
- **JSON**: A machine-readable result with the workspace's registry data,
  including a durable `workspace.adopted` flag set when the path was first
  adopted via setup. The result of the HTTP probe performed at setup time is
  included in metadata.
- **Stream JSON**: Newline-delimited progress JSON for non-interactive agents.

## Related

- [`workspace:new`](../1_workspace-new/workspace-new.md) (Companion command;
  runs the same setup behavior exposed by `workspace:setup` after creating
  the workspace path.)
- [`workspace:remove`](../5_workspace-remove/workspace-remove.md)
- [`doctor --family=workspace`](../workspace-doctor.md)

***

**Technical Contract:** [technical/1_workspace-setup.md](technical/1_workspace-setup.md)
