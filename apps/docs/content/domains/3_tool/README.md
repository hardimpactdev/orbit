# Tool Commands

Tool commands manage node capabilities Orbit installs, updates, adopts,
removes, configures, observes, and keeps available for runtime units. Tools are
Orbit product concepts; package managers, binaries, containers, and services
are backend details.

## Domain Rules

These rules govern what the tool command family owns and what it may not touch.

- The tool command family owns the `tool:*` command prefix.
- `tool` is a state family. A gateway tool row is the expected state for one
  tool instance on one node.
- Tool rows include the node, tool name, instance id, expected capability
  state, version family, expected version or config when the tool definition
  tracks them, runtime family, install paths, and backend-specific probe and
  repair settings.
- CLI callers resolve input locally, then the gateway reads or writes configuration and
  performs node inspection or applies changes.
- Some tools are observational, while others are managed by Orbit.
- Tool reads use gateway-tracked configuration by default. Live node status is
  included only when a command explicitly requests live inspection or when
  doctor runs.
- Tool definitions declare supported version families and runtime families.
  Runtime support is platform-scoped: the runtime family plus `Node.platform`
  resolves the concrete implementation. A runtime family may be declared by a
  tool while still unsupported on a platform, such as `docker-swarm` on macOS
  until that implementation exists.
- `tool:update` changes expected version configuration or updates a managed
  tool instance to the latest supported version within the stored runtime. It
  does not silently migrate an installed instance from `docker` to
  `docker-swarm`; use the command contract that explicitly changes runtime
  once that migration workflow exists.
- `tool:reconfigure` reruns a managed tool's configuration/setup flow without
  changing the intended version.
- `tool:reload` reloads configuration without a full restart only when the tool
  definition supports reload.
- Tools observed on a node without a gateway tool row are unmanaged inventory,
  not drift, unless explicit `doctor --family=tool --adopt` semantics are used.
- Role baseline tools are materialized as tool rows during node provisioning, so
  doctor has one gateway-owned source of truth per node.
- Node reality import is not part of the tool command surface. If an adoption
  flow needs to adopt node reality, it must use explicit
  `doctor --family=tool --adopt` semantics.
- Credential-bearing managed capabilities use Orbit-owned generated secrets.
  The default service username is `orbit` when the protocol has a username
  concept.
- Tool definitions may declare tool-owned service endpoints. HTTP and
  WebSocket tool endpoints are represented as tool-owned `proxy` routes; TCP
  service endpoints are WireGuard-only host/port records using the serving
  node's WireGuard service address and are not HTTP proxy routes.
- Tools supply capabilities that other domains depend on, but they do not own
  apps, workspaces, processes, schedules, custom proxy routes, or non-tool
  firewall policy.
- Tools do not own start, stop, restart, or log lifecycle directly. Processes
  are the lifecycle-managed long-running units. A process may reference a tool
  with a tool dependency when it needs that node-level capability. During
  migration, existing `tool:start`, `tool:stop`, `tool:restart`, and
  `tool:logs` commands may resolve to related process lifecycle operations for
  compatibility.
- Tool-specific and capability-specific command families are opt-in product
  surfaces. The tool catalog does not automatically create `redis:*`,
  `mysql:*`, `postgres:*`, or other top-level command families. Generic tool
  lifecycle and inventory remain under `tool:*`; separate command families are
  admitted only when the workflow is clearer as its own Orbit product surface.
  PHP runtime selection is admitted as `php:*`. Redis-native data-plane
  operations may use `redis:*`. Database connection inventory, env convergence,
  schema inspection, audited SQL execution, and backup/restore where
  applicable belong to the `database:*` family, not per-database command
  families. S3 publication and service credential workflows belong to the
  `s3:*` family; generic RustFS lifecycle and inventory remain under `tool:*`.

## Supported Tool Catalog

Orbit supports only the catalogued tool slugs below. A syntactically valid tool
name that is not present in this catalog fails as an unsupported tool. Detailed
tool-specific contracts live in [`catalog/`](catalog/README.md).

