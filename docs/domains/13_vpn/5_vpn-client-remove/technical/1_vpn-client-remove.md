# Technical Contract: `orbit vpn-client:remove <name> [--force] [--json]`

[Back to public `vpn-client:remove` documentation.](../vpn-client-remove.md)

**Owner:** `vpn`.

**Effects:** `write`, `destructive`.

**Prerequisites:**
- The caller is the gateway node, has `vpn:write` on the active gateway node,
  or has `gateway-admin` (`*`) on the active gateway node.
- Non-gateway callers can reach the active `vpn` role node over
  Orbit/WireGuard SSH. In v1 that node is gateway-coupled.
- The active `vpn` role is resolvable.
- The VPN runtime backend is installed and reachable on the active `vpn` role host.
- The operator can authenticate to the VPN runtime backend when TOTP is
  required.

**Post-input path eligibility:**
- The resolved client must exist.
- The resolved client must not correspond to an active Orbit node identity.
- Destructive consent is required before backend deletion begins.

## Signature

```bash
orbit vpn-client:remove <name> [--force] [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `<name>` | Always. | Never. | None. | Existing non-node VPN client name. |
| `force` | `--force` | Required in non-interactive input mode. | Never. | `false` | Destructive consent for deleting the backend peer. |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the VPN runtime backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode; `--json` never grants destructive consent. |

## Input Mode Contracts

- [Interactive input mode](5.1_vpn-client-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_vpn-client-remove_input-mode_non-interactive.md)

## Behavior Contract

### Client Removal Rules

- Resolve the active `vpn` role host.
- Resolve `name` against VPN runtime backend clients.
- Fail when the client does not exist.
- Fail when the resolved backend peer matches an active Orbit node identity.
- Delete the backend peer only after destructive consent.
- Return the removed client name and `action=removed`.
- Do not remove local WireGuard config files from operator machines.

### Node Identity Boundary

`vpn-client:remove` must not remove Orbit node records, node access grants, or
active node peers. Removing Orbit node peers belongs to `node:remove`; stale
node peers after node removal belong to `doctor --fix --family=node --restore`.

## Renderer Contracts

- [Human renderer](6.1_vpn-client-remove_output-render_human.md)
- [JSON renderer](6.2_vpn-client-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| VPN runtime unavailable | No active `vpn` role node exists for VPN administration. | `error.code=vpn_runtime_unavailable` |
| VPN runtime SSH unavailable | A non-gateway caller cannot execute the VPN-role runtime operation over Orbit/WireGuard SSH on the active `vpn` role node. | `error.code=vpn_runtime_ssh_unavailable` |
| VPN backend unavailable | The VPN runtime backend is missing, stopped, or unreachable on the active `vpn` role host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

Removing a non-node VPN client is not doctor drift. Removing an Orbit node's
WireGuard peer outside `node:remove` may later surface as node-family drift.
[`doctor --family=node`](../../../1_node/node-doctor.md) owns stale node-peer
detection and safe node-peer cleanup.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Vpn/VpnClientRemoveCommandTest.php` | Command contract: grant denial, SSH execution, TOTP, missing-client failure, node-peer protection, destructive consent, backend deletion, and no node cleanup. |
| `tests/Feature/Commands/Vpn/VpnClientRemoveInteractiveInputModeTest.php` | Interactive confirmation prompt, `--force` bypass, declined confirmation failure before side effects, and prompt abort behavior. |
| `tests/Feature/Commands/Vpn/VpnClientRemoveNonInteractiveInputModeTest.php` | Non-interactive missing-`--force` failure, `--json` forcing non-interactive mode, and no prompts. |
| `tests/Feature/Commands/Vpn/VpnClientRemoveRendererTest.php` | Human and JSON renderer output and every documented `error.code` value. |
