# Technical Contract: `orbit vpn-web-ui:change-password [password] [--force] [--json]`

[Back to public `vpn-web-ui:change-password` documentation.](../vpn-web-ui-change-password.md)

**Owner:** `vpn`.

**Effects:** `write`, `destructive`, `gateway-admin`.

**Prerequisites:**
- The caller is a gateway node, or an authorized client with SSH access
  to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the gateway VPN backend when TOTP is
  required.

**Post-input path eligibility:**
- The new password satisfies the gateway VPN backend password policy.
- Destructive consent is required before credential rotation begins.

## Signature

```bash
orbit vpn-web-ui:change-password [password] [--force] [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `password` | `[password]` | Always. Interactive input mode may prompt when omitted. | Never. | None. | At least 12 characters and accepted by the gateway VPN backend password policy. |
| `force` | `--force` | Required in non-interactive input mode. | Never. | `false` | Destructive consent for rotating the backend admin credential. |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the gateway VPN backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode; `--json` never grants destructive consent. |

## Input Mode Contracts

- [Interactive input mode](5.1_vpn-web-ui-change-password_input-mode_interactive.md)
- [Non-interactive input mode](5.2_vpn-web-ui-change-password_input-mode_non-interactive.md)

## Behavior Contract

### Credential Rotation Rules

- Verify backend identity before changing stored credentials.
- Replace the gateway VPN backend web UI password with the new password.
- Invalidate existing backend admin sessions when supported by the backend.
- Update the Orbit-managed credential storage on the gateway so later VPN
  commands can authenticate with the new password.
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
| Gateway SSH unavailable | A operator caller cannot execute the gateway-local operation over Orbit/WireGuard SSH. | `error.code=gateway_ssh_unavailable` |
| VPN backend unavailable | The gateway VPN backend is missing, stopped, or unreachable on the gateway host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |
| Credential rotation failed | The backend credential, session secret, or Orbit-managed credential store cannot be updated. | `error.code=vpn_credential_rotation_failed` |

## Doctor Relationship

VPN backend password rotation is gateway administration, not doctor drift.
[`doctor --family=node`](../../../1_node/node-doctor.md) may report gateway
WireGuard or node identity readiness failures caused by a broken VPN backend,
but it does not rotate the VPN web UI password.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Vpn/VpnWebUiChangePasswordCommandTest.php` | Command contract: caller-role denial, operator-caller gateway-local SSH execution, gateway execution, TOTP handling, password validation, destructive consent, credential update, session invalidation, gateway-local credential-store update, secret redaction, and non-goals. |
| `tests/Feature/Commands/Vpn/VpnWebUiChangePasswordInteractiveInputModeTest.php` | Interactive password prompt, confirmation prompt, `--force` bypass, validation retry, declined confirmation failure before side effects, and prompt abort behavior. |
| `tests/Feature/Commands/Vpn/VpnWebUiChangePasswordNonInteractiveInputModeTest.php` | Non-interactive missing password failure, missing `--force` failure, `--json` forcing non-interactive mode, no prompts, and secret redaction in errors. |
| `tests/Feature/Commands/Vpn/VpnWebUiChangePasswordRendererTest.php` | Human and JSON renderer output and every documented `error.code` value. |
