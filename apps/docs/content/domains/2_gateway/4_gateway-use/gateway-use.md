# `orbit gateway:use <name>`

[Back to Gateway commands.](../README.md)

Switch the active gateway in the caller-local Orbit CLI configuration.

`gateway:use` is local-only. It selects one already configured gateway entry as
the active endpoint for subsequent Orbit commands. It does not fetch trust
material or contact the gateway API.

## Usage

```bash
orbit gateway:use <name> [--json]
```

## Examples

```bash
orbit gateway:use default
orbit gateway:use incus-dev
orbit gateway:use incus-dev --json
```

## What Happens

`gateway:use` validates that `<name>` exists in the local gateway entries and
sets `active_gateway` to that name. Subsequent gateway-backed commands read that
active gateway endpoint unless environment overrides are set.

## Related Commands

- [`gateway:add`](../1_gateway-add/gateway-add.md) - add or refresh a named
  local gateway entry
- [`gateway:list`](../3_gateway-list/gateway-list.md) - list configured local
  gateway entries

## Technical Contract

See [`gateway:use` technical contract](technical/1_gateway-use.md).
