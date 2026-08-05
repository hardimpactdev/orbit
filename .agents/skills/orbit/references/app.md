# App and Instance Commands

Orbit models application state as `App → Instance → Workspace`.

- An app is the global logical identity: its canonical name, repository, and
  shared runtime policy.
- An instance is one concrete placement of that app. It owns its driver,
  node or cloud environment, path, document root, domain, runtime requirements,
  environment values, and database targets.
- A workspace belongs to one instance.

Use dotted `app.instance` selectors for instance-scoped commands, for
example `hauser.development` or `hauser.production-cloud`. An app can have
many instances, including Orbit placements on `app-dev` or `app-prod` role
nodes and externally driven placements such as Laravel Cloud. The `app-*` terms
remain valid for infrastructure roles and runtime containers; they are not
public app command families.

All app and instance commands flow through the gateway. Product contract:
[`apps/docs/content/domains/5_app/`](../../../apps/docs/content/domains/5_app/).

## Create or adopt

### `orbit app:new [app]`

Create the app and its first Orbit instance together, cloning or creating
the source directory on an eligible app-role node.

```bash
orbit app:new [<app>] [--node=<name>] [--repo=<git>] [--root=public]
                  [--php-version=8.5] [--domain=<host>]
                  [--runtime-proxy-transport=http|https]
                  [--json|--stream-json]
```

`--node` defaults to the local `node:default`. `--repo` accepts a Git URL or
`owner/repo` GitHub shorthand. `--domain` configures the first instance's
production host.

```bash
orbit app:new docs --repo=acme/docs
orbit app:new shop --node=prod-1 --repo=acme/shop --domain=shop.example.com
```

### `orbit instance:register [app]`

Adopt an existing path as the app's first or current Orbit instance, or
reapply its runtime and route intent. The command is idempotent and does not
clone.

```bash
orbit instance:register [<app>] [--node=<name>] [--path=<path>]
                        [--root=public] [--php-version=8.5]
                        [--domain=<host>] [--json]
```

Use it after moving an app under Orbit management or to retry instance
convergence after DNS or runtime prerequisites change.

## Inspect apps and instances

```bash
orbit app:list [--json]
orbit app:show [<app>] [--json]
orbit instance:list [--app=<app>] [--json]
orbit instance:show [<app.instance>] [--json]
```

`app:list` returns each logical app once. `app:show` expands the
caller-visible instances and their nested workspaces. `instance:list` and
`instance:show` expose concrete placement details.

## Add or remove instances

Add another placement with one dotted selector:

```bash
orbit instance:add <app.instance> [--driver=orbit|laravel-cloud]
                   [--node=<node>] [--path=<path>] [--root=<root>]
                   [--domain=<host>] [--cloud-app=<app>]
                   [--cloud-environment=<environment>]
                   [--php-extension=<extension>] [--json]
```

`--php-extension` is repeatable. Orbit-driver instances use `--node` and
`--path`; Laravel Cloud instances use the cloud application and environment
options.

```bash
orbit instance:add billing.development --driver=orbit --node=app-dev-1 --path=/srv/billing
orbit instance:add billing.production-cloud --driver=laravel-cloud --cloud-app=app_123 --cloud-environment=env_123 --php-extension=redis
```

Remove only one placement while keeping the app and sibling instances:

```bash
orbit instance:remove <app.instance> --force [--json]
```

Remove an app and all of its owned instances and workspaces:

```bash
orbit app:remove [<app>] --force [--json]
```

App removal preauthorizes every affected Orbit instance before performing
destructive work.

## Configure one instance

The following commands all take a dotted instance selector:

```bash
orbit instance:root <app.instance> <root> [--json]
orbit instance:worker [show|enable|disable] <app.instance> [--json]
```

- `instance:root` changes the document root relative to the instance path.
- `instance:worker` controls opt-in FrankenPHP worker mode.

Manage runtime mounts with:

```bash
orbit instance:mount list <app.instance> [--json]
orbit instance:mount add <app.instance> <source> <target> [--read-only] [--json]
orbit instance:mount remove <app.instance> <target> [--force] [--json]
```

## Setup pipeline

```bash
orbit instance:setup <app.instance> [--json|--stream-json]
orbit instance-setup-step:add <app.instance> --command=<command>
                              [--before=<id>] [--after=<id>]
                              [--timeout=600] [--json]
orbit instance-setup-step:list <app.instance> [--json]
orbit instance-setup-step:remove <app.instance> --step=<id> --force [--json]
```

Setup steps are finite bootstrap commands that run with the selected instance's
host toolchain. Use processes for long-running services.

## Environment values

`instance:env` stores non-secret values for exactly one instance:

```bash
orbit instance:env list <app.instance> [--json]
orbit instance:env set <app.instance> --key=<KEY> --value=<value> [--apply] [--json]
orbit instance:env render <app.instance> [--json]
```

`--apply` writes the stored value to the live instance, clears Laravel caches,
and reapplies its runtime. Without `--apply`, the value remains gateway intent
only. Secret writes are rejected; attach database credentials through
`database:attach --instance=<app.instance>`.

## Analytics and WebSockets

Analytics binding commands are instance-scoped:

```bash
orbit instance:analytics enable <app.instance> [--json]
orbit instance:analytics disable <app.instance> [--json]
orbit instance:analytics show <app.instance> [--json]
orbit instance:analytics verify <app.instance> [--json]
```

WebSocket binding and credentials also follow the selected instance:

```bash
orbit instance:websocket enable <app.instance> [--host=<public-host>] [--json]
orbit instance:websocket disable <app.instance> [--json]
orbit instance:websocket credentials <app.instance> [--json]
```

The websocket runtime itself belongs to nodes carrying the `websocket` role.
These commands manage the instance binding, allowed origins, credentials, and
public route intent.
