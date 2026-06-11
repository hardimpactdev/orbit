# `orbit gateway:list`

[Back to Gateway commands.](../README.md)

List the gateway entries stored in the Orbit CLI configuration on the caller's machine.

`gateway:list` is local-only. It does not contact the active gateway, verify
WireGuard reachability, refresh trust material, or mutate gateway-owned state.

## Usage

```bash
orbit gateway:list [--json]
```

## Examples

```bash
orbit gateway:list
orbit gateway:list --json
```

## What Happens

Run `gateway:list` to see all gateway entries configured on your machine and which one is active.

`gateway:list` reads `~/.config/orbit/config.json`, reports every configured
gateway entry, and marks the single active gateway selected by
`active_gateway`.

The command fails when no local gateway entries exist.

## Related Commands

See also these commands for managing your local gateway configuration.

- [`gateway:add`](../1_gateway-add/gateway-add.md) - add or refresh a named
  local gateway entry
- [`gateway:use`](../4_gateway-use/gateway-use.md) - switch the active gateway

## Technical Contract

See [`gateway:list` technical contract](technical/1_gateway-list.md).
