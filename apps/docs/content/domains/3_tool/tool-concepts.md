# Tool Concepts

This document defines tool-family vocabulary and invariants. It supports the
tool command contracts and the [tool doctor](tool-doctor.md); it does not
override the [Architecture](../../architecture.md).

## Identity

These terms define the core vocabulary used across tool command contracts and the tool doctor.

- **Tool:** Orbit product concept for a node capability Orbit installs,
  updates, adopts, removes, observes, or keeps available for other runtime
  units. A tool row is not itself a runtime unit, but a definition may expose
  lifecycle or logs capabilities when it maps to exactly one direct runtime.
- **Tool process dependency:** Optional relationship from a process to the
  tool capability it uses, such as `opencode-cli`, `viteplus`, or `php-cli`.
  The process owns lifecycle; the tool supplies the capability. A tool
  definition may also declare a related singleton process (runtime, command,
  and `--tool` dependency); `tool:install` configures that process by default so
  installing the capability yields a running service. The process row remains
  the runtime owner; declared `tool:start|stop|restart|logs` verbs may address
  that one process without becoming a generic compatibility adapter.
  `--no-process` installs the capability only.
- **Tool catalog:** The set of supported tool slugs documented in
  [`catalog/`](catalog/README.md). Tool slugs outside the catalog are
  unsupported and fail validation.
- **Tool definition:** Per-tool catalog entry that declares the tool's support
  model, supported operating systems, runtime user, route/TLD requirement,
  isolation model, gateway-local constraint, managed artifacts, service
  endpoints, credential behavior, and supported tool actions. Tools installed
  automatically by roles may also declare bootstrap-role metadata; bootstrap
  metadata is not a user-directed role gate.
- Service process definitions and concrete runtime state belong to the process
  family. Tool-owned capability metadata, service endpoints, and credentials
  remain tool facts when declared. A lifecycle/log command may address one
  exact tool-owned runtime or one exact process row only when the tool declares
  that verb; zero or multiple runtime matches fail explicitly.
- **Tool category:** Catalog-declared classification for a tool, such as
  `always`, `runtime`, `database`, `cache`, `development`, `communication`,
  `infrastructure`, `storage`, `observability`, `agent`, or `operator`. Used
  for catalog grouping and category-specific behavior when that category
  declares it.
- **Storage tool category:** Tool category `storage`. Tools in this category
  back object-storage services owned by a role and expose service credentials
  and WireGuard-private endpoints through their catalog entry.
- **Agent tool category:** Tool category `agent`. Tools in this category are
  first-party autonomous agent runtimes that may have agent-user,
  agent-route, and agent-credential behavior.
- **Operator tool category:** Tool category `operator`. Tools in this category
  configure local applications used by operators. The category is UX/catalog
  metadata, not a node-role constraint.
- **Tool row:** Gateway-owned record of expected state for one tool on one
  node, including expected capability state, expected version when tracked,
  install paths, and probe and repair settings.

## Support Models

These terms describe how Orbit relates to each tool in the catalog.

- **Required baseline tool:** Catalog tool expected to exist as part of node
  provisioning or host bootstrap. Adopted and kept converged; not provisioned
  from scratch by `tool:install`.
- **Installable tool:** Catalog tool that Orbit can provision through
  `tool:install` and remove through `tool:remove`.
- **Managed tool:** Tool whose capability state Orbit owns on the node,
  including installation, update, adoption, removal, and configuration when the
  tool definition supports those actions. Surfaces as `managed=true` on the
  tool entity.
- **Observational tool:** Tool whose presence and state Orbit observes but does
  not own.
- **Role baseline tool:** Tool materialized as a tool row during node
  provisioning so doctor has one gateway-owned source of truth per node. Role
  baseline metadata controls automatic provisioning, not the general explicit
  target eligibility gate for user-directed `tool:*` commands.
- **Supported operating systems:** Tool-definition metadata that limits which
  node platforms may run a tool. Explicit `--node` tool commands may target any
  active visible non-gateway node when the selected tool supports that node's
  operating system.
