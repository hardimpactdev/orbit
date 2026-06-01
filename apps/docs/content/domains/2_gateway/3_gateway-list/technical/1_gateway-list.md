# Technical Contract: `orbit gateway:list`

[Back to public `gateway:list` documentation.](../gateway-list.md)

**Owner:** `gateway`.

**Effects:** `read`.

## Signature

```bash
orbit gateway:list [--json]
```

## Behavior Contract

- Read caller-local gateway entries from `~/.config/orbit/config.json`.
- Return each entry's local name, active state, URL, WireGuard IP, CA
  fingerprint, and timeout when present.
- Never contact the gateway API.
- Never mutate `active_gateway` or any gateway entry.
- Fail with `validation_failed` when no local gateway entries exist.

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
