# `orbit app:register [name]`

[Back to Apps commands.](../README.md)

Register or re-apply Orbit management for an app.

## Usage

```bash
orbit app:register [name]
orbit app:register [name] --path=/home/orbit/apps/my-app --node=app-1
orbit app:register [name] --domain=example.com --json
```

## Examples

```bash
# Register a manually cloned app
orbit app:register my-app --path=/home/orbit/apps/my-app --node=app-1

# Re-apply Orbit management (e.g., after manual node changes)
orbit app:register my-app

# Retry production activation for an existing app (safe to call repeatedly)
orbit app:register my-app --domain=example.com
```

## Arguments And Options

- `name`: The name of the app.
- `--path=<path>`: The absolute path to the app on the target node. Required when adopting an app path not yet known to Orbit.
- `--node=<name>`: The target app node. Defaults to the existing app owner, the local default development node, or an interactive prompt.
- `--root=<path>`: The document root relative to the app path. Default: `public`.
- `--php-version=<version>`: The app PHP-FPM version to store in gateway app
  intent. When omitted, existing apps keep their stored value and newly adopted
  apps use Orbit's app runtime default (`8.5`), not the owning node's CLI PHP
  default.
- `--domain=<host>`: The production domain. Triggers or retries production activation.
- `--json`: Output JSON.

`--repo` is not accepted. In the current converted app command surface,
repository URL is creation-time metadata captured only by
[`app:new`](../1_app-new/app-new.md). `app:register` re-applies management for
an existing path; it never clones, re-clones, mutates app source, or changes
repository metadata. Re-registering an existing app preserves its stored
repository value. Adopting an unmanaged path through `app:register` stores
`repository=null`.

## What Happens

`app:register` ensures that an application is correctly recorded in the Orbit
gateway and that its runtime artifacts are properly enacted on the target app
node.

1. **Resolution**: Identifies the app and target node from the provided name, options, or local context.
2. **Registration/Adoption**: Writes the app's intent to the gateway database. If the path already exists but isn't managed by Orbit, it is "adopted."
3. **Enactment**: Connects to the app node over SSH to configure PHP-FPM and install runtime configuration, then records app-owned proxy route intent for the `proxy_route` family to converge.
4. **Production Activation**: If a domain is supplied, it performs DNS and TLS checks to activate production routing. If DNS or TLS prerequisites are pending, the registration still succeeds and the inactive domain is reported as a non-fatal warning; retry the same command once propagation completes.

This command is idempotent. Re-running on an already-managed app re-renders
artifacts and verifies command-owned enactment; if nothing changes, the command
still succeeds. The result reports which path the run took (`registered`,
`adopted`, or `converged`) so operators and agents can see what changed.

## Output

- **Human**: A multi-step progress tree showing each phase of the registration and enactment process, followed by a success line keyed to the result (`registered`, `adopted`, or `converged`) and any non-fatal warnings.
- **JSON**: A `success` envelope with `success.data.result.action` and the app's registry data, including a durable `adopted` flag set when the path was first adopted via registration.

## Requirements

- The CLI caller must be able to reach the Orbit gateway.
- The gateway must be able to reach the target app node over SSH.
- The target node must be an active app node.
- The supplied `--path` on the resolved node must not already be owned by a different registered app. A path collision fails before side effects with `app.path_collision`.

## Related Commands

- [`orbit app:new`](../1_app-new/app-new.md)
- [`orbit app:list`](../3_app-list/app-list.md)
- [`orbit app:show`](../4_app-show/app-show.md)
- [`orbit app:remove`](../6_app-remove/app-remove.md)
- [Technical Contract](technical/1_app-register.md)
