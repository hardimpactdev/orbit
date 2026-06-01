# `orbit workspace:exec [workspace] -- <command...>`

[Back to Workspace commands.](../README.md)

Run a command inside a workspace's FrankenPHP runtime container.

`workspace:exec` is the explicit execution surface for PHP, Composer, and
Artisan commands inside a workspace. Orbit no longer routes those tools
through host PHP or host Composer; they always run inside the selected
workspace's runtime container.

## Usage

Place your Orbit options (including `--app` and `--json`) before `--`,
then list the command tokens after the separator. Symfony stops parsing
options after `--`, so anything you put after the separator is passed to
the child command verbatim.

```bash
orbit workspace:exec [workspace] [--app=<slug>] [--json] -- <command...>
```

## Examples

Run common Composer and Artisan commands against your workspace:

```bash
orbit workspace:exec docs-feature -- composer install
orbit workspace:exec docs-feature -- php artisan test
orbit workspace:exec docs-feature --json -- php artisan tinker
```

Omit `[workspace]` when your working directory is inside a workspace source
path; the source CLI entrypoint initializes `ORBIT_HOST_CWD` when absent and
`workspace:exec` resolves the workspace from it:

```bash
cd /home/orbit/apps/docs/.worktrees/docs-feature
orbit workspace:exec -- php artisan test
```

## Arguments and options

- `workspace`: workspace name. Optional; auto-resolved from
  `ORBIT_HOST_CWD` when the working directory is inside a known workspace
  source path. Required when cwd resolution does not find a workspace.
- `command`: words after `--` form the command to run inside the
  container. Required. There is no default.
- `--app=<slug>`: parent app slug to disambiguate workspace names that
  collide across apps. Required when the workspace name is shared across
  apps.
- `--json`: output JSON.

## What Happens

`workspace:exec` resolves the selected workspace, looks up its FrankenPHP
runtime container on the owning node, and runs `<command...>` inside that
container. The command runs as the container's default user with the
container's working directory at the mounted workspace source root.

Resolution order for `workspace`:

1. The `workspace` argument, when supplied. When the same workspace name
   exists in multiple apps, `--app=<slug>` is required to pick one; an
   ambiguous name without `--app` fails with `workspace.ambiguous_name`.
2. `ORBIT_HOST_CWD`, when the caller's working directory matches a managed
   workspace source path. Workspace match wins over parent-app match: a
   working directory under `apps/docs/.worktrees/docs-feature` resolves to
   workspace `docs-feature`, not the parent app `docs`.

`workspace:exec` is non-interactive — it does not prompt for a missing
selector. When neither a positional `workspace` nor a resolvable
`ORBIT_HOST_CWD` is available the command fails with
`validation_failed`.

`workspace:exec` does not:

- Run on host PHP or host Composer. Workspaces whose parent app has
  `runtime_kind != php` do not have a runtime container and cannot be the
  target of `workspace:exec`.
- Restart or recreate the runtime container. The container must already be
  running. Use [`doctor --family=workspace`](../workspace-doctor.md) to
  repair drift.
- Bypass authorization. The caller must hold `workspace:exec` on the
  workspace's owning node.

## Output

Human mode shows the underlying command's stdout and stderr exactly as if
you had run the command yourself. JSON mode wraps the result in a single
machine-readable envelope. See the
[JSON renderer contract](technical/6.2_workspace-exec_output-render_json.md)
for the exact payload shape, success and error codes, and field meanings.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The workspace's owning node is reachable so the gateway can exec into the
  container.
- The current node identity is authorized to run `workspace:exec` on the
  workspace's owning node.

## Related Commands

Use these commands alongside `workspace:exec` to switch between app and
workspace runtime targets or to repair a container that is not running.

- [`app:exec`](../../5_app/10_app-exec/app-exec.md) — run a command inside
  the parent app's runtime container instead of a workspace.
- [`workspace:show`](../4_workspace-show/workspace-show.md) — inspect the
  canonical workspace entity before running commands against it.
- [`doctor --family=workspace`](../workspace-doctor.md) — verify and repair
  the workspace runtime container when `workspace:exec` reports the
  container is not running.

## Technical Contract

See [`workspace:exec` technical contract](technical/1_workspace-exec.md).
