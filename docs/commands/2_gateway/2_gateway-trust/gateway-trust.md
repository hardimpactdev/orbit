# `orbit gateway:trust`

[Back to Gateway commands.](../README.md)

Repair local trust for the configured Orbit gateway root CA.

`gateway:trust` is a local trust repair command. It fetches the configured
gateway's root CA public certificate, installs it into the caller machine's OS
trust store, and records local trust metadata for later verification. It does
not onboard a control node, verify node identity, or mutate gateway fleet
configuration.

The trusted root is the Orbit network trust anchor. The gateway owns the root
CA and issues Orbit-managed leaf certificates for app, workspace, proxy,
gateway, and future tool routes. Serving nodes receive the route-scoped
certificate and key material they need to serve HTTPS locally, but they do not
receive the gateway root key or mint Orbit certificates themselves. Trusting
the gateway root once lets the caller trust every Orbit-managed route
certificate that chains back to that root.

## Usage

```bash
orbit gateway:trust [--json]
```

## Examples

```bash
orbit gateway:trust
orbit gateway:trust --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

`gateway:trust` repairs local gateway CA trust:

1. Resolve the configured local gateway endpoint.
2. Fetch the gateway root CA through the bootstrap-safe trust path.
3. Validate that the response contains a root CA certificate in PEM encoding.
4. Install the certificate into the local OS trust store.
5. Store local trust metadata, including the gateway endpoint, CA fingerprint,
   and trust timestamp.

The command is idempotent. If the same gateway CA is already trusted locally, it
reports success without changing gateway configuration.

`gateway:trust` does not create or distribute route certificates. App,
workspace, proxy, gateway, and tool route applying owns the gateway-issued
leaf certificates and the serving-node TLS files they require.

Use [`gateway:add`](../1_gateway-add/gateway-add.md) for first-time
control-node onboarding. `gateway:add` uses the same trust behavior as part of
the onboarding flow, then verifies `/api/me` and stores complete local gateway
configuration. Use
[`doctor --family=node --self`](../../1_node/node-doctor.md) when the operator
needs local gateway endpoint, identity, and trust diagnostics.

## Output

You will see a short progress tree showing whether the gateway CA is now trusted.

JSON output reports the trusted gateway URL, trust status, and CA fingerprint
using the shared command envelope.

## Requirements

- A configured local gateway endpoint exists.
- The gateway root CA endpoint is reachable from the caller machine.
- The caller machine supports the local trust-store installation path that Orbit uses.
- The process has the local OS privileges required to update the trust store.

## Related Commands

Use these commands when you need to go beyond trust repair or diagnose deeper gateway drift.

- [`gateway:add`](../1_gateway-add/gateway-add.md) - configure a control node
  for an already-issued gateway identity
- [`doctor --family=node`](../../1_node/node-doctor.md) - verify local gateway
  trust and node identity drift

## Technical Contract

See [`gateway:trust` technical contract](technical/1_gateway-trust.md).
