# Technical Contract: `orbit vpn-client:remove <name> [--force] [--json]`

[Back to public `vpn-client:remove` documentation.](../vpn-client-remove.md)

**Owner:** `vpn`.

**Effects:** `write`, `destructive`, `gateway-admin`.

**Prerequisites:**
- The caller is a gateway node, or an authorized control node with SSH access
  to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the gateway VPN backend when TOTP is
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
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the gateway VPN backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode; `--json` never grants destructive consent. |

## Input Mode Contracts

- [Interactive input mode](5.1_vpn-client-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_vpn-client-remove_input-mode_non-interactive.md)

## Behavior Contract

### Client Removal Rules

- Resolve `name` against gateway VPN backend clients.
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
| Gateway SSH unavailable | A control caller cannot execute the gateway-local operation over Orbit/WireGuard SSH. | `error.code=gateway_ssh_unavailable` |
| VPN backend unavailable | The gateway VPN backend is missing, stopped, or unreachable on the gateway host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

Removing a non-node VPN client is not doctor drift. Removing an Orbit node's
WireGuard peer outside `node:remove` may later surface as node-family drift.
[`doctor --family=node`](../../../1_node/node-doctor.md) owns stale node-peer
detection and safe node-peer cleanup.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Vpn/VpnClientRemoveCommandTest.php` | Command contract: caller-role denial, gateway-local SSH execution, TOTP handling, missing client failure, node peer protection, destructive consent, backend deletion. No node records, grants, or local config cleanup. |
| `tests/Feature/Commands/Vpn/VpnClientRemoveInteractiveInputModeTest.php` | Interactive confirmation prompt, `--force` bypass, declined confirmation failure before side effects, and prompt abort behavior. |
| `tests/Feature/Commands/Vpn/VpnClientRemoveNonInteractiveInputModeTest.php` | Non-interactive missing-`--force` failure, `--json` forcing non-interactive mode, and no prompts. |
| `tests/Feature/Commands/Vpn/VpnClientRemoveRendererTest.php` | Human and JSON renderer output and every documented `error.code` value. |
