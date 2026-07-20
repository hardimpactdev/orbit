# `orbit instance:register [project]`

[Back to Project and instance commands.](../README.md)

Create, adopt, move, or re-apply Orbit management for one concrete app
instance.

## Usage

```bash
orbit instance:register [project]
orbit instance:register [project] --node=app-1 --path=/home/orbit/apps/my-app
orbit instance:register [project] --domain=example.com --json
```

## Examples

```bash
# Register a manually cloned app
orbit instance:register my-app --node=app-1 --path=/home/orbit/apps/my-app

# Re-apply Orbit management (e.g., after manual node changes)
orbit instance:register my-app.development

# Retry production activation for an existing project (safe to call repeatedly)
orbit instance:register my-app.production --domain=example.com
```

## Arguments and options

The following arguments and options shape an `instance:register` invocation.

- `project`: A dotted instance selector. A bare project slug creates the
  deterministic first instance for an unregistered project or resolves exactly one
  visible existing instance; otherwise it fails with
  `validation_failed`, `meta.reason=instance_required`.
- `--path=<path>`: The absolute path to the app on the target node.
- `--node=<name>`: The target node.
- `--root=<path>`: The document root relative to the selected instance path. Default: `public`.
- `--php-version=<version>`: The app runtime container version to store in gateway project configuration.
- `--runtime-proxy-transport=<http|https>`: The app-dev FrankenPHP transport between `orbit-caddy` and the runtime container. Default: existing value or `http`; `https` opts the app into inner TLS.
- `--domain=<host>`: The production domain. Triggers or retries production activation.
- `--json`: Output JSON.

### `--path` adoption requirement

`--path` is required when adopting a path that is not yet known to Orbit.

### `--node` defaults

`--node` defaults to the selected instance's serving node. For first adoption,
it uses the local default node or an interactive prompt.

### `--php-version` defaults

When `--php-version` is omitted, existing projects keep their shared runtime
value. A newly adopted project uses Orbit's app runtime default (`8.5`),
not any host PHP default.

`--repo` is not accepted. In the current converted project and instance command surface,
repository URL is metadata that is captured only at creation time by
[`project:new`](../1_project-new/project-new.md). `instance:register` re-applies management for
an existing path; it never clones, re-clones, mutates app source, or changes
repository metadata. Re-registering an existing project preserves its stored
repository value. Explicitly supplying both `--node` and `--path` for a
selected instance moves only that instance to the pre-existing path on another
eligible app node. Adopting the first unmanaged path atomically creates the
project with `repository=null` and its first instance. That instance is
named `development` without `--domain` or `production` with `--domain`.

## What Happens

Run `instance:register` when you need to install, re-apply, or retry Orbit management for a project instance.

`instance:register` ensures that an application is correctly recorded in the Orbit
gateway and that its runtime artifacts are properly applied on the target app
node.

An `app-dev` node's self-grant includes `instance:register` for that same node, so
local app-dev CLIs can register or re-apply instances hosted by themselves. `app-prod`
self-grants do not include `instance:register`; production registration requires an
explicit operator/deploy grant to the target app node.

1. **Resolution**: Resolves exactly one dotted instance. Bare logical
   shorthand is valid only for exactly one visible existing instance; a first
   adoption derives `development` or `production` as described above.
2. **Registration/Adoption**: Writes or converges the selected instance. First
   adoption atomically creates the project and first instance. Path-derived
   `adopted` state belongs to the instance.
3. **Move**: Moves only the selected instance to another eligible node/path,
   and only when both `--node` and `--path` are explicit.
4. **Apply**: Uses Agent push on the concrete instance node to configure the
   runtime container and install runtime configuration. It then records instance-owned proxy route
   configuration for the `proxy` family to converge.
5. **Production Activation**: Performs DNS and TLS checks to activate production routing.

Step 5 only runs when a domain is supplied.

### Pending production prerequisites

If DNS or TLS prerequisites are pending at Production Activation time, registration still succeeds. The inactive domain is reported as a non-fatal warning. Retry the same command once propagation completes.

### Idempotency

This command is idempotent. Re-running it on an instance that is already
managed re-renders that instance's artifacts and verifies the result; if
nothing changes, the command still succeeds. The result reports which path the
run took (`registered`, `adopted`, `moved`, `partial`, or `converged`). `partial`
means selected-instance route intent persisted but proxy enactment failed; the
warning identifies the dotted selector, failed node, and operation.

## Output

You receive output in the format determined by the presence of `--json`.

### Human

Progress showing each phase, followed by a success line keyed to the result (`registered`, `adopted`, `moved`, `partial`, or `converged`) and any non-fatal warnings.

### JSON

A machine-readable result with separate canonical `project` and `instance`
entities. The durable `adopted` flag is on `instance`.

## Requirements

- The CLI caller must be able to reach the Orbit gateway.
- The target non-gateway node must be reachable through Agent push.
- The target node must be an active node.
- The supplied `--path` on the resolved node must not already be owned by a
  different instance. A path collision fails before side effects with
  `project.path_collision` and identifies that dotted instance.
- Moving an existing instance to another node/path requires an explicit dotted
  selector plus explicit `--node` and `--path`.

## Related Commands

Use these commands alongside `instance:register` for common app management workflows.

- [`orbit project:new`](../1_project-new/project-new.md)
- [`orbit project:list`](../3_project-list/project-list.md)
- [`orbit project:show`](../4_project-show/project-show.md)
- [`orbit project:remove`](../6_project-remove/project-remove.md)
- [Technical Contract](technical/1_instance-register.md)
