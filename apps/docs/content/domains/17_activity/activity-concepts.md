# Activity Concepts

This document defines the activity-command-domain vocabulary, the cross-cutting
logging contract, and the per-command requirements for emitting activity. It
supports the activity command contracts; it does not override the
[Architecture](../../architecture.md).

## Domain and scope

These terms define the scope of the activity command domain.

- **Activity command domain:** Cross-cutting command domain for surfacing
  gateway activity history (`activity:list`, `activity:show`) and for the
  doctrine every command and API endpoint follows when it emits an activity
  entry. It does not create a separate state family.
- **Gateway activity history:** Durable operational records stored in the
  gateway database. They capture past operations, not live state, and do not
  substitute for doctor probes.
- **Activity entry:** One gateway history record with stable fields:
  occurrence time, type, effect, subject, causer (actor), command,
  correlation id, description, structured properties, and channel. The same
  Activity DTO is used by `activity:list`, the selected `activity:show` entry,
  and every correlated entry returned by `activity:show`.

## Activity Model

Each activity entry carries the following fields.

- **Type:** Stable, human-readable action identifier. Conventionally
  `domain.verb` (`node.granted`, `app.created`, `proxy.route_stored`,
  `workspace.deleted`). Type is part of the command's contract; changes are
  doc edits.
- **Effect:** Read-vs-write classifier exposed to filters and renderers.
  One of:
  - `read` — non-mutating action.
  - `write` — mutates state, reversible or recoverable through normal
    operator workflows (creates, updates, additions).
  - `destructive` — irreversible mutation (deletes, removes, prunes,
    revokes). Recorded as a separate value so operators can filter
    `activity:list --effect=destructive` to audit irreversible actions
    without scanning all writes.

  Every Loggable implementation MUST declare its effect. The line between
  `write` and `destructive` is irreversibility through normal workflows,
  not data sensitivity: `app:create` is `write`; `node:remove`,
  `node:revoke`, `vpn-client:remove`, and deploy retention prune are
  `destructive`.
- **Subject:** Product-family entity the action targets, when there is one.
  Examples: the `Node` granted, the `App` deployed, the `Workspace` created,
  the `ProxyRoute` stored. `null` when the action has no single domain
  target.
- **Causer (actor):** Node identity that initiated the action, resolved from
  the WireGuard identity middleware on every gateway API call. The causer
  answers "who did this." For gateway-internal apply work that no operator
  initiated directly, causer is the gateway node identity. For work
  initiated by an autonomous agent tool running on an `agent` node, causer
  is the node identity. Orbit does not attribute activity to a
  per-tool sub-identity, because per-tool identities are spoofable
  without a stronger identity mechanism than the node handshake. The actor is
  serialized as exactly `{ node: <slug> }`.
- **Properties:** Structured audit fields declared by the command or
  controller. Properties never include secrets, raw command argv, raw
  request bodies, or full credentials. Property keys are declared per
  command in the command's technical contract.
- **Description:** Optional one-line human summary for human renderers.
  Renderers may fall back to type plus subject when description is absent.
- **Channel:** Origin of the entry. Currently `cli` (command-side emission)
  and `api` (gateway controller emission). Channel does not change which
  fields are required. The retained `remote_shell` and `local_executor`
  channel values can appear only on pre-existing rows requested through
  `--include-internal`. Rows with a missing or empty channel are normalized to
  `default` on read. Current code does not emit `remote_shell`,
  `local_executor`, or `default`. Pre-existing `security` host-key rows are
  normalized on read to channel `api` and effect `write`. Their stored key type
  moves to `properties.host_key_type`.

### Canonical Activity DTO

Every activity read surface returns these exact fields:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | integer | Stable gateway activity id. |
| `occurred_at` | string | ISO-8601 occurrence time. |
| `correlation_id` | string \| null | UUID shared by one correlated operation. |
| `type` | string | Stable action identifier. |
| `effect` | string | Top-level `read`, `write`, or `destructive` classifier. It is not nested under `properties`. |
| `subject` | object \| null | `{type, name}` subject reference when the activity has one. |
| `actor` | object \| null | Exactly `{node: <slug>}` when the gateway knows the initiating node. |
| `command` | string \| null | Orbit command name when one applies. |
| `description` | string \| null | Optional human-readable description. Automation must not parse it. |
| `properties` | object | Type-specific structured audit data after canonical top-level fields are removed. |
| `channel` | string | Current origin channel, `cli` or `api`; pre-existing internal rows can retain their stored channel, known host-key security rows normalize to `api`, and a missing channel reads as `default`. |

## Correlation

These terms describe how related activity entries are grouped.

- **Correlation id:** UUID metadata identifier that groups related activity
  entries from one operator-initiated operation. It does not collapse those
  entries into a single synthetic row.
- **Correlation generation:** The gateway middleware generates the
  correlation id when a request enters and tags every entry produced under
  that request scope, including any sub-actions the gateway initiates
  synchronously while handling the request. Operator causer plus correlation
  id together answer "which operator triggered which group of entries."
- **Optional CLI propagation:** The CLI may send `X-Orbit-Request-Id` to
  carry an existing correlation across multiple gateway calls in one
  command run. When the header is absent the gateway generates a fresh id.
  CLIs do not need to thread this header to satisfy the activity contract.
