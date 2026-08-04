# Project and Instance Commands

Orbit models application state as `Project -> Instance -> Workspace`.

- A project is the global logical identity: its canonical name, repository, and
  shared runtime policy.
- An instance is one concrete placement of that project. It owns its driver,
  node or cloud environment, path, document root, domain, runtime requirements,
  environment values, and database targets.
- A workspace belongs to one instance.

Use dotted `project.instance` selectors for instance-scoped commands, for
example `hauser.development` or `hauser.production-cloud`. A project can have
many instances, including Orbit placements on `app-dev` or `app-prod` role
nodes and externally driven placements such as Laravel Cloud. The `app-*` terms
remain valid for infrastructure roles and runtime containers; they are not
public project command families.

All project and instance commands flow through the gateway. Product contract:
[`apps/docs/content/domains/5_project/`](../../../apps/docs/content/domains/5_project/).

## Create or adopt

### `orbit project:new [project]`

Create the project and its first Orbit instance together, cloning or creating
the source directory on an eligible app-role node.

```bash
orbit project:new [<project>] [--node=<name>] [--repo=<git>] [--root=public]
                  [--php-version=8.5] [--domain=<host>]
                  [--runtime-proxy-transport=http|https]
                  [--json|--stream-json]
```

`--node` defaults to the local `node:default`. `--repo` accepts a Git URL or
`owner/repo` GitHub shorthand. `--domain` configures the first instance's
production host.

```bash
orbit project:new docs --repo=acme/docs
orbit project:new shop --node=prod-1 --repo=acme/shop --domain=shop.example.com
```

### `orbit instance:register [project]`

Adopt an existing path as the project's first or current Orbit instance, or
reapply its runtime and route intent. The command is idempotent and does not
clone.

```bash
orbit instance:register [<project>] [--node=<name>] [--path=<path>]
                        [--root=public] [--php-version=8.5]
                        [--domain=<host>] [--json]
```

Use it after moving a project under Orbit management or to retry instance
convergence after DNS or runtime prerequisites change.

## Inspect projects and instances

```bash
orbit project:list [--json]
orbit project:show [<project>] [--json]
orbit instance:list [--project=<project>] [--json]
orbit instance:show [<project.instance>] [--json]
```

`project:list` returns each logical project once. `project:show` expands the
caller-visible instances and their nested workspaces. `instance:list` and
`instance:show` expose concrete placement details.

## Add or remove instances

Add another placement with one dotted selector:

```bash
orbit instance:add <project.instance> [--driver=orbit|laravel-cloud]
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

Remove only one placement while keeping the project and sibling instances:

```bash
orbit instance:remove <project.instance> --force [--json]
```

Remove a project and all of its owned instances and workspaces:

```bash
orbit project:remove [<project>] --force [--json]
```

Project removal preauthorizes every affected Orbit instance before performing
destructive work.

## Configure one instance

The following commands all take a dotted instance selector:

```bash
orbit instance:root <project.instance> <root> [--json]
orbit instance:worker [show|enable|disable] <project.instance> [--json]
```

- `instance:root` changes the document root relative to the instance path.
- `instance:worker` controls opt-in FrankenPHP worker mode.
  Agent IDE adapter.

Manage runtime mounts with:

```bash
orbit instance:mount list <project.instance> [--json]
orbit instance:mount add <project.instance> <source> <target> [--read-only] [--json]
orbit instance:mount remove <project.instance> <target> [--force] [--json]
```

## Setup pipeline

```bash
orbit instance:setup <project.instance> [--json|--stream-json]
orbit instance-setup-step:add <project.instance> --command=<command>
                              [--before=<id>] [--after=<id>]
                              [--timeout=600] [--json]
orbit instance-setup-step:list <project.instance> [--json]
orbit instance-setup-step:remove <project.instance> --step=<id> --force [--json]
```

Setup steps are finite bootstrap commands that run with the selected instance's
host toolchain. Use processes for long-running services.

## Environment values

`instance:env` stores non-secret values for exactly one instance:

```bash
orbit instance:env list <project.instance> [--json]
orbit instance:env set <project.instance> --key=<KEY> --value=<value> [--apply] [--json]
orbit instance:env render <project.instance> [--json]
```

`--apply` writes the stored value to the live instance, clears Laravel caches,
and reapplies its runtime. Without `--apply`, the value remains gateway intent
only. Secret writes are rejected; attach database credentials through
`database:attach --instance=<project.instance>`.

## Analytics and WebSockets

Analytics binding commands are instance-scoped:

```bash
orbit instance:analytics enable <project.instance> [--json]
orbit instance:analytics disable <project.instance> [--json]
orbit instance:analytics show <project.instance> [--json]
orbit instance:analytics verify <project.instance> [--json]
```

WebSocket binding and credentials also follow the selected instance:

```bash
orbit instance:websocket enable <project.instance> [--host=<public-host>] [--json]
orbit instance:websocket disable <project.instance> [--json]
orbit instance:websocket credentials <project.instance> [--json]
```

The websocket runtime itself belongs to nodes carrying the `websocket` role.
These commands manage the instance binding, allowed origins, credentials, and
public route intent.
