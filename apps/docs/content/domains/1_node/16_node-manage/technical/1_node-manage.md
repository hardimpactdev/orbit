# Technical Contract: `orbit node:manage --node-transport=transitional-ssh-fallback [--user=<user>] [--json]`

[Back to public `node:manage` documentation.](../node-manage.md)

**Owner:** `node`.

**Effects:** `local-write, write, remote-probe`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer is an active node with no active role assignments.
- The caller is running on the local machine represented by that node identity.
- The caller explicitly selects the exact `transitional-ssh-fallback` transport.

## Signature

```bash
orbit node:manage --node-transport=transitional-ssh-fallback [--user=<user>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `user` | `--user` | Optional. | Never. | Current local user. | Must match the current local user in this implementation. |
| `node_transport` | `--node-transport` | Always. | Never. | None. | Must be exactly `transitional-ssh-fallback`; this command is an explicitly tracked transitional SSH seam pending Agent-push bootstrap replacement. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/me` | authenticated | Resolve the caller's node identity. |
| `GET` | `/api/nodes/self/manage-key` | authenticated roleless active node plus exact transitional transport marker | Preflight the transitional SSH seam, then return the gateway management SSH public key. |
| `POST` | `/api/nodes/self/manage` | authenticated roleless active node | Persist management metadata, pin SSH host key, and verify gateway SSH reachability. |

## Behavior Contract

### Local Setup Rules

- Resolve the current local SSH user before requesting gateway mutation.
- Complete the gateway eligibility and exact transitional-transport preflight
  before mutating local `authorized_keys`.
- Install the gateway SSH public key into the current user's
  `~/.ssh/authorized_keys`.
- Create `~/.ssh` with mode `0700` when missing.
- Create `authorized_keys` with mode `0600` when missing.
- Append the gateway key only when that exact key line is not already present.
- Preserve unrelated authorized keys.
- Detect the local platform using the same normalized family convention as
  gateway-managed nodes, such as `macos_15-5` or `ubuntu_24-04`.

### Gateway Management Rules

- Reject inactive nodes and nodes with any active role assignment before
  gateway side effects.
- Reject requests without the exact `transitional-ssh-fallback` marker before
  local or gateway management state changes.
- Store the selected SSH user in `node.user`.
- Store the detected platform in `node.platform`.
- Use the node's `node.wireguard_address` as the SSH host. Do not use public
  hostnames, local hostnames, TLDs, or host metadata for this path.
- Pin the SSH host key by WireGuard address and persist the existing node
  host-key fields.
- Verify gateway-to-node SSH reachability after metadata and host-key pinning.
- Set `node.managed=true` only after the explicit opt-in verification succeeds;
  this gives a roleless operator node managed Agent intent.

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
| Transitional transport not selected | The caller omits the exact transitional SSH marker. | `error.code=node_transport_required` |
| Missing WireGuard address | The node has no `wireguard_address` for gateway SSH. | `error.code=node.wireguard_address_missing` |
| Gateway key unavailable | The gateway cannot expose its management public key. | `error.code=node.management_key_unavailable` |
| Local key install failed | The CLI cannot create or update local `authorized_keys`. | `error.code=node.authorized_keys_failed` |
| Host key pin failed | Gateway SSH host-key pinning by WireGuard address failed. | `error.code=node.host_key_pin_failed` |
| SSH reachability failed | The gateway could not SSH to the node at the WireGuard address after setup. | `error.code=node.ssh_unreachable` |

## Doctor Relationship

`node:manage` performs the explicit managed-Agent opt-in and transitional SSH
bootstrap check for a roleless operator node. Later drift in managed eligibility,
Agent expectation, user metadata, platform metadata, or reachability belongs to
[`doctor --family=node`](../../node-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeManageCommandTest.php` | CLI validation, local authorized key install, platform detection, and gateway request sequencing. |
| `apps/gateway/tests/Feature/Http/Api/NodeManageControllerTest.php` | Gateway self-management API authorization, metadata persistence, host-key pinning, and SSH verification behavior. |
| `apps/gateway/tests/Unit/Services/Nodes/OperatorNodeManagerTest.php` | Roleless eligibility, WireGuard SSH host selection, host-key pinning, and reachability failure mapping. |
