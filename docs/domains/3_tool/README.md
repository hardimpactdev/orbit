# Tool Commands

Tool commands manage node capabilities Orbit installs, configures, observes,
and keeps converged. Tools are Orbit product concepts; package managers,
binaries, containers, and services are backend details.

## Domain Rules

These rules govern what the tool command family owns and what it may not touch.

- The tool command family owns the `tool:*` command prefix.
- `tool` is a state family. A gateway tool row is the expected state for one
  tool on one node.
- Tool rows include the node, tool name, expected lifecycle state, expected
  version or config when the tool definition tracks them, install paths, and
  backend-specific probe and repair settings.
- CLI callers resolve input locally, then the gateway reads or writes configuration and
  performs node inspection or applies changes.
- Some tools are observational, while others are managed by Orbit.
- Tool reads use gateway-tracked configuration by default. Live node status is
  included only when a command explicitly requests live inspection or when
  doctor runs.
- `tool:update` changes expected version configuration or updates a managed tool to the latest
  supported version. It is not a generic setup rerun command.
- `tool:reconfigure` reruns a managed tool's configuration/setup flow without
  changing the intended version.
- `tool:reload` reloads configuration without a full restart only when the tool
  definition supports reload.
- Tools observed on a node without a gateway tool row are unmanaged inventory,
  not drift, unless explicit `doctor --fix --family=tool --adopt` semantics are used.
- Role baseline tools are materialized as tool rows during node provisioning, so
  doctor has one gateway-owned source of truth per node.
- Node reality import is not part of the tool command surface. If an adoption
  flow needs to adopt node reality, it must use explicit
  `doctor --fix --family=tool --adopt` semantics.
- Credential-bearing managed service tools use Orbit-owned generated secrets.
  The default service username is `orbit` when the protocol has a username
  concept.
- Tool definitions may declare tool-owned service endpoints. HTTP and
  WebSocket tool endpoints are represented as tool-owned `proxy` routes; TCP
  service endpoints are WireGuard-only host/port records and are not HTTP proxy
  routes.
- Tools supply capabilities that other domains depend on, but they do not own
  apps, workspaces, processes, schedules, custom proxy routes, or non-tool
  firewall policy.
- Tool-specific and capability-specific command families are opt-in product
  surfaces. The tool catalog does not automatically create `redis:*`,
  `mysql:*`, `postgres:*`, or other top-level command families. Generic tool
  lifecycle and inventory remain under `tool:*`; separate command families are
  admitted only when the workflow is clearer as its own Orbit product surface.
  PHP runtime selection is admitted as `php:*`. Redis-native data-plane
  operations may use `redis:*`. Database connection inventory, env convergence,
  schema inspection, and audited SQL execution belong to the `database:*`
  family, not per-database command families.

## Supported Tool Catalog

Orbit supports only the catalogued tool slugs below. A syntactically valid tool
name that is not present in this catalog fails as an unsupported tool. Detailed
tool-specific contracts live in [`catalog/`](catalog/README.md).

| Slug | Label | Backend | Support model | Category | Primary capability surface |
| --- | --- | --- | --- | --- | --- |
| [`caddy`](catalog/caddy.md) | Caddy | system service | Required baseline, adopted and kept converged | `always` | lifecycle, reload, reconfigure, update, logs, fix, adopt |
| [`supervisor`](catalog/supervisor.md) | Supervisor | system service | Required baseline, adopted and kept converged | `always` | lifecycle, reload, logs, fix, adopt |
| [`docker`](catalog/docker.md) | Docker | system service | Required baseline, adopted and kept converged | `always` | probe, fix, adopt, prerequisite for Docker-backed tools |
| [`viteplus`](catalog/viteplus.md) | VitePlus | system binary | Required baseline, adopted and kept converged | `always` | probe, adopt |
| [`php-cli`](catalog/php-cli.md) | PHP CLI | system binary | Required baseline, adopted and kept converged | `always` | probe, adopt |
| [`gh`](catalog/gh.md) | GitHub CLI | system binary | Required baseline, adopted and kept converged | `always` | update, adopt |
| [`composer`](catalog/composer.md) | Composer | system binary | Required baseline, adopted and kept converged | `always` | update, adopt |
| [`dns`](catalog/dns.md) | DNS | Docker service | Required infrastructure tool, adopted and kept converged | `infrastructure` | lifecycle, update, logs, fix, adopt |
| [`php`](catalog/php.md) | PHP-FPM | system service | Installable and removable by Orbit | `runtime` | install, remove, lifecycle, reload, update, logs, fix, adopt |
| [`postgres`](catalog/postgres.md) | PostgreSQL | Docker service | Installable and removable by Orbit | `database` | install, remove, lifecycle, update, logs, credentials, service endpoint, fix, adopt |
| [`mysql`](catalog/mysql.md) | MySQL | Docker service | Installable and removable by Orbit | `database` | install, remove, lifecycle, update, logs, credentials, service endpoint, fix, adopt |
| [`redis`](catalog/redis.md) | Redis | Docker service | Installable and removable by Orbit | `cache` | install, remove, lifecycle, update, logs, credentials, service endpoint, fix, adopt |
| [`mailpit`](catalog/mailpit.md) | Mailpit | Docker service | Installable and removable by Orbit | `development` | install, remove, lifecycle, update, logs, credentials, service endpoint, fix, adopt |
| [`reverb`](catalog/reverb.md) | Reverb | Docker service | Installable and removable by Orbit | `communication` | install, remove, lifecycle, update, logs, credentials, service endpoint, fix, adopt |
| [`polyscope-server`](catalog/polyscope-server.md) | Polyscope Server | user systemd service | Installable and removable by Orbit | `development` | install, remove, lifecycle, reconfigure, update, streamed logs, fix, adopt |
| [`opencode-server`](catalog/opencode-server.md) | OpenCode Server | user systemd service | Installable and removable by Orbit | `development` | install, remove, lifecycle, reconfigure, password reset, update, streamed logs, credentials, service endpoint, fix, adopt |

