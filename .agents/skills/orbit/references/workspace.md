# Workspace Commands

Workspaces are isolated working copies of an app for parallel development (per
branch, per agent, per task). Each workspace gets its own route
(`{workspace}.{app}.{tld}` for development apps) and, for PHP apps, its own
FrankenPHP runtime container. Spec:
[`apps/docs/content/domains/6_workspace/`](../../../apps/docs/content/domains/6_workspace/).

## `orbit workspace:new [name]`

Create a workspace intent for an app.

```bash
orbit workspace:new [<name>] [--instance=<name>] [--base=main] [--php-version=<v>] [--json|--stream-json]
```

| Option | Default | Notes |
|---|---|---|
| `name` |  -  | Workspace slug (<=63 chars, independent of parent app). |
| `--instance` |  -  | Parent `app.instance` selector. |
| `--base` | `main` | Base git ref to branch from. |
| `--php-version` | inherit | Optional PHP version override (otherwise inherits the app's PHP version). |
| `--stream-json` | off | JSONL progress stream for agents; mutually exclusive with `--json`. |

`workspace:new` only writes intent. Use `workspace:setup` to converge.

## `orbit workspace:list`

```bash
orbit workspace:list [--instance=<name>] [--node=<name>] [--json]
```

## `orbit workspace:show [name]`

```bash
orbit workspace:show [<name>] [--instance=<name>] [--json]
```

## `orbit workspace:setup [name]`

Converge a workspace to a ready-to-develop-in state. Streams output from each setup step.

```bash
orbit workspace:setup [<name>] [--instance=<name>] [--path=<path>] [--json|--stream-json]
```

`--path` adopts an existing on-disk workspace path instead of creating a fresh checkout.
The path may live outside the parent app path, including external agent worktrees.
The parent app root itself is not a valid workspace path.
Use `--stream-json` for JSONL setup progress when an agent needs incremental
frames; use `--json` for the final result envelope only.

Workspace setup runs the steps configured for the workspace's app instance via
`workspace-setup-step:add --instance=<app.instance>`. There is no logical-project row or
read fallback.
Setup steps receive `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`,
`VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT` for the workspace URL.

## `orbit workspace:remove [name]`

Remove a workspace and its artifacts (route intent, runtime container/process
intent, certificate material, history, and files).

```bash
orbit workspace:remove [<name>] [--instance=<name>] [--keep-files] [--force] [--json]
```

`--keep-files` preserves the workspace directory on the owning app-role node;
useful when copying changes off the node first.

## `orbit workspace:history [name]`

Show workspace lifecycle history (setup runs, teardown runs, status changes).

```bash
orbit workspace:history [<name>] [--instance=<name>] [--limit=<n>] [--since=<iso>] [--until=<iso>] [--json]
```

## `orbit workspace:log [run]`

Show captured stdout/stderr for one lifecycle run.

```bash
orbit workspace:log [<run>] [--json]
```

`<run>` is the run id from `workspace:history`.

## Setup-step pipeline

App-instance-scoped, ordered list of shell commands that run during
`workspace:setup`. `add` writes require a dotted selector such as
`myapp.nmbp`; bare app slugs are rejected.

### `orbit workspace-setup-step:add`

```bash
orbit workspace-setup-step:add --command='<shell>' --instance=<app.instance>
                               [--before=<step-id>] [--after=<step-id>]
                               [--timeout=600] [--json]
```

Without `--before` / `--after`, the step is appended.

### `orbit workspace-setup-step:list`

```bash
orbit workspace-setup-step:list [--instance=<app.instance>] [--json]
```

The selector resolves exactly one app instance. Ambiguous bare app slugs fail
for explicit instance selection; there are no app-level fallback rows.

### `orbit workspace-setup-step:remove`

```bash
orbit workspace-setup-step:remove --step=<id> --instance=<app.instance> [--force] [--json]
```

Removes require a dotted app-instance selector and only delete
instance-owned rows.

## Teardown-step pipeline

App-instance-scoped ordered commands that run during `workspace:remove`.
Mirrors the setup pipeline.

```bash
orbit workspace-teardown-step:add --command='<shell>' --instance=<app.instance>
                                  [--before=<step-id>] [--after=<step-id>]
                                  [--timeout=600] [--json]
orbit workspace-teardown-step:list [--instance=<app.instance>] [--json]
orbit workspace-teardown-step:remove --step=<id> --instance=<app.instance> [--force] [--json]
```

## Examples

```bash
# Define a typical Laravel setup pipeline once per app instance
orbit workspace-setup-step:add --instance=myapp.nmbp --command='composer install'
orbit workspace-setup-step:add --instance=myapp.nmbp --command='npm ci'
orbit workspace-setup-step:add --instance=myapp.nmbp --command='cp .env.example .env'
orbit workspace-setup-step:add --instance=myapp.nmbp --command='php artisan key:generate'
orbit workspace-setup-step:add --instance=myapp.nmbp --command='php artisan migrate --seed'

# Spin up a workspace for a feature branch
orbit workspace:new feature-x --instance=myapp.nmbp --base=main
orbit workspace:setup feature-x --instance=myapp.nmbp
# Served at feature-x.myapp.beast
```
