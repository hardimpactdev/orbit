# `orbit app:register [name]`

[Back to Apps commands.](../README.md)

Register or re-apply Orbit management for an app.

## Usage

```bash
orbit app:register [name]
orbit app:register [name] --node=app-1 --path=/home/orbit/apps/my-app
orbit app:register [name] --domain=example.com --json
```

## Examples

```bash
# Register a manually cloned app
orbit app:register my-app --node=app-1 --path=/home/orbit/apps/my-app

# Re-apply Orbit management (e.g., after manual node changes)
orbit app:register my-app

# Retry production activation for an existing app (safe to call repeatedly)
orbit app:register my-app --domain=example.com
```

## Arguments and options

The following arguments and options shape an `app:register` invocation.

- `name`: The name of the app.
- `--path=<path>`: The absolute path to the app on the target node.
- `--node=<name>`: The target node.
- `--root=<path>`: The document root relative to the app path. Default: `public`.
- `--php-version=<version>`: The app runtime container version to store in gateway app configuration.
- `--runtime-proxy-transport=<http|https>`: The app-dev FrankenPHP transport between `orbit-caddy` and the runtime container. Default: existing value or `http`; `https` opts the app into inner TLS.
- `--domain=<host>`: The production domain. Triggers or retries production activation.
- `--json`: Output JSON.

### `--path` adoption requirement

`--path` is required when adopting a path that is not yet known to Orbit.

### `--node` defaults

`--node` defaults to the existing app owner, the local default node, or an interactive prompt.

### `--php-version` defaults

When `--php-version` is omitted, existing apps keep their stored value. Newly adopted apps use Orbit's app runtime default (`8.5`), not any host PHP default.

`--repo` is not accepted. In the current converted app command surface,
repository URL is metadata that is captured only at creation time by
[`app:new`](../1_app-new/app-new.md). `app:register` re-applies management for
an existing path; it never clones, re-clones, mutates app source, or changes
repository metadata. Re-registering an existing app preserves its stored
repository value. Explicitly supplying both `--node` and `--path` for an
existing app moves the app record to that pre-existing path on another eligible
app node. Adopting an unmanaged path through `app:register` stores
`repository=null`.

## What Happens

Run `app:register` when you need to install, re-apply, or retry Orbit management for an app.

`app:register` ensures that an application is correctly recorded in the Orbit
gateway and that its runtime artifacts are properly applied on the target app
node.

An `app-dev` node's self-grant includes `app:register` for that same node, so
local app-dev CLIs can register or re-apply apps hosted by themselves. `app-prod`
self-grants do not include `app:register`; production registration requires an
explicit operator/deploy grant to the target app node.

1. **Resolution**: Identifies the app and target node from the provided name,
   options, or the CLI's stored `node:default` node.
2. **Registration/Adoption**: Writes the app's configuration to the gateway
   database. An existing path not yet managed by Orbit is adopted at this step.
3. **Move**: Existing apps can move to another eligible node/path only when both
   `--node` and `--path` are explicit.
4. **Apply**: Connects to the node over SSH to configure runtime container and
   install runtime configuration. It then records app-owned proxy route
   configuration for the `proxy` family to converge.
5. **Production Activation**: Performs DNS and TLS checks to activate production routing.

Step 5 only runs when a domain is supplied.

### Pending production prerequisites

If DNS or TLS prerequisites are pending at Production Activation time, registration still succeeds. The inactive domain is reported as a non-fatal warning. Retry the same command once propagation completes.

### Idempotency

This command is idempotent. Re-running it on an app that is already managed re-renders artifacts and verifies the result; if nothing changes, the command still succeeds. The result reports which path the run took (`registered`, `adopted`, `moved`, or `converged`) so operators and agents can see what changed.

## Output

You receive output in the format determined by the presence of `--json`.

### Human

Progress showing each phase, followed by a success line keyed to the result (`registered`, `adopted`, `moved`, or `converged`) and any non-fatal warnings.

### JSON

A machine-readable result with the app's registry data. It includes a durable `adopted` flag, set when the path was first adopted via registration.

## Requirements

- The CLI caller must be able to reach the Orbit gateway.
- The gateway must be able to reach the target node over SSH.
- The target node must be an active node.
- The supplied `--path` on the resolved node must not already be owned by a different registered app. A path collision fails before side effects with `app.path_collision`.
- Moving an existing app to another node/path requires explicit `--node` and `--path`.

## Related Commands

Use these commands alongside `app:register` for common app management workflows.

- [`orbit app:new`](../1_app-new/app-new.md)
- [`orbit app:list`](../3_app-list/app-list.md)
- [`orbit app:show`](../4_app-show/app-show.md)
- [`orbit app:remove`](../6_app-remove/app-remove.md)
- [Technical Contract](technical/1_app-register.md)
