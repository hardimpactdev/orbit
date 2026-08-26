# Tool Commands

Tool commands manage node capabilities Orbit installs, updates, adopts,
removes, configures, observes, and keeps available for runtime units. Processes
remain the canonical lifecycle owner for process rows. A tool may expose an
explicit lifecycle, reload, or logs verb only when its catalog definition
declares that capability and runtime resolution produces exactly one target.

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
  apps, instances, workspaces, processes, schedules, custom proxy routes, or non-tool
  firewall policy.
- Processes are the lifecycle-managed long-running units. A process may
  reference a tool with a canonical `tool` dependency when it needs that
  capability. A tool definition may declare a related singleton process;
  `tool:install` configures that process by default (idempotently), while that
  process row remains the runtime owner. `--no-process` installs the capability
  only.
- `tool:start`, `tool:stop`, `tool:restart`, `tool:reload`, and `tool:logs` are
  admitted independently and only when the tool definition declares the exact
  verb. Each action must resolve to exactly one direct tool-owned runtime or
  exactly one process row whose canonical `tool` value matches the selected
  tool. A missing or ambiguous runtime fails explicitly. Orbit does not infer
  support from similar names.
- Direct tool-owned runtime commands use Agent push for remote nodes. The
  gateway-local DNS runtime is the deliberate local-execution exception.
  Process-backed commands dispatch through the owning `process:*` action.
- Tool-specific and capability-specific command families are opt-in product
  surfaces. The tool catalog does not automatically create `valkey:*`,
  `mysql:*`, `postgres:*`, or other top-level command families. Generic tool
  lifecycle and inventory remain under `tool:*`; separate command families are
  admitted only when the workflow is clearer as its own Orbit product surface.
  PHP runtime selection is admitted as `php:*`. Valkey-native data-plane
  operations may use `valkey:*`. Database connection inventory, env convergence,
  schema inspection, audited SQL execution, and backup/restore where
  applicable belong to the `database:*` family, not per-database command
  families. S3 publication and service credential workflows belong to the
  `s3:*` family; SeaweedFS runtime lifecycle and logs remain under `process:*`,
  while the `seaweedfs` tool row, credentials, and inventory remain under
  `tool:*`.

## Supported Tool Catalog

Orbit supports only the catalogued tool slugs below. A syntactically valid tool
name that is not present in this catalog fails as an unsupported tool. The only
exception is a removal-only residual slug that `tool:remove` still accepts.
The closed residual set is listed only on the public `tool:remove` contract;
those slugs are not catalog members and have no install or update surface.
Detailed tool-specific contracts live in [`catalog/`](catalog/README.md).

| Slug | Label | Backend | Support model | Category | Primary capability surface |
| --- | --- | --- | --- | --- | --- |
| [`caddy`](catalog/caddy.md) | Caddy | `orbit-caddy` Docker container | Role baseline where HTTP routing is needed, adopted and kept converged | `always` | reconfigure, update, fix, adopt, reload, logs |
| [`docker`](catalog/docker.md) | Docker | system service on Linux; Docker-compatible provider on macOS | Required baseline, adopted and kept converged | `always` | probe, fix, adopt, prerequisite for Docker-backed processes |
| [`viteplus`](catalog/viteplus.md) | VitePlus | managed-user Vite+ runtime with stable host shims | Required baseline on `app-dev` and `app-prod` | `runtime` | install, update, remove, probe, adopt |
| [`php-cli`](catalog/php-cli.md) | PHP CLI | Orbit-owned static host binaries (`coverage` on app-dev, `standard` on app-prod) | Installable/updatable/removable host toolchain on `app-dev` and `app-prod` | `runtime` | install, remove, update, probe, adopt |
| [`git`](catalog/git.md) | Git | system binary | Role baseline tool for the `app-dev`, `app-prod`, and `agent` roles (repository clone and checkout workflows) | `runtime` | install, update, adopt |
| [`gh`](catalog/gh.md) | GitHub CLI | system binary | Role baseline tool for the `app-dev` and `app-prod` roles (repository cloning and deployment) | `runtime` | update, adopt |
| [`composer`](catalog/composer.md) | Composer | host binary (`/usr/local/bin/composer`) | Installable/updatable host toolchain on `app-dev` and `app-prod` | `runtime` | install, update, adopt |
| [`bun`](catalog/bun.md) | Bun | managed-user binary (`/usr/local/bin/bun`) | Installable/updatable/removable host toolchain on `app-dev` and `app-prod` | `runtime` | install, update, remove, adopt |
| [`laravel-installer`](catalog/laravel-installer.md) | Laravel Installer | Composer global package (`laravel/installer`) | Installable/updatable/removable host toolchain on `app-dev` only | `runtime` | install, update, remove, adopt |
| [`claude-code`](catalog/claude-code.md) | Claude Code | Anthropic native installer (`https://claude.ai/install.sh`) | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes; no required node role | `runtime` | install |
| [`codex-cli`](catalog/codex-cli.md) | Codex CLI | OpenAI standalone installer (`https://chatgpt.com/codex/install.sh`) | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes | `runtime` | install, update, adopt; auth/session state is outside Orbit ownership |
| [`grok-cli`](catalog/grok-cli.md) | Grok CLI | xAI Grok Build installer (`https://x.ai/cli/install.sh`) | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes | `runtime` | install, update, adopt; auth/session state is outside Orbit ownership |
| [`antigravity-cli`](catalog/antigravity-cli.md) | Antigravity CLI | Google Antigravity installer (`https://antigravity.google/cli/install.sh`) | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes; supersedes outdated Gemini CLI support | `runtime` | install, update, adopt; auth/session state is outside Orbit ownership |
| [`cursor-cli`](catalog/cursor-cli.md) | Cursor CLI | Cursor Agent installer (`https://cursor.com/install`) | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes | `runtime` | install, update, adopt; auth/session state is outside Orbit ownership |
| [`dns`](catalog/dns.md) | DNS | Gateway-local Docker service | Required infrastructure tool, kept converged on the gateway | `infrastructure` | update, restore, restart, logs; no adopt/start/stop/reload |
| [`php`](catalog/php.md) | PHP images | FrankenPHP Docker image capability | Selected by app/workspace runtime configuration | `runtime` | image inventory, update, remove (registry only), fix, adopt |
| [`mailpit`](catalog/mailpit.md) | Mailpit | Docker service | Installable and removable by Orbit | `development` | install, remove, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`seaweedfs`](catalog/seaweedfs.md) | SeaweedFS | Docker runtime container | Role baseline tool for the `s3` role | `storage` | credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`node-exporter`](catalog/node-exporter.md) | node-exporter | host binary (`/usr/local/bin/node_exporter`) | Role baseline tool for the `metrics` role and active Ubuntu workload nodes scraped by metrics | `observability` | install, remove, update, fix, adopt; lifecycle and logs through `process:*` |
| [`hermes`](catalog/hermes.md) | Hermes | Host-managed runtime as `agent` with default web port `8080` | Installable and removable by Orbit | `agent` | install, remove, update, credentials, service endpoint, fix, adopt; lifecycle and logs through `process:*` |
| [`codex-app`](catalog/codex-app.md) | Codex App | macOS Codex App configuration file and URL callback | App-facing project-registration bridge for Codex App on macOS | `operator` | `codex:app` add, remove, list; config presence probe |
| [`orbstack`](catalog/orbstack.md) | OrbStack | macOS application and CLI (`orb`, `orbctl`) | Installable macOS runtime-provider capability | `infrastructure` | install, update, probe, safe adopt, start, stop, restart |