- **Correlation visibility:** `activity:show` returns other visible entries
  that share the requested entry's correlation id. Visibility filters apply
  per related entry; correlation does not bypass authorization.

## Loggable Contract

Every API controller and every CLI command participates in activity logging
through one shared contract.

- **Loggable interface:** Controllers and command handlers that emit
  activity declare:
  - `effect()`: `read`, `write`, or `destructive`.
  - `type()`: stable type string (e.g. `node.granted`).
  - `subject()`: the domain model targeted, or `null`.
  - `properties()`: structured array of declared audit fields, with no
    secrets and no raw request or command argv.
  - `description()`: optional one-line summary.
- **Loggable emission:** On the gateway, an HTTP middleware resolves the
  matched controller and, if it implements Loggable, records an entry after
  the response is produced (success or failure). The controller decides
  what to log; the middleware is the transport. Causer is resolved from
  the WireGuard identity middleware. On the CLI, a small command-side
  helper emits an entry through the gateway client when the command itself
  is the canonical actor (CLI paths that do not flow through a single
  matching API endpoint); causer is the local node identity.
- **Logging failure handling:** Activity write failures must not change
  command or API outcomes. Logging is a side channel; commands succeed or
  fail on their documented contracts, and activity emission errors are
  diagnostic.

## What is logged

Activity covers the following categories of operations.

- All gateway API endpoints that mutate state. Effect `write` for reversible
  mutations (creates, updates, additions, configuration changes); effect
  `destructive` for irreversible mutations (deletes, removes, revokes,
  retention prunes).
- All gateway API endpoints that read state (lists, shows, status). Effect
  `read`. Default-on for consistent activity visibility.
- CLI commands that perform CLI-only state changes (e.g. local gateway
  connection setup) emit through the CLI helper.
- Orbit Agent push operations are recorded through the existing gateway activity
  and operation history surfaces. The gateway records command dispatch, success,
  and failure as gateway history entries, not a separate Orbit Agent log product
  surface. Activity entries still follow the no raw command, no-secret
  properties rule.

Never logged:

- Secrets, credentials, passwords, tokens, raw certificates, raw private
  keys.
- Raw command argv beyond declared properties.
- Raw request or response bodies.
- Process logs, deploy log streams, or schedule log streams. Those have
  their own surfaces.

## Per-Command Requirements

Each ported command's technical contract must declare an **Activity Logging**
section with:

- `type`: stable type string.
- `effect`: `read`, `write`, or `destructive`.
- `subject`: declared subject model, or `none`.
- `properties`: declared property keys with one-line semantic notes. The
  keys are part of the command's contract; changes are doc edits.
- `description`: declared format, or `derived`.

Commands that explicitly do not emit an entry (e.g. local-only update of the
caller checkout pre-gateway) must declare `none` and the reason.

The gateway controller's matching technical contract carries the same
Activity Logging declaration. Command and controller declarations for the
same logical action must agree.

## Visibility and authorization

These rules govern which activity rows a caller may read.

- **Activity visibility:** Gateway-owned authorization filter that controls
  which activity rows and correlated entries a caller may read. Visibility
  is computed against the caller's WireGuard-resolved node identity.
- **Internal activity visibility:** Backend transport audit rows are durable
  but hidden from default `activity:list` output. Current Agent-push dispatch,
  success, and failure rows use channel `api`, types `agent_push.dispatching`
  and `agent_push.completed`, and properties `lane = internal` plus `transport
  = agent_push`. Bootstrap/provisioning SSH rows use channel `api`, types
  `ssh_bootstrap.run` or `ssh_bootstrap.start`, and `transport =
  ssh_bootstrap`.

  Default filtering uses the internal lane. It also recognizes pre-existing
  rows with `remote_shell` or `local_executor` channels and the
  `local-executor` lane, so those rows remain hidden. Operators inspect
  internal rows with
  `activity:list --include-internal` or the gateway `include_internal=true`
  query parameter. Internal rows still use the public effect vocabulary
  (`read`, `write`, `destructive`). Backend execution records a conservative
  top-level `write` effect because execution may mutate node state, without
  exposing raw scripts, stdin, or stdout. `remote_shell` is retained for read
  compatibility only; normal current node execution is Agent push, while SSH
  is restricted to the bootstrap/provisioning seam.
- **Filter denial versus empty:** When a caller filters by an entity it
  cannot see, the gateway returns an authorization failure rather than an
  empty result. This prevents leaking the existence of hidden activity
  through filter probing.

## Boundaries

These are the hard limits for the activity command domain.

- **Activity-domain boundaries:** Activity commands own the doctrine for
  emission, the durable read surface, and the correlation contract. They do
  not own product-family configuration, do not define drift, do not replace
  family doctor contracts, do not adopt reality, and do not grant or revoke
  node access. Activity success or failure is not proof of state-family
  convergence.
- **Activity is not metrics:** Activity is discrete operational events.
  Continuous performance, latency, or throughput data belongs to the
  profile command and the process manager, not to activity history.
- **Activity is not the live state:** Activity describes what happened.
  Doctor and family probes describe what is true now.
- **Agent activity attribution boundary:** Activity emitted while an
  autonomous agent tool is working on an `agent` node is attributed to
  the node identity. Orbit does not claim per-tool sub-identities,
  so a single causer covers every agent tool running on the node.
