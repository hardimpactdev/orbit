# Tool Commands

Tool commands manage node capabilities Orbit installs, updates, adopts,
removes, configures, observes, and keeps available for runtime units. A tool is
not itself the lifecycle-managed runnable unit; processes own lifecycle.

## Domain Rules

These rules govern what the tool command family owns and what it may not touch.

- The tool command family owns the `tool:*` command prefix.
- `tool` is a state family. A gateway tool row is the expected state for one
  installed or observed capability on one node.
- Tool rows include the node, tool name, expected capability state, expected
  version or config when the tool definition tracks them, install paths, and
  backend-specific probe and repair settings.
- CLI callers resolve input locally, then the gateway reads or writes configuration and
  performs node inspection or applies changes.
- Some tools are observational, while others are managed by Orbit.
- Tool reads use gateway-tracked configuration by default. Live node status is
  included only when a command explicitly requests live inspection or when
  doctor runs.
- Tool definitions may declare capability versions, install scripts,
  reconfiguration scripts, credentials, probe metadata, supported operating
  systems, and repair commands.
- `tool:update` changes expected version configuration or updates a managed
  host capability. It does not change process runtime, process version, or any
  runnable service instance.
- `tool:reconfigure` reruns a managed tool's configuration/setup flow without
  changing the intended version.
- Tools observed on a node without a gateway tool row are unmanaged inventory,
  not drift, unless explicit `doctor --family=tool --adopt` semantics are used.
- Role baseline tools are materialized as tool rows during node provisioning, so
  doctor has one gateway-owned source of truth per node.
- Node reality import is not part of the tool command surface. If an adoption
  flow needs to adopt node reality, it must use explicit
  `doctor --family=tool --adopt` semantics.
- Credential-bearing managed capabilities use Orbit-owned generated secrets.
- Tool definitions may declare tool-owned service endpoints for capability UIs.
  HTTP and WebSocket tool endpoints are represented as tool-owned `proxy`
  routes. TCP endpoints for runnable services such as databases and caches
  belong to process definitions, not tool rows.
- Tools supply capabilities that other domains depend on, but they do not own
  apps, workspaces, processes, schedules, custom proxy routes, or non-tool
  firewall policy.
- Tools do not own start, stop, restart, or log lifecycle directly. Processes
  are the lifecycle-managed long-running units. A process may reference a tool
  with a tool dependency when it needs that node-level capability. A tool
  definition may declare a related singleton process; `tool:install` configures
  that process by default (idempotently) so installing the capability yields a
  running service, while lifecycle stays process-owned. `--no-process` installs
  the capability only.
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
  `s3:*` family; SeaweedFS runtime lifecycle and logs remain under `process:*`,
  while the `seaweedfs` tool row, credentials, and inventory remain under
  `tool:*`.

## Supported Tool Catalog

Orbit supports only the catalogued tool slugs below. A syntactically valid tool
name that is not present in this catalog fails as an unsupported tool. Detailed
tool-specific contracts live in [`catalog/`](catalog/README.md).

