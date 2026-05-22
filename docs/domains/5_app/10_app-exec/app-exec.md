# `orbit app:exec [app] -- <command...>`

[Back to App commands.](../README.md)

Run a command inside an app's FrankenPHP runtime container.

`app:exec` is the explicit execution surface for PHP, Composer, and Artisan
commands. Orbit no longer routes those tools through host PHP or host
Composer; they always run inside the selected app's runtime container.

## Usage

Place your Orbit options (including `--json`) before `--`, then list the
command tokens after the separator. Symfony stops parsing options after
`--`, so anything you put after the separator is passed to the child
command verbatim.

```bash
orbit app:exec [app] [--json] -- <command...>
```

## Examples

Run common Composer and Artisan commands against your app:

```bash
orbit app:exec docs -- composer install
orbit app:exec docs -- php artisan migrate
orbit app:exec docs --json -- php -v
```

Omit `[app]` when your working directory is inside an app source path; the
launcher passes `ORBIT_HOST_CWD` and `app:exec` resolves the app from it:

```bash
cd /home/orbit/apps/docs
orbit app:exec -- php artisan tinker
```

## Arguments and options

- `app`: app name or app hostname. Optional; auto-resolved from
  `ORBIT_HOST_CWD` when the working directory is inside a known app source
  path. Required when cwd resolution does not find an app.
- `command`: words after `--` form the command to run inside the container.
  Required. There is no default.
- `--json`: output JSON.

## What Happens

`app:exec` resolves the selected app, looks up its FrankenPHP runtime
container on the owning node, and runs `<command...>` inside that container.
The command runs as the container's default user with the container's
working directory at the mounted app source root.

Resolution order for `app`:

1. The `app` argument, when supplied. App name match wins over hostname,
   domain, or URL match.
2. `ORBIT_HOST_CWD`, when the caller's working directory matches a managed
   app source path. Workspace paths under an app do not resolve as the
   parent app — use `workspace:exec` for that case.

`app:exec` is non-interactive — it does not prompt for a missing selector.
When neither a positional `app` nor a resolvable `ORBIT_HOST_CWD` is
available the command fails with `validation_failed`.

`app:exec` does not:

- Run on host PHP or host Composer. Apps with `runtime_kind != php` do not
  have a runtime container and cannot be the target of `app:exec`.
- Restart or recreate the runtime container. The container must already be
  running. Use [`doctor --family=app`](../app-doctor.md) to repair drift.
- Bypass authorization. The caller must hold `app:exec` on the app's owning
  node.

## Output

Human mode shows the underlying command's stdout and stderr exactly as if
you had run the command yourself. JSON mode wraps the result in a single
machine-readable envelope. See the
[JSON renderer contract](technical/6.2_app-exec_output-render_json.md) for
the exact payload shape, success and error codes, and field meanings.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The app's owning node is reachable so the gateway can exec into the
  container.
- The current node identity is authorized to run `app:exec` on the app's
  owning node.

## Related Commands

Use these commands alongside `app:exec` to switch between app and workspace
runtime targets or to repair a container that is not running.

- [`workspace:exec`](../../6_workspace/14_workspace-exec/workspace-exec.md) — run
  a command inside a workspace runtime container instead of the parent app.
- [`app:show`](../4_app-show/app-show.md) — inspect the canonical app entity
  before running commands against it.
- [`doctor --family=app`](../app-doctor.md) — verify and repair the app
  runtime container when `app:exec` reports the container is not running.

## Technical Contract

See [`app:exec` technical contract](technical/1_app-exec.md).
