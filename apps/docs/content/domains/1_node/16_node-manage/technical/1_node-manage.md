# Technical Contract: `orbit node:manage [--user=<user>] [--json]`

[Back to public `node:manage` documentation.](../node-manage.md)

**Owner:** `node`.

**Effects:** `local-write, write, remote-probe`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer is an active node with no active role assignments.
- The caller is running on the local machine represented by that node identity.
- The node has a recorded WireGuard address. Agent reachability is probed only
  after the selected user, platform, and managed intent are persisted; failure
  retains that intent for later Doctor convergence.

## Signature

```bash
orbit node:manage [--user=<user>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `user` | `--user` | Optional. | Never. | Current local user. | Must match the current local user in this implementation. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/me` | authenticated | Resolve the caller's node identity. |
| `POST` | `/api/nodes/self/manage` | authenticated roleless active node | Persist management metadata and verify Agent-push reachability. |

## Behavior Contract

### Local Setup Rules

- Resolve the current local Agent user before requesting gateway mutation.
- Detect the local platform using the same normalized family convention as
  gateway-managed nodes, such as `macos_15-5` or `ubuntu_24-04`.

### Gateway Management Rules

- Reject inactive nodes and nodes with any active role assignment before
  gateway side effects.
- Store the selected Agent user in `node.user`.
- Store the detected platform in `node.platform`.
- Use the node's `node.wireguard_address` for Agent push. Do not use public
  hostnames, local hostnames, TLDs, or host metadata for this path.
- Persist `node.managed=true`, then dispatch `internal:agent-runtime:probe`.
  If dispatch fails, retain the selected user, platform, and managed intent and
  report repairable Agent drift.

## Context Contracts

- [Client context](2_node-manage_on-client.md)

## Input Mode Contracts

- [Interactive input mode](5.1_node-manage_input-mode_interactive.md)
- [Non-interactive input mode](5.2_node-manage_input-mode_non-interactive.md)

### Scope Boundaries

`node:manage` must not:

- Add, remove, or mutate node roles.
- Create a persistent transport object. This command owns the explicit
  `managed=true` Agent opt-in for a roleless operator.
- Mint WireGuard identity material.
- Create node grants.
- Open public SSH or alter firewall policy.
- Register workspaces or Codex-managed worktrees.

## Renderer Contracts

- [Human renderer](6.1_node-manage_output-render_human.md)
- [JSON renderer](6.2_node-manage_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Not an operator node | The authenticated node is inactive or has an active role assignment. | `error.code=node.not_operator` |
| Missing WireGuard address | The node has no `wireguard_address` for Agent push. | `error.code=node.wireguard_address_missing` |
| Agent reachability failed | The gateway could not complete the typed Agent runtime probe. | `error.code=node.agent_unreachable`; management intent remains stored so node doctor can report repairable drift. |

## Doctor Relationship

`node:manage` performs the explicit managed-Agent opt-in and Agent-push
reachability check for a roleless operator node. Later drift in managed eligibility,
Agent expectation, user metadata, platform metadata, or reachability belongs to
[`doctor --family=node`](../../node-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `node.managed` |
| Effect | `write` |
| Subject | The authenticated self-managed `Node`; `none` before identity resolution. |
| Properties | `user`, `platform`, and `managed` intent; probe output is not logged. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeManageCommandTest.php` | CLI validation, platform detection, and gateway request sequencing. |
| `apps/gateway/tests/Feature/Http/Api/NodeManageControllerTest.php` | Gateway self-management API authorization and metadata persistence. |
| `apps/gateway/tests/Unit/Services/Nodes/OperatorNodeManagerTest.php` | Roleless eligibility, Agent-push verification, and retained management intent on reachability failure. |
