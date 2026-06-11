# `orbit gateway:use <name>`

[Back to Gateway commands.](../README.md)

Switch the active gateway in the Orbit CLI configuration on the caller's machine.

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

Run `gateway:use <name>` to switch your active gateway to a configured local entry.

`gateway:use` validates that `<name>` exists in the local gateway entries and
sets `active_gateway` to that name. Subsequent gateway-backed commands read that
active gateway endpoint unless environment overrides are set.

## Related Commands

See also these commands for managing your local gateway configuration.

- [`gateway:add`](../1_gateway-add/gateway-add.md) - add or refresh a named
  local gateway entry
- [`gateway:list`](../3_gateway-list/gateway-list.md) - list configured local
  gateway entries

## Technical Contract

See [`gateway:use` technical contract](technical/1_gateway-use.md).
