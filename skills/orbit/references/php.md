# PHP Runtime Commands

Select PHP runtime intent for apps, workspaces, and the node CLI default. The actual PHP runtime is installed via the [`php` catalog tool](../../../docs/domains/3_tool/catalog/php.md). Spec: [`docs/domains/14_php/`](../../../docs/domains/14_php/).

Supported versions: `8.3`, `8.4`, `8.5` (default for new apps).

When changing an app's PHP version, Orbit moves the per-app FPM pool to the new version's `pool.d/`, restarts both the old and new PHP-FPM, and updates Caddy. The affected app sees a ~1–2 second blip while the socket transfers; other apps are unaffected. The DB is updated only after the new pool is serving, with full rollback on any failure.

## `orbit php:list`

List PHP support, installed facts, and selected runtime intent.

```bash
orbit php:list [--app=<name>] [--workspace=<name>] [--node=<name>] [--live] [--json]
```

| Option | Notes |
|---|---|
| `--app` | Show selected runtime for one app. |
| `--workspace` | Show effective runtime for one workspace (own override or inherited). |
| `--node` | Show CLI default for the node. |
| `--live` | Probe the node for actually-installed PHP versions instead of relying on gateway-tracked facts. |

Without scope flags, returns the global support matrix and currently-selected node CLI defaults across the fleet.

## `orbit php:use [version]`

Select PHP runtime intent at one of three scopes: app, workspace, or node CLI default.

```bash
orbit php:use [<version>] [--app=<name>] [--workspace=<name>] [--node=<name>]
              [--inherit] [--cli] [--json]
```

| Option | Notes |
|---|---|
| `version` | `8.3` / `8.4` / `8.5`. Required unless `--inherit`. |
| `--app` | Scope: app PHP version (controls FPM pool and CLI inside the app path). |
| `--workspace` | Scope: workspace PHP override (otherwise inherits the app). |
| `--node` | Scope target node. Combine with `--cli` for the node CLI default. |
| `--inherit` | Workspace only — clear the override and re-inherit the app's PHP. |
| `--cli` | With `--node`, sets the node-wide CLI default PHP version. |

Examples:

```bash
orbit php:use 8.4 --app=myapp                  # change app PHP (live FPM swap)
orbit php:use --inherit --workspace=feature-x --app=myapp
orbit php:use 8.5 --cli --node=beast           # default CLI PHP on the node
```

If a target version isn't installed yet, the partial-enactment warning points to `tool:install php --expected-version=<v>` (or `tool:update` if a different version of `php` is already installed).
