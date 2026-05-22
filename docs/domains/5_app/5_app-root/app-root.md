# `orbit app:root [app] [root]`

Change the app document root.

## Usage

```bash
orbit app:root [app] [root] [--json]
```

## Examples

```bash
# Set document root to 'public' for the 'docs' app
orbit app:root docs public

# Set document root to the app root itself
orbit app:root my-app .
```

## Arguments and options

- `app`: The name or hostname of the application to update.
- `root`: The new document root path, relative to the application's base path.
- `--json`: Output JSON.

## What Happens

Run `app:root` when you need to change the document root and re-apply artifacts on the node.

`app:root` updates the application's document root configuration on the gateway
and then re-applies the necessary runtime artifacts on the application node.

1.  **Configuration Update:** Updates the gateway's recorded document root for the
    application. If the supplied root equals the current configuration, the configuration
    write is a no-op (`changed: false`); application still runs.
2.  **Artifact Re-application:** Triggers the gateway to re-render and upload the
    runtime container configuration to the node over SSH. The runtime container restart
    required to pick up the new document root is part of this step. App-owned
    proxy route configuration continues to belong to the app, but backend proxy
    artifact convergence belongs to the `proxy` family.
3.  **No File Movement:** The command only updates the path configuration; it
    does **not** move or rename any files or directories on the application
    node.

## Idempotence

`app:root` is convergent and idempotent. Re-running it with the same root as
gateway configuration is a documented success path and is safe to use as a "redo
apply" recovery action. The command always re-applies artifacts, so re-running
it against an app that is already managed still refreshes node artifacts.

## Output

Use `--json` to receive structured output; omit it for a human-readable summary.

- **Human Output:** Summarizes the changed runtime configuration and the
  status of re-applied artifacts on the node.
- **JSON Output:** Returns a machine-readable result or failure containing the
  updated app state. Drift during a successful run is reported as app-family
  warning metadata.

## Requirements

- The application must exist and be managed by Orbit.
- The CLI caller role must be `control` or `gateway`. App-role callers are
  denied before prompts or side effects.
- The caller must have permission to manage the target application.
- The application node must be reachable by the gateway over SSH.

## Related Commands

Use these commands before or after `app:root` to inspect or repair app configuration.

- [`orbit app:show [app]`](../4_app-show/app-show.md)
- [`orbit app:register [name]`](../2_app-register/app-register.md)
- [`doctor --family=app`](../app-doctor.md)
