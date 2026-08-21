# Technical Contract: `orbit gateway:use <name>`

[Back to public `gateway:use` documentation.](../gateway-use.md)

**Owner:** `gateway`.

**Effects:** `write`.

**Prerequisites:**
- No network or gateway access is required. This command reads and writes local CLI configuration only.

## Signature

```bash
orbit gateway:use <name> [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `<name>` | Always. | Never. | None. | Existing local gateway name slug. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Active gateway selection

- Read caller-local gateway entries from `~/.config/orbit/config.json`.
- Validate that `<name>` is a configured gateway entry.
- Set `active_gateway` to `<name>`.
- Preserve all gateway entries and defaults.

### Scope Boundaries

- Never contact the gateway API.
- Never refresh CA trust or gateway identity.

## JSON Output

JSON output uses the canonical success envelope. `success.data.gateway` contains
the selected gateway entry and `success.data.result.action` is `selected`.

## Human Output

Human output reports the selected gateway name and endpoint.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid name | `<name>` is not a local gateway name slug. | `validation_failed` with `meta.reason: "invalid_name"` |
| Unknown gateway | `<name>` is not configured locally. | `validation_failed` with `meta.reason: "not_found"` |

## Doctor Relationship

`gateway:use` has no doctor relationship. It writes local CLI configuration only and does not verify gateway connectivity or CA trust.

## Activity Logging

This command does not emit activity. It changes caller-local CLI settings, and
the CLI has no trusted shared activity helper. The gateway records API work;
`gateway:use` does not make a gateway API request.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Gateway/GatewaySelectionCommandTest.php` | CLI `gateway:use` active gateway persistence, unknown gateway failure, JSON selected-gateway envelope, and human success/error prose without gateway API contact. |

There is no gateway-side coverage for this command-local mapping: input handling and renderer behavior live in `apps/cli`. Gateway API behavior is mapped in the command contract file when a gateway-side surface exists.
