# `orbit gateway:status`

[Back to Gateway commands.](../README.md)

Check whether the active gateway API is reachable and healthy.

## Usage

```bash
orbit gateway:status [--json]
```

## Examples

```bash
orbit gateway:status
orbit gateway:status --json
```

## Arguments and Options

- `--json`: Output JSON.

## What Happens

Run `gateway:status` to verify that your local CLI can reach the gateway API
over the WireGuard network. The command sends a request to the gateway and
reports the health response.

`gateway:status` does not modify local configuration, gateway state, or node
records. It does not perform identity verification, CA trust repair, or
WireGuard configuration.

See the [technical contract](technical/1_gateway-status.md) for the full
behavior specification.

## Output

Your terminal shows the gateway status fields, including at minimum `status`
and `version`, from the gateway health response.

Pass `--json` to receive the full gateway response and request metadata in
machine-readable form. See the [JSON renderer contract](technical/6.2_gateway-status_output-render_json.md).

## Requirements

- A gateway has been configured locally via
  [`gateway:add`](../1_gateway-add/gateway-add.md).
- Your machine has an active WireGuard connection to the Orbit network.

## Related Commands

Use these commands to configure gateway access or investigate failures.

- [`gateway:add`](../1_gateway-add/gateway-add.md) — configure the local CLI
  to use a gateway
- [`gateway:trust`](../2_gateway-trust/gateway-trust.md) — repair local
  gateway CA trust
- [`gateway:list`](../3_gateway-list/gateway-list.md) — list configured local
  gateway entries
- [`gateway:use`](../4_gateway-use/gateway-use.md) — switch the active local
  gateway
- [`doctor --family=node`](../../1_node/node-doctor.md) — verify node drift
  including gateway reachability
