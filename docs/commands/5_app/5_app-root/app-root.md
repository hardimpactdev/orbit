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

## Arguments And Options

- `app`: The name or hostname of the application to update.
- `root`: The new document root path, relative to the application's base path.
- `--json`: Output JSON.

## What Happens

`app:root` updates the application's document root configuration on the gateway
and then re-enacts the necessary runtime artifacts on the application node.

1.  **Intent Update:** Updates the gateway's recorded document root for the
    application. If the supplied root equals the current intent, the intent
    write is a no-op (`changed: false`); enactment still runs.
2.  **Artifact Re-enactment:** Triggers the gateway to re-render and upload the
    PHP-FPM configuration to the app node over SSH. The PHP-FPM pool reload
    required to pick up the new document root is part of this step. App-owned
    proxy route intent continues to belong to the app, but backend proxy
    artifact convergence belongs to the `proxy` family.
3.  **No File Movement:** The command only updates the path configuration; it
    does **not** move or rename any files or directories on the application
    node.

## Idempotence

`app:root` is convergent and idempotent. Re-running it with the same root as
gateway intent is a documented success path and is safe to use as a "redo
enactment" recovery action. The command always re-applies enactment so an
already-managed re-run still refreshes node artifacts.

## Output

- **Human Output:** Summarizes the changed runtime configuration and the
  status of re-enacted artifacts on the node.
- **JSON Output:** Returns a structured `success` or `error` envelope
  containing the updated app state. Drift during a successful run is
  reported under `success.meta.warnings[]` using singular `app.*` doctor codes
  with `family: "app"`.

## Requirements

- The application must exist and be managed by Orbit.
- The CLI caller role must be `control` or `gateway`. App-node callers are
  denied before prompts or side effects.
- The caller must have permission to manage the target application.
- The application node must be reachable by the gateway over SSH.

## Related Commands

- [`orbit app:show [app]`](../4_app-show/app-show.md)
- [`orbit app:register [name]`](../2_app-register/app-register.md)
- [`doctor --family=app`](../app-doctor.md)
