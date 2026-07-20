# `orbit instance:mount list|add|remove [instance]`

[Back to Project and instance commands.](../README.md)

List or change additional Docker runtime mounts for an instance.

Configurable runtime mounts belong to instances, not projects. A
configured mount is rendered into the PHP app runtime container for the
selected instance and inherited by workspace runtime containers that use that
instance. Different instances of the same project may use different host source
paths — for example, `hauser.development` on Linux may mount
`/home/nckrtl/projects` while `hauser.nmbp` on macOS mounts
`/Users/nckrtl/projects` at the same container target.

Every action requires a dotted instance selector such as `hauser.nmbp`.

## Usage

```bash
orbit instance:mount list <project>.<instance> [--json]
orbit instance:mount add <project>.<instance> <source> <target> [--read-only|--read-write] [--json]
orbit instance:mount remove <project>.<instance> <target> [--json]
```

## Examples

```bash
orbit instance:mount list hauser.development
orbit instance:mount add hauser.development /home/nckrtl/projects /projects
orbit instance:mount add hauser.nmbp /Users/nckrtl/projects /projects
orbit instance:mount remove hauser.nmbp /projects --json
```

## Arguments and options

- `action`: one of `list`, `add`, `remove`. Required.
- `instance`: dotted instance selector such as `hauser.nmbp`. Required for every
  action and must resolve the named instance under the app.
- `source`: host source path. Required for `add`. Must live under the resolved
  instance node's home directory.
- `target`: container target path. Required for `add` and `remove`.
- `--read-only`: mount read-only. This is the default for `add`.
- `--read-write`: mount read-write.
- `--json`: Output JSON.

## What Happens

Run `instance:mount` to manage instance-scoped additional runtime mount intent.

`instance:mount list` reads the configured mounts for the selected instance without
changing gateway state.

`instance:mount add` requires a dotted instance selector, authorizes its serving
node, validates the source and target against that node and home boundary, then creates or
updates the mount for the target path.
Adding the same target again updates the stored source or read/write mode.

`instance:mount remove` requires a dotted instance selector, authorizes its
serving node, and deletes the configured mount for that instance and target.

Configured mounts are rendered after Orbit's built-in runtime mounts. Instance and
workspace runtime containers use the selected instance's mount rows. The runtime spec hash changes when mount
configuration changes, so the runtime manager can recreate the affected app or
workspace container during normal runtime convergence. The command does not
restart containers directly.

`instance:mount` does not:

- Switch the app runtime to PHP-FPM. PHP apps still use FrankenPHP runtime
  containers.
- Mount the owning user's whole home directory by default.
- Replace the built-in app-dev `/packages` mount.
- Configure static apps or `app-prod` apps in the current slice.
- Grant filesystem permissions beyond creating safe bind-mount source
  directories on the selected instance's serving node before Docker starts the runtime container.

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

You see the logical `project` entity and concrete `instance` entity separately,
followed by configured mounts and whether they are inherited by workspaces
that use the selected instance. Node, URL, path, root, domain, and `adopted`
appear only on `instance`, never on the project.

Use `--json` when another tool needs the machine-readable envelope. The exact
payload shape is documented in the
[JSON renderer contract](technical/6.2_instance-mount_output-render_json.md).

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `instance:read` on the selected instance's serving node for `list`.
- The caller has `instance:mount` on the selected instance's serving node for `add`
  and `remove`.
- Every action uses a dotted instance selector such as `hauser.nmbp`.

## Related Commands

Use these commands when you need to inspect the app, manage related runtime
settings, or repair runtime drift.

- [`project:show`](../4_project-show/project-show.md) - inspect the canonical project entity.
- [`instance:show`](../26_instance-show/instance-show.md) - inspect an instance.
- [`instance:worker`](../11_instance-worker/instance-worker.md) - manage FrankenPHP worker mode.
- [`doctor --family=instance`](../instance-doctor.md) - verify and repair app runtime drift.

## Technical Contract

See [`instance:mount` technical contract](technical/1_instance-mount.md).
