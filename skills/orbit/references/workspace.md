# Workspace Commands

Workspaces are isolated working copies of an app for parallel development (per branch, per agent, per task). Each workspace gets its own Caddy vhost (`{workspace}.{app}.{tld}`), PHP-FPM pool, and certificate. Spec: [`docs/commands/6_workspace/`](../../../docs/commands/6_workspace/).

## `orbit workspace:new [name]`

Create a workspace intent for an app.

```bash
orbit workspace:new [<name>] [--app=<name>] [--base=main] [--php-version=<v>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` | — | Workspace slug (≤63 chars, independent of parent app). |
| `--app` | — | Parent app slug. |
| `--base` | `main` | Base git ref to branch from. |
| `--php-version` | inherit | Optional PHP version override (otherwise inherits the app's PHP version). |

`workspace:new` only writes intent. Use `workspace:setup` to converge.

## `orbit workspace:list`

```bash
orbit workspace:list [--app=<name>] [--node=<name>] [--json]
```

## `orbit workspace:show [name]`

```bash
orbit workspace:show [<name>] [--app=<name>] [--json]
```

## `orbit workspace:setup [name]`

Converge a workspace to a ready-to-develop-in state. Streams output from each setup step.

```bash
orbit workspace:setup [<name>] [--app=<name>] [--path=<path>] [--json]
```

`--path` adopts an existing on-disk workspace path instead of creating a fresh checkout.

Workspace setup runs the steps configured for the parent app via `workspace-setup-step:add`.

## `orbit workspace:remove [name]`

Remove a workspace and its artifacts (Caddy site, FPM pool, cert, files).

```bash
orbit workspace:remove [<name>] [--app=<name>] [--keep-files] [--force] [--json]
```

`--keep-files` preserves the workspace directory on the app node — useful when copying changes off the node first.

## `orbit workspace:history [name]`

Show workspace lifecycle history (setup runs, teardown runs, status changes).

```bash
orbit workspace:history [<name>] [--app=<name>] [--limit=<n>] [--since=<iso>] [--until=<iso>] [--json]
```

## `orbit workspace:log [run]`

Show captured stdout/stderr for one lifecycle run.

```bash
orbit workspace:log [<run>] [--json]
```

`<run>` is the run id from `workspace:history`.

## Setup-step pipeline

App-scoped, ordered list of shell commands that run during `workspace:setup`.

### `orbit workspace-setup-step:add`

```bash
orbit workspace-setup-step:add --command='<shell>' [--app=<name>]
                               [--before=<step-id>] [--after=<step-id>]
                               [--timeout=600] [--json]
```

Without `--before` / `--after`, the step is appended.

### `orbit workspace-setup-step:list`

```bash
orbit workspace-setup-step:list [--app=<name>] [--json]
```

### `orbit workspace-setup-step:remove`

```bash
orbit workspace-setup-step:remove --step=<id> [--app=<name>] [--force] [--json]
```

## Teardown-step pipeline

App-scoped, ordered list of commands that run during `workspace:remove`. Mirrors the setup pipeline.

```bash
orbit workspace-teardown-step:add --command='<shell>' [--app=<name>]
                                  [--before=<step-id>] [--after=<step-id>]
                                  [--timeout=600] [--json]
orbit workspace-teardown-step:list [--app=<name>] [--json]
orbit workspace-teardown-step:remove --step=<id> [--app=<name>] [--force] [--json]
```

## Examples

```bash
# Define a typical Laravel setup pipeline once for the app
orbit workspace-setup-step:add --app=myapp --command='composer install'
orbit workspace-setup-step:add --app=myapp --command='npm ci'
orbit workspace-setup-step:add --app=myapp --command='cp .env.example .env'
orbit workspace-setup-step:add --app=myapp --command='php artisan key:generate'
orbit workspace-setup-step:add --app=myapp --command='php artisan migrate --seed'

# Spin up a workspace for a feature branch
orbit workspace:new feature-x --app=myapp --base=main
orbit workspace:setup feature-x --app=myapp
# Served at feature-x.myapp.beast
```
