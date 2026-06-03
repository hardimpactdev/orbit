# Tool Concepts

This document defines tool-family vocabulary and invariants. It supports the
tool command contracts and the [tool doctor](tool-doctor.md); it does not
override the [Architecture](../../architecture.md).

## Identity

These terms define the core vocabulary used across tool command contracts and the tool doctor.

- **Tool:** Orbit product concept for a node capability Orbit installs,
  configures, observes, or keeps converged.
- **Tool catalog:** The set of supported tool slugs documented in
  [`catalog/`](catalog/README.md). Tool slugs outside the catalog are
  unsupported and fail validation.
- **Tool definition:** Per-tool catalog entry that declares the tool's support
  model, role and platform eligibility, managed artifacts, service endpoints,
  credential behavior, and supported tool actions.
- **Tool runtime family:** Catalog-declared backend family such as `docker` or
  `docker-swarm`. The runtime family plus `Node.platform` resolves the concrete
  implementation used on a node.
- **Tool version family:** Catalog-declared major or channel line, such as
  `7`, `8`, or `latest`. A specific version belongs to exactly one version
  family when the tool tracks versions.
- **Tool instance:** One installed version-family/runtime realization of a tool
  on a node. Single-instance tools use the stable instance id `default`;
  multi-instance tools require an explicit instance id when a command cannot
  infer the target.
- **Tool category:** Catalog-declared classification for a tool, such as
  `always`, `runtime`, `database`, `cache`, `development`, `communication`,
  `infrastructure`, `storage`, or `agent`. Used by authorization and routing
  rules.
- **Storage tool category:** Tool category `storage`. Tools in this category
  back object-storage services owned by a role and expose service credentials
  and WireGuard-private endpoints through their catalog entry.
- **Agent tool category:** Tool category `agent`. Tools in this category are
  first-party autonomous agent runtimes (`openclaw`, `hermes`) that require
  the `agent` node role and run as the shared unprivileged
  `agent` user.
- **Tool row:** Gateway-owned record of expected state for one tool on one
  node and instance, including expected lifecycle state, version family,
  expected version when tracked, runtime family, install paths, and probe and
  repair settings.

## Support Models

These terms describe how Orbit relates to each tool in the catalog.

- **Required baseline tool:** Catalog tool expected to exist as part of node
  provisioning or host bootstrap. Adopted and kept converged; not provisioned
  from scratch by `tool:install`.
- **Installable tool:** Catalog tool that Orbit can provision through
  `tool:install` and remove through `tool:remove`.
- **Managed tool:** Tool whose lifecycle and configuration Orbit owns on the
  node. Surfaces as `managed=true` on the tool entity.
- **Observational tool:** Tool whose presence and state Orbit observes but does
  not own.
- **Role baseline tool:** Tool materialized as a tool row during node
  provisioning so doctor has one gateway-owned source of truth per node.
- **Agent tool:** Installable tool in the `agent` category. Requires the
  `agent` role on the node, runs as the shared unprivileged
  `agent` user, and uses the backend declared by its catalog entry.
- **Agent tool internal route:** Tool-owned proxy route under the agent
  role TLD, such as `https://openclaw.agent`. Reachable only over the
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
  managed service tools. The default service username is `orbit` when the
  protocol has a username concept.

## Boundaries

These rules define what tool commands may and may not change.

- **Tool-family boundaries:** Tool commands own tool configuration, tool lifecycle,
  declared service endpoints, and tool catalog membership.
  They do not own apps, workspaces, processes, schedules, custom proxy routes,
  or non-tool firewall policy. Tool-specific or capability-specific command
  families (such as `php:*`) are admitted only when the workflow is clearer as
  its own product surface.
