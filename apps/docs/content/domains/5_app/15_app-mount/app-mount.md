# `orbit app:mount list|add|remove [app]`

[Back to App commands.](../README.md)

List or change additional Docker runtime mounts for an app instance.

Configurable runtime mounts belong to app instances, not logical apps. A
configured mount is rendered into the PHP app runtime container for the
selected instance and inherited by workspace runtime containers that use that
instance. Different instances of the same app may use different host source
paths — for example, `hauser.development` on Linux may mount
`/home/nckrtl/projects` while `hauser.nmbp` on macOS mounts
`/Users/nckrtl/projects` at the same container target.

Every action requires a dotted instance selector such as `hauser.nmbp`.

## Usage

```bash
orbit app:mount list <app>.<instance> [--json]
orbit app:mount add <app>.<instance> <source> <target> [--read-only|--read-write] [--json]
orbit app:mount remove <app>.<instance> <target> [--json]
```

## Examples

```bash
orbit app:mount list hauser.development
orbit app:mount add hauser.development /home/nckrtl/projects /projects
orbit app:mount add hauser.nmbp /Users/nckrtl/projects /projects
orbit app:mount remove hauser.nmbp /projects --json
```

## Arguments and options

- `action`: one of `list`, `add`, `remove`. Required.
- `app`: dotted instance selector such as `hauser.nmbp`. Required for every
  action and must resolve the named instance under the app.
- `source`: host source path. Required for `add`. Must live under the resolved
  instance node's home directory.
- `target`: container target path. Required for `add` and `remove`.
- `--read-only`: mount read-only. This is the default for `add`.
- `--read-write`: mount read-write.
- `--json`: Output JSON.

## What Happens

Run `app:mount` to manage instance-scoped additional runtime mount intent.

`app:mount list` reads the configured mounts for the resolved target without
changing gateway state.

`app:mount add` requires an app instance selector, validates the source and
target against the selected instance's node and home boundary, then creates or
updates the mount for the target path.
Adding the same target again updates the stored source or read/write mode.

`app:mount remove` requires an app instance selector and deletes the configured
mount for the target path on the resolved instance.

Configured mounts are rendered after Orbit's built-in runtime mounts. App and
workspace runtime containers use the selected instance's mount rows. The runtime spec hash changes when mount
configuration changes, so the runtime manager can recreate the affected app or
workspace container during normal runtime convergence. The command does not
restart containers directly.

`app:mount` does not:

- Switch the app runtime to PHP-FPM. PHP apps still use FrankenPHP runtime
  containers.
- Mount the owning user's whole home directory by default.
- Replace the built-in app-dev `/packages` mount.
- Configure static apps or `app-prod` apps in the current slice.
- Grant filesystem permissions beyond creating safe bind-mount source
  directories on the owning node before Docker starts the runtime container.

## Safety Rules

Mounts are intentionally constrained because they cross the host/container
boundary.

- Only PHP apps on `app-dev` nodes can store configurable runtime mounts in
  the current slice.
- `source` must be an absolute path under the resolved instance node's home
  directory, such as `/home/<node-user>/` on Linux or `/Users/<node-user>/` on
  macOS.
- The home root itself and sensitive home subtrees such as `.ssh`, `.gnupg`,
  `.aws`, `.config`, `.netrc`, `.npmrc`, and Composer auth material are
  rejected.
- `target` must be absolute and must not collide with Orbit-reserved runtime
  targets: `/app`, `/packages`, `/data`, `/config`, the managed PHP ini target,
  the app source mirror target, or the internal ephemeral FrankenPHP XDG root
  `/tmp/orbit-frankenphp`.
- Mounts default to read-only; use `--read-write` only when the container must
  write to the mounted path.

## Output

You see the app, `app_instance` mount target, configured mounts, and
whether they are inherited by workspaces that use the selected instance.

Use `--json` when another tool needs the machine-readable envelope. The exact
payload shape is documented in the
[JSON renderer contract](technical/6.2_app-mount_output-render_json.md).

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `app:read` on the app's owning node for `list`.
- The caller has `app:mount` on the app's owning node for `add` and `remove`.
- Every action uses a dotted app instance selector such as `hauser.nmbp`.

## Related Commands

Use these commands when you need to inspect the app, manage related runtime
settings, or repair runtime drift.

- [`app:show`](../4_app-show/app-show.md) - inspect the canonical app entity.
- [`app:instance`](../19_app-instance/app-instance.md) - inspect app instances.
- [`app:worker`](../11_app-worker/app-worker.md) - manage FrankenPHP worker mode.
- [`doctor --family=app`](../app-doctor.md) - verify and repair app runtime drift.

## Technical Contract

See [`app:mount` technical contract](technical/1_app-mount.md).
