# `orbit instance:root [project.instance] [root]`

Change one instance's document root.

## Usage

```bash
orbit instance:root [project.instance] [root] [--json]
```

## Examples

```bash
# Set document root for one concrete instance
orbit instance:root docs.development public

# Set document root to the app root itself
orbit instance:root my-app.production .
```

## Arguments and options

- `app`: Dotted instance selector. A bare logical slug is shorthand only
  when exactly one eligible visible instance exists. Hostnames are not input.
- `root`: The new document root path, relative to the selected instance's path.
- `--json`: Output JSON.

## What Happens

Run `instance:root` when you need to change the document root and re-apply artifacts on the node.

`instance:root` updates the selected instance's driver configuration on the gateway
and then re-applies only that instance's runtime artifacts on its serving node.

1.  **Configuration Update:** Updates the gateway's recorded document root for the
    selected instance. If the supplied root equals the current configuration, the configuration
    write is a no-op (`changed: false`); application still runs.
2.  **Artifact Re-application:** Triggers the gateway to re-render and apply the
    runtime container configuration to the selected instance's serving node through
    Agent push. The runtime container restart
    required to pick up the new document root is part of this step. App-owned
    proxy route configuration remains bound to the instance, but backend proxy
    artifact convergence belongs to the `proxy` family.
3.  **No File Movement:** The command only updates the path configuration; it
    does **not** move or rename any files or directories on the application
    node.

## Idempotence

`instance:root` is convergent and idempotent. Re-running it with the same root as
gateway configuration is a documented success path and is safe to use as a "redo
apply" recovery action. The command always re-applies artifacts, so re-running
it against an instance that is already managed still refreshes its artifacts.

## Output

Use `--json` to receive structured output; omit it for a human-readable summary.

- **Human Output:** Summarizes the changed runtime configuration and the
  status of re-applied artifacts on the node.
- **JSON Output:** Returns separate canonical logical `app` and updated
  `instance` entities. Drift during a successful run is reported as app-family
  warning metadata.

## Requirements

- The selected instance must exist and be managed by Orbit.
- The caller's grant on the instance's serving node must include the `instance:root`
  permission. Denials surface as `authorization_failed`.
- The concrete application-instance node must be reachable through Agent push.

## Related Commands

Use these commands before or after `instance:root` to inspect or repair project configuration.

- [`orbit project:show [project]`](../4_project-show/project-show.md)
- [`orbit instance:register [instance]`](../2_instance-register/instance-register.md)
- [`doctor --family=instance`](../instance-doctor.md)