- **Agent tool:** Installable tool in the `agent` category. Requires the
  selected node's operating system to be supported, runs as the shared
  unprivileged `agent` user when the backend uses an agent runtime, and uses
  the backend declared by its catalog entry.
- **Agent tool internal route:** Tool-owned proxy route under the agent node's
  node-owned TLD, such as `https://hermes.agent`. Reachable only over the
  Orbit/WireGuard network.
- **Agent tool credentials:** Web UI access metadata returned by
  `tool:credentials` for agent tools. Reading agent tool credentials
  requires the explicit `tool:credentials` permission; the default agent
  self-grant does not include that permission.
- **Multi-agent-tool warning:** Warning emitted when more than one agent
  tool is installed or running on the same node. Interactive callers
  confirm; non-interactive callers receive
  `tool.multiple_agent_tools_running` under `success.meta.warnings[]` and
  the command proceeds.
- **Unmanaged inventory:** Tools observed on a node without a gateway tool
  row. Not drift unless adopted through `doctor --family=tool --adopt`.

## Service Surfaces

These terms describe the network surfaces a tool may declare.

- **Tool-owned service endpoint:** Service endpoint declared by a tool
  definition that does not expose secret values. HTTP and WebSocket endpoints
  surface as tool-owned `proxy` routes; TCP service endpoints are
  WireGuard-only host/port records that use the serving node's WireGuard
  service address.
- **Tool credentials:** Orbit-owned generated secrets for credential-bearing
  tools. Credentials for runnable service instances belong to the owning
  process definition or process runtime configuration.
- **DNS tool capability:** The gateway-local `dns` tool that owns the
  `orbit-dns` runtime, port-53 listener, VPN forwarding, client-DNS settings,
  and base `dnsmasq.conf`. The `vpn` role requires this capability; it does not
  create a `dns` role.
- **DNS base configuration:** Tool-owned `dnsmasq.conf` containing resolver
  policy and explicit includes for the node-owned
  `dnsmasq.d/10-node-records.conf` and proxy-owned
  `dnsmasq.d/20-proxy-records.conf`. It does not contain family-owned records.
- **DNS record projection boundary:** The DNS tool serves the node and proxy
  record projections but does not own their content. Node and proxy doctor
  compare and restore their respective files.
- **Shared DNS materializer and reload:** Ownership-neutral infrastructure that
  atomically publishes any family-owned DNS artifact and reloads or restarts
  the runtime. Calling it does not move artifact ownership to the tool family.

## Node Execution

Catalog install, update, remove, reconfigure, and direct tool-owned runtime
scripts dispatch from the gateway through the typed
`internal:tool:run-script` command over agent-push. Process-backed lifecycle
and logs use the process action path instead. The gateway builds
operation-token-bound JSON stdin that
includes the tool slug, action, and rendered script text; secret values such as
GitHub tokens stage separately through `internal:secret-file` and reach the
script only as a staged token-file path in the rendered config.

The local executor runs declared catalog scripts explicitly with Bash from its
fixed working directory. It uses a non-login shell so profile or logout hooks
cannot replace the script's real exit status.

## Boundaries

These rules define what tool commands may and may not change.

- **Tool-family boundaries:** Tool commands own capability inventory,
  installation, update, adoption, removal, configuration, declared service
  endpoints, tool catalog membership, and explicit capability-declared runtime
  actions. `tool:start`, `tool:stop`, `tool:restart`, `tool:reload`, and
  `tool:logs` are available only when the definition declares the verb and the
  tool maps to exactly one runtime: either one direct tool-owned runtime or one
  process row whose `tool` equals the canonical slug. Ambiguity fails with
  `tool.runtime_ambiguous`; no generic related-process fallback is permitted.
- They do not own projects, instances, workspaces, process lifecycle, schedules, custom proxy
  routes, or non-tool firewall policy. Tool-specific or capability-specific
  command families (such as `php:*`) are admitted only when the workflow is
  clearer as its own product surface.
- The DNS tool owns only base configuration and runtime capability facts. It
  does not own node or proxy record projections, and Orbit has no DNS state
  family.