| Slug | Label | Backend | Support model | Category | Primary capability surface |
| --- | --- | --- | --- | --- | --- |
| [`caddy`](catalog/caddy.md) | Caddy | `orbit-caddy` Docker container | Role baseline where HTTP routing is needed, adopted and kept converged | `always` | process-backed lifecycle during migration, reload, reconfigure, update, logs, fix, adopt |
| [`supervisor`](catalog/supervisor.md) | Supervisor | system service | Explicit residual runtime only where configured | `runtime` | capability probe, repair, reload where supported, adopt |
| [`docker`](catalog/docker.md) | Docker | system service | Required baseline, adopted and kept converged | `always` | probe, fix, adopt, prerequisite for Docker-backed tools |
| [`viteplus`](catalog/viteplus.md) | VitePlus | system binary | Role baseline tool for the `app-dev` and `app-prod` roles | `runtime` | probe, adopt |
| [`php-cli`](catalog/php-cli.md) | PHP CLI | prebuilt static host binaries (dl.static-php.dev bulk preset) | Installable/updatable host toolchain on `app-dev` and `app-prod` | `runtime` | install, update, probe, adopt |
| [`gh`](catalog/gh.md) | GitHub CLI | system binary | Role baseline tool for the `app-dev` and `app-prod` roles (repository cloning and deployment) | `runtime` | update, adopt |
| [`composer`](catalog/composer.md) | Composer | host binary (`/usr/local/bin/composer`) | Installable/updatable host toolchain on `app-dev` and `app-prod` | `runtime` | install, update, adopt |
| [`laravel-installer`](catalog/laravel-installer.md) | Laravel Installer | Composer global package (`laravel/installer`) | Installable/updatable/removable host toolchain on `app-dev` only | `runtime` | install, update, remove, adopt |
| [`dns`](catalog/dns.md) | DNS | Docker service | Required infrastructure tool, adopted and kept converged | `infrastructure` | process-backed lifecycle during migration, update, logs, fix, adopt |
| [`php`](catalog/php.md) | PHP images | FrankenPHP Docker image capability | Selected by app/workspace runtime configuration | `runtime` | image inventory, update, fix, adopt |
| [`postgres`](catalog/postgres.md) | PostgreSQL | Docker service | Installable and removable by Orbit | `database` | install, remove, process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |
| [`mysql`](catalog/mysql.md) | MySQL | Docker service | Installable and removable by Orbit | `database` | install, remove, process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |
| [`redis`](catalog/redis.md) | Redis | Docker service | Installable and removable by Orbit | `cache` | install, remove, process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |
| [`mailpit`](catalog/mailpit.md) | Mailpit | Docker service | Installable and removable by Orbit | `development` | install, remove, process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |
| [`reverb`](catalog/reverb.md) | Reverb | Docker service | Compatibility tool; superseded by the `websocket` role for fleet realtime | `communication` | install, remove, process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |
| [`rustfs`](catalog/rustfs.md) | RustFS | Docker runtime container | Role baseline tool for the `s3` role | `storage` | process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |
| [`polyscope-server`](catalog/polyscope-server.md) | PolyScope Server | Node-owned `systemd` process with `tool=polyscope` | Installable and removable by Orbit | `development` | install, remove, reconfigure, update, fix, adopt; lifecycle and logs through `process:*` |
| [`opencode-server`](catalog/opencode-server.md) | OpenCode Server | Node-owned `systemd` process with `tool=opencode` | Installable and removable by Orbit | `development` | install, remove, reconfigure, password reset, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`openclaw`](catalog/openclaw.md) | OpenClaw | Docker-managed runtime as `agent` | Installable and removable by Orbit | `agent` | install, remove, process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |
| [`hermes`](catalog/hermes.md) | Hermes | Docker-managed runtime as `agent` | Installable and removable by Orbit | `agent` | install, remove, process-backed lifecycle during migration, update, logs, credentials, service endpoint, fix, adopt |

Required baseline tools are expected to exist as part of node provisioning or
host bootstrap. `tool:install` does not create those tools from scratch unless
a future tool definition explicitly changes their support model. Role baseline
service tools, such as `rustfs`, are materialized by their owning role and may
only be installed or reconverged through `tool:*` when the target node already
has the required active role. Installable tools are provisioned by
`tool:install`, removed by `tool:remove`, and verified by
`doctor --family=tool`.

The `dns` tool is the runtime capability behind the VPN-facing DNS substrate;
its container, port, and config lifecycle are verified by `doctor --family=tool`.
Development/agent DNS mapping records — which TLD points at which WireGuard
IP — are owned by the node family. Stable private `.orbit` service names and
private route selection are router-owned service contracts. The `dns:*` command
family owns only caller-local resolver overrides on operator machines. See
[Architecture: DNS responsibilities](../../architecture.md#dns-responsibilities)
for the full split.

## Tool JSON Entity

Tool-family JSON renderers that return one tool entity embed this shape under
`success.data.tool`, or directly under `success.data.tools[]` for list items.
Command-specific outcome fields, log frames, or credential fields live beside
the entity in the command result.

```json
{
  "name": "redis",
  "instance": "default",
  "node": "app-1",
  "expected_state": "running",
  "observed_state": "running",
  "version_family": "7",
  "version": "7.2",
  "runtime": "docker",
  "managed": true,
  "endpoints": [
    {
      "name": "redis",
      "kind": "tcp",
      "host": "10.6.0.12",
      "port": 6379
    }
  ]
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Tool identity in Orbit's tool catalog. |
| `instance` | string | Tool instance identity on the node. Single-instance tools use `default`. |
| `node` | string | Node slug where the tool is expected. |
| `expected_state` | string | Gateway-owned intended capability state, such as `installed`, `available`, or `absent`. During migration, some managed service tools may still expose transitional running-state compatibility here. |
| `observed_state` | string \| null | Last known or live observed state when the command includes it. Registry reads may return `null`. |
| `version_family` | string \| null | Intended major or channel line when the tool definition tracks version families. |
| `version` | string \| null | Intended or observed version when the tool definition tracks versions. |
| `runtime` | string \| null | Stored runtime family for this installed instance, such as `docker` or `docker-swarm`. |
| `managed` | boolean | Whether Orbit owns lifecycle/configuration for this tool on the node. |
| `endpoints` | array | Non-secret service endpoint metadata declared by the tool definition. Omit or return an empty array when the tool declares no service endpoint. |

## Commands

The `tool:*` family covers inventory, capability state, configuration, and
credential operations. Lifecycle commands listed below are transitional
compatibility surfaces: the long-term lifecycle owner is the related process
record.

### Inventory and inspection

These commands list catalogued tools and show per-tool state for a node.

1. [`orbit tool:list`](1_tool-list/tool-list.md)
2. [`orbit tool:show <tool>`](2_tool-show/tool-show.md)

### Provisioning

These commands create or remove an installable tool on a node.

3. [`orbit tool:install <tool>`](3_tool-install/tool-install.md)
4. [`orbit tool:remove <tool>`](4_tool-remove/tool-remove.md)

### Lifecycle

These commands run, stop, restart, or inspect logs through a related managed
process when one exists. They remain in the docs as compatibility surfaces while
managed service tools migrate to process-backed lifecycle.

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
