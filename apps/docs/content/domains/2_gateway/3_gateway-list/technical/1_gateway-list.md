# Technical Contract: `orbit gateway:list`

[Back to public `gateway:list` documentation.](../gateway-list.md)

**Owner:** `gateway`.

**Effects:** `read`.

**Prerequisites:**
- No network or gateway access is required. This command reads local CLI configuration only.

## Signature

```bash
orbit gateway:list [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

No required inputs exist. The command takes no positional arguments.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Local config read

- Read caller-local gateway entries from `~/.config/orbit/config.json`.
- Return each entry's local name, active state, URL, WireGuard IP, CA
  fingerprint, and timeout when present.
- Fail with `validation_failed` when no local gateway entries exist.

### Scope Boundaries

- Never contact the gateway API.
- Never mutate `active_gateway` or any gateway entry.

## JSON Output

JSON output uses the canonical success envelope. `success.data.gateways` is a
list of gateway entries. `success.data.active_gateway` is the active gateway
name or `null`.

## Human Output

Human output prints the active gateway name and the gateway list. The active
gateway is marked per entry.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| No gateways configured | The local config has no gateway entries. | `validation_failed` with `meta.reason: "missing"` |

## Doctor Relationship

`gateway:list` has no doctor relationship. It reads local CLI configuration only and does not verify gateway connectivity or CA trust.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Gateway/GatewayListCommandTest.php` | Command contract: local config read, active gateway resolution, empty config failure, and no gateway API contact. |
