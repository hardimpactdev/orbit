# Tool Concepts

This document defines tool-family vocabulary and invariants. It supports the
tool command contracts and the [tool doctor](tool-doctor.md); it does not
override the [Architecture](../../ARCHITECTURE.md).

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
- **Tool row:** Gateway-owned record of expected state for one tool on one
  node, including expected lifecycle state, expected version when tracked,
  install paths, and probe and repair settings.

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
- **Unmanaged inventory:** Tools observed on a node without a gateway tool
  row. Not drift unless adopted through `doctor --fix --family=tool --adopt`.

## Service Surfaces

These terms describe the network surfaces a tool may declare.

- **Tool-owned service endpoint:** Service endpoint declared by a tool definition that does not expose secret values. HTTP and WebSocket endpoints surface as tool-owned `proxy`
  routes; TCP service endpoints are WireGuard-only host/port records.
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