| Slug | Label | Backend | Support model | Category | Primary capability surface |
| --- | --- | --- | --- | --- | --- |
| [`caddy`](catalog/caddy.md) | Caddy | `orbit-caddy` Docker container | Role baseline where HTTP routing is needed, adopted and kept converged | `always` | reconfigure, update, fix, adopt; lifecycle and logs through `process:*` |
| [`docker`](catalog/docker.md) | Docker | system service | Required baseline, adopted and kept converged | `always` | probe, fix, adopt, prerequisite for Docker-backed processes |
| [`viteplus`](catalog/viteplus.md) | VitePlus | system binary | Role baseline tool for the `app-dev` and `app-prod` roles | `runtime` | probe, adopt |
| [`php-cli`](catalog/php-cli.md) | PHP CLI | prebuilt static host binaries (dl.static-php.dev bulk preset) | Installable/updatable host toolchain on `app-dev` and `app-prod` | `runtime` | install, update, probe, adopt |
| [`gh`](catalog/gh.md) | GitHub CLI | system binary | Role baseline tool for the `app-dev` and `app-prod` roles (repository cloning and deployment) | `runtime` | update, adopt |
| [`composer`](catalog/composer.md) | Composer | host binary (`/usr/local/bin/composer`) | Installable/updatable host toolchain on `app-dev` and `app-prod` | `runtime` | install, update, adopt |
| [`laravel-installer`](catalog/laravel-installer.md) | Laravel Installer | Composer global package (`laravel/installer`) | Installable/updatable/removable host toolchain on `app-dev` only | `runtime` | install, update, remove, adopt |
| [`claude-code`](catalog/claude-code.md) | Claude Code | Anthropic native installer (`https://claude.ai/install.sh`) | Installable runtime CLI on authorized active non-gateway Linux nodes; no required node role | `runtime` | install |
| [`dns`](catalog/dns.md) | DNS | Docker service | Required infrastructure tool, adopted and kept converged | `infrastructure` | update, fix, adopt; lifecycle and logs through `process:*` |
| [`php`](catalog/php.md) | PHP images | FrankenPHP Docker image capability | Selected by app/workspace runtime configuration | `runtime` | image inventory, update, fix, adopt |
| [`mailpit`](catalog/mailpit.md) | Mailpit | Docker service | Installable and removable by Orbit | `development` | install, remove, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`reverb`](catalog/reverb.md) | Reverb | Docker service | Compatibility tool; the `websocket` role is the current choice for fleet realtime | `communication` | install, remove, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`seaweedfs`](catalog/seaweedfs.md) | SeaweedFS | Docker runtime container | Role baseline tool for the `s3` role | `storage` | update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`node-exporter`](catalog/node-exporter.md) | node-exporter | host binary (`/usr/local/bin/node_exporter`) | Role baseline tool for the `metrics` role and active workload nodes scraped by metrics | `observability` | install, remove, update, fix, adopt; lifecycle and logs through `process:*` |
| [`polyscope-server`](catalog/polyscope-server.md) | PolyScope Server | Node-owned `systemd` process with `tool=polyscope` | Installable and removable by Orbit | `development` | install, remove, reconfigure, update, fix, adopt; lifecycle and logs through `process:*` |
| [`opencode-server`](catalog/opencode-server.md) | OpenCode Server | Node-owned `systemd` process with `tool=opencode` | Installable and removable by Orbit | `development` | install, remove, reconfigure, password reset, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`openclaw`](catalog/openclaw.md) | OpenClaw | Docker-managed runtime as `agent` | Installable and removable by Orbit | `agent` | install, remove, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`hermes`](catalog/hermes.md) | Hermes | Docker-managed runtime as `agent` | Installable and removable by Orbit | `agent` | install, remove, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
<<<<<<< HEAD
| [`codex-app`](catalog/codex-app.md) | Codex App | macOS Codex App configuration file and URL callback | App-facing project-registration bridge for Codex App on macOS | `operator` | `codex:app` add, remove, list; config presence probe |
=======
| [`claude-code`](catalog/claude-code.md) | Claude Code | Anthropic native installer | Installable by Orbit for node default and additional OS users | `runtime` | install |
| [`codex-app`](catalog/codex-app.md) | Codex App | macOS Codex App configuration file and URL callback | App-facing project-registration bridge for Codex App on macOS | `operator` | `app:codex` add, remove, list; config presence probe |
>>>>>>> e912559130202903e699b9832952a1188e284244

Required baseline tools are expected to exist as part of node provisioning or
host bootstrap. `tool:install` does not create those tools from scratch unless
a future tool definition explicitly changes their support model. Role baseline
tools, such as `seaweedfs`, are installed automatically by their owning role.
User-directed `tool:*` commands with an explicit target may target an active
visible node unless that node is the gateway. The selected tool definition must
support the target node operating system; role membership is not the general
eligibility gate.
Installable tools are provisioned by `tool:install`, removed by `tool:remove`,
and verified by `doctor --family=tool`.

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
  "name": "composer",
  "node": "app-1",
  "expected_state": "installed",
  "observed_state": null,
  "version": "2.9.2",
  "managed": true,
  "endpoints": []
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Tool identity in Orbit's tool catalog. |
| `node` | string | Node slug where the tool is expected. |
| `expected_state` | string | Gateway-owned intended capability state, such as `installed`, `available`, or `absent`. |
| `observed_state` | string \| null | Last known or live observed state when the command includes it. Registry reads may return `null`. |
| `version` | string \| null | Intended or observed version when the tool definition tracks versions. |
| `managed` | boolean | Whether Orbit owns capability configuration for this tool on the node. |
| `endpoints` | array | Non-secret endpoint metadata declared by the tool definition. Omit or return an empty array when the tool declares no endpoint. |

## Commands

The `tool:*` family covers inventory, capability state, configuration, and
credential operations. Lifecycle is process-owned: `process:*` commands
start, stop, restart, and stream logs for tool-backed services through the
related process record.

### Inventory and inspection

These commands list catalogued tools and show per-tool state for a node.

1. [`orbit tool:list`](1_tool-list/tool-list.md)
2. [`orbit tool:show <tool>`](2_tool-show/tool-show.md)

### Provisioning

These commands create or remove an installable tool on a node.

3. [`orbit tool:install <tool>`](3_tool-install/tool-install.md)
4. [`orbit tool:remove <tool>`](4_tool-remove/tool-remove.md)

### Configuration and credentials

Lifecycle (start, stop, restart, logs) belongs to the `process:*` family; tools
do not own runtime lifecycle. These commands change tool versions or
configuration and expose generated credentials.

5. [`orbit tool:update [tool]`](9_tool-update/tool-update.md)
6. [`orbit tool:credentials [tool]`](10_tool-credentials/tool-credentials.md)
7. [`orbit tool:reconfigure [tool]`](12_tool-reconfigure/tool-reconfigure.md)

## Related

- [`doctor --family=tool`](tool-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
- [`orbit firewall:*`](../4_firewall/README.md)
