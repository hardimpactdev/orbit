# PHP Runtime Commands

Select PHP runtime intent for apps, workspaces, and the node CLI default. App
and workspace web runtimes use FrankenPHP containers; host PHP is for
ad-hoc/app-source workflows. The PHP image catalog is documented under the
[`php` tool](../../../apps/docs/content/domains/3_tool/catalog/php.md). Spec:
[`apps/docs/content/domains/14_php/`](../../../apps/docs/content/domains/14_php/).

Supported versions: `8.3`, `8.4`, `8.5` (default for new apps).

When changing an app or workspace PHP version, Orbit updates gateway-tracked
runtime selection and recreates the affected FrankenPHP runtime artifact from
the selected image through the owning node. PHP-FPM is not a fallback and must
not be restored manually.

On `app-dev` nodes, classic PHP app and workspace containers include native
FrankenPHP thread-pool tuning (`max_threads auto`, `max_idle_time 1h`).
Laravel Octane worker mode remains opt-in through `instance:worker`.

## `orbit php:list`

List PHP support, installed facts, and selected runtime intent.

```bash
orbit php:list [--instance=<name>] [--workspace=<name>] [--node=<name>] [--live] [--json]
```

| Option | Notes |
|---|---|
| `--instance` | Show selected runtime for one `project.instance`. |
| `--workspace` | Show effective runtime for one workspace (own override or inherited). |
| `--node` | Show CLI default for the node. |
| `--live` | Probe the node for actually-installed PHP versions instead of relying on gateway-tracked facts. |

Without scope flags, returns the global support matrix and currently-selected node CLI defaults across the fleet.

## `orbit php:use [version]`

Select PHP runtime intent at one of three scopes: app, workspace, or node CLI default.

```bash
orbit php:use [<version>] [--instance=<name>] [--workspace=<name>] [--node=<name>]
              [--inherit] [--cli] [--json]
```

| Option | Notes |
|---|---|
| `version` | `8.3` / `8.4` / `8.5`. Required unless `--inherit`. |
| `--instance` | Scope: PHP image selection for one instance runtime. |
| `--workspace` | Scope: workspace PHP override (otherwise inherits the app). |
| `--node` | Scope target node. Combine with `--cli` for the node CLI default. |
| `--inherit` | Workspace only  -  clear the override and re-inherit the app's PHP. |
| `--cli` | With `--node`, sets the node-wide CLI default PHP version. |

Examples:

```bash
orbit php:use 8.4 --instance=myapp.development      # change instance FrankenPHP image selection
orbit php:use --inherit --workspace=feature-x --instance=myapp.development
orbit php:use 8.5 --cli --node=beast           # default CLI PHP on the node
```

If a target version isn't installed yet, the partial-enactment warning points to
`tool:install php --tool-version=<v>` (or `tool:update --expected-version=<v>`
when updating an existing managed capability).