Required baseline tools are expected to exist as part of node provisioning or
host bootstrap. `tool:install` creates those tools from scratch only when the
selected tool definition explicitly supports install. Role baseline tools, such
as `git` and `seaweedfs`, are installed automatically by their owning role.
User-directed `tool:*` commands with an explicit target may target an active
visible node unless that node is the gateway. The selected tool definition must
support the target node operating system; role membership is not the general
eligibility gate.
Installable tools are provisioned by `tool:install`, removed by `tool:remove`,
and verified by `doctor --family=tool`.

The `dns` tool is the gateway-local runtime capability required by the `vpn`
role; it owns base `dnsmasq.conf`, its container/service, listener, VPN
forwarding, and client-DNS settings. The node family owns
`dnsmasq.d/10-node-records.conf`; the proxy family owns
`dnsmasq.d/20-proxy-records.conf`. The shared materializer and reload path is
ownership-neutral. The `dns:*` command family owns only caller-local resolver
overrides on operator machines. Orbit has no `dns` role or DNS state family. See
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
  "observed_version": null,
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
| `version` | string \| null | Gateway-owned intended or registry-known version when the tool definition tracks versions. |
| `observed_version` | string \| null | Live observed version when a command performs an explicit probe; otherwise `null`. The key remains present in canonical tool entities. |
| `managed` | boolean | Whether Orbit owns capability configuration for this tool on the node. |
| `endpoints` | array | Non-secret endpoint metadata. Autonomous-agent tools derive consumer HTTPS endpoints from catalog category + node TLD + proxy contract (for example `https://hermes.agent`) instead of requiring persisted endpoint copies on the tool row. Canonical agent endpoint objects use `{name, kind, url, host, port, upstream_port}` where `url` is the operator-facing consumer address, `port` is public HTTPS `443`, and `upstream_port` is the proxy loopback listen port from the proxy contract. Other tools may still surface definition- or config-declared endpoints. Omit or return an empty array when the tool has no endpoint. |

## Commands

The `tool:*` family covers inventory, capability state, configuration,
credentials, and catalog-declared runtime actions. Process rows remain
process-owned even when a declared `tool:*` verb addresses the one exact
matching process.

### Inventory and inspection

These commands list catalogued tools and show per-tool state for a node.

1. [`orbit tool:list`](1_tool-list/tool-list.md)
2. [`orbit tool:show <tool>`](2_tool-show/tool-show.md)

### Provisioning

These commands create or remove an installable tool on a node.

3. [`orbit tool:install <tool>`](3_tool-install/tool-install.md)
4. [`orbit tool:remove <tool>`](4_tool-remove/tool-remove.md)

### Declared runtime actions

These commands are available only when the selected tool declares the exact
verb and runtime resolution is unambiguous.

5. [`orbit tool:start <tool>`](5_tool-start/tool-start.md)
6. [`orbit tool:stop <tool>`](6_tool-stop/tool-stop.md)
7. [`orbit tool:restart <tool>`](7_tool-restart/tool-restart.md)
8. [`orbit tool:logs <tool>`](8_tool-logs/tool-logs.md)

### Configuration and credentials

These commands change tool versions or configuration, expose generated
credentials, or invoke a declared reload capability.

9. [`orbit tool:update [tool]`](9_tool-update/tool-update.md)
10. [`orbit tool:credentials [tool]`](10_tool-credentials/tool-credentials.md)
11. [`orbit tool:reload <tool>`](11_tool-reload/tool-reload.md)
12. [`orbit tool:reconfigure [tool]`](12_tool-reconfigure/tool-reconfigure.md)

## Related

- [`doctor --family=tool`](tool-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
- [`orbit firewall:*`](../4_firewall/README.md)
