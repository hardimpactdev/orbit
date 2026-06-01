# Technical Contract: `orbit gateway:use <name>`

[Back to public `gateway:use` documentation.](../gateway-use.md)

**Owner:** `gateway`.

**Effects:** `write`.

## Signature

```bash
orbit gateway:use <name> [--json]
```

## Input Contract

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `<name>` | Always. | Never. | None. | Existing local gateway name slug. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

- Read caller-local gateway entries from `~/.config/orbit/config.json`.
- Validate that `<name>` is a configured gateway entry.
- Set `active_gateway` to `<name>`.
- Preserve all gateway entries and defaults.
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