Required baseline tools are expected to exist as part of node provisioning or
host bootstrap. `tool:install` does not create those tools from scratch unless
a future tool definition explicitly changes their support model. Installable
tools are provisioned by `tool:install`, removed by `tool:remove`, and verified
by `doctor --family=tool`.

The `dns` tool is the runtime capability behind Orbit-managed DNS
infrastructure. DNS records, zones, and DNS command behavior remain owned by
the DNS command family.

## Tool JSON Entity

Tool-family JSON renderers that return one tool entity embed this shape under
`success.data.tool`, or directly under `success.data.tools[]` for list items.
Command-specific outcome fields, log frames, or credential fields live beside
the entity in the command result.

```json
{
  "name": "redis",
  "node": "app-1",
  "expected_state": "running",
  "observed_state": "running",
  "version": "7.2",
  "managed": true,
  "endpoints": [
    {
      "name": "redis",
      "kind": "tcp",
      "host": "orbit.test",
      "port": 6379
    }
  ]
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Tool identity in Orbit's tool catalog. |
| `node` | string | Node slug where the tool is expected. |
| `expected_state` | string | Gateway-owned intended lifecycle state, such as `installed`, `running`, or `absent`. |
| `observed_state` | string \| null | Last known or live observed state when the command includes it. Registry reads may return `null`. |
| `version` | string \| null | Intended or observed version when the tool definition tracks versions. |
| `managed` | boolean | Whether Orbit owns lifecycle/configuration for this tool on the node. |
| `endpoints` | array | Non-secret service endpoint metadata declared by the tool definition. Omit or return an empty array when the tool declares no service endpoint. |

## Commands

The `tool:*` family covers inventory, lifecycle, configuration, and credential operations.

### Inventory and inspection

These commands list catalogued tools and show per-tool state for a node.

1. [`orbit tool:list`](1_tool-list/tool-list.md)
2. [`orbit tool:show <tool>`](2_tool-show/tool-show.md)

### Provisioning

These commands create or remove an installable tool on a node.

3. [`orbit tool:install <tool>`](3_tool-install/tool-install.md)
4. [`orbit tool:remove <tool>`](4_tool-remove/tool-remove.md)

### Lifecycle

These commands run, stop, restart, or inspect logs for a managed tool.

5. [`orbit tool:start <tool>`](5_tool-start/tool-start.md)
6. [`orbit tool:stop <tool>`](6_tool-stop/tool-stop.md)
7. [`orbit tool:restart <tool>`](7_tool-restart/tool-restart.md)
8. [`orbit tool:logs <tool>`](8_tool-logs/tool-logs.md)

### Configuration and credentials

These commands change tool versions or configuration and expose generated credentials.

9. [`orbit tool:update [tool]`](9_tool-update/tool-update.md)
10. [`orbit tool:credentials [tool]`](10_tool-credentials/tool-credentials.md)
11. [`orbit tool:reload [tool]`](11_tool-reload/tool-reload.md)
12. [`orbit tool:reconfigure [tool]`](12_tool-reconfigure/tool-reconfigure.md)

## Related

- [`doctor --family=tool`](tool-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
- [`orbit firewall:*`](../4_firewall/README.md)
