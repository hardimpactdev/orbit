# `orbit ca:trust`

[Back to Operation commands.](../README.md)

Install or repair local trust for the configured Orbit gateway root CA.

`ca:trust` is a local trust repair command. It fetches the gateway root CA public
certificate, installs it into the caller machine's OS trust store, and records
local trust metadata for later verification. It does not onboard a control node,
verify node identity, or mutate gateway fleet intent.

## Usage

```bash
orbit ca:trust [--gateway=<url-or-address>] [--json]
```

## Examples

```bash
orbit ca:trust
orbit ca:trust --gateway=https://10.6.0.2
orbit ca:trust --json
```

## Arguments And Options

- `--gateway`: Gateway URL, hostname, or WireGuard address to trust. When
  omitted, Orbit uses the configured local gateway endpoint.
- `--json`: Output JSON.

## What Happens

`ca:trust` repairs local gateway CA trust:

1. Resolve the gateway endpoint from `--gateway` or local gateway settings.
2. Fetch the gateway root CA through the bootstrap-safe trust path.
3. Validate that the response contains a PEM-encoded root CA certificate.
4. Install the certificate into the local OS trust store.
5. Store local trust metadata, including the gateway endpoint, CA fingerprint,
   and trust timestamp.

The command is idempotent. If the same gateway CA is already trusted locally, it
reports success without changing gateway intent.

Use [`gateway:add`](../../1_node/2_gateway-add/gateway-add.md) for first-time
control-node onboarding. Use
[`doctor --family=node --self`](../../1_node/node-doctor.md) when the operator
needs local gateway endpoint, identity, and trust diagnostics.

## Output

Human output shows a short progress tree and reports whether the gateway CA is
trusted.

JSON output reports the trusted gateway URL, trust status, and CA fingerprint
using the shared command envelope.

## Requirements

- A configured local gateway endpoint exists, or `--gateway` is supplied.
- The gateway root CA endpoint is reachable from the caller machine.
- The caller machine supports Orbit's local trust-store installation path.
- The process has the local OS privileges required to update the trust store.

## Related Commands

- [`gateway:add`](../../1_node/2_gateway-add/gateway-add.md) - configure a
  control node for an already-issued gateway identity
- [`doctor --family=node`](../../1_node/node-doctor.md) - verify local gateway
  trust and node identity drift

## Technical Contract

See [`ca:trust` technical contract](technical/1_ca-trust.md).
