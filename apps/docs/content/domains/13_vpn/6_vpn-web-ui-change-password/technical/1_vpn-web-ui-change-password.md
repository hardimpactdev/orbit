# Technical Contract: `orbit vpn-web-ui:change-password [password] [--force] [--json]`

[Back to public `vpn-web-ui:change-password` documentation.](../vpn-web-ui-change-password.md)

**Owner:** `vpn`.

**Effects:** `write`, `destructive`.

**Prerequisites:**
- The caller is the gateway node, has `vpn:write` on the active gateway node,
  or has `gateway-admin` (`*`) on the active gateway node.
- Every public CLI caller reaches the gateway through the typed HTTPS API over
  WireGuard. Caller identity changes authorization, not transport.
- The active `vpn` role is resolvable.
- The VPN runtime backend is installed and reachable on the active `vpn` role host.
- The operator can authenticate to the VPN runtime backend when TOTP is
  required.

**Post-input path eligibility:**
- The new password satisfies the VPN runtime backend password policy.
- Destructive consent is required before credential rotation begins.

## Signature

```bash
orbit vpn-web-ui:change-password [password] [--force] [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `password` | `[password]` | Always. Interactive input mode may prompt when omitted. | Never. | None. | At least 12 characters and accepted by the VPN runtime backend password policy. |
| `force` | `--force` | Required in non-interactive input mode. | Never. | `false` | Destructive consent for rotating the backend admin credential. |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the VPN runtime backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode; `--json` never grants destructive consent. |

## Input Mode Contracts

- [Interactive input mode](5.1_vpn-web-ui-change-password_input-mode_interactive.md)
- [Non-interactive input mode](5.2_vpn-web-ui-change-password_input-mode_non-interactive.md)

## Behavior Contract

### Credential Rotation Rules

- Verify backend identity before changing stored credentials.
- Resolve the active `vpn` role host.
- Replace the VPN runtime backend web UI password with the new password.
- Invalidate existing backend admin sessions when supported by the backend.
- Update the Orbit-managed credential storage on the active `vpn` role host so
  later VPN commands can authenticate with the new password. In v1 that host is
  gateway-coupled.
- Never emit the new password in command output, progress output, logs, or error
  metadata.

### Non-Goals

`vpn-web-ui:change-password` must not:

- Rotate WireGuard client keys.
- Reissue client configs.
- Change node identities or node access grants.
- Rotate gateway CA material.
- Change gateway API credentials or certificates.

## Renderer Contracts

- [Human renderer](6.1_vpn-web-ui-change-password_output-render_human.md)
- [JSON renderer](6.2_vpn-web-ui-change-password_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| VPN runtime unavailable | No active `vpn` role node exists for VPN administration. | `error.code=vpn_runtime_unavailable` |
| VPN backend unavailable | The VPN runtime backend is missing, stopped, or unreachable on the active `vpn` role host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |
| Credential rotation failed | The backend credential, session secret, or Orbit-managed credential store cannot be updated. | `error.code=vpn_credential_rotation_failed` |

## Doctor Relationship

VPN backend password rotation is VPN-role runtime administration, not doctor
drift.
[`doctor --family=node`](../../../1_node/node-doctor.md) may report gateway
WireGuard or node identity readiness failures caused by a broken VPN backend,
but it does not rotate the VPN web UI password.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php` | CLI `vpn-web-ui:change-password` gateway request forwarding, password validation before gateway contact, destructive consent, interactive prompts, secret redaction, human and JSON renderer output, and gateway error envelope passthrough. |
| `apps/gateway/tests/Feature/Http/Api/VpnControllerActivityTest.php` | Shared VPN API runtime-unavailable behavior and write-grant enforcement for VPN write requests. |
