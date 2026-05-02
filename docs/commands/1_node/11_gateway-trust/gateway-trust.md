# `orbit gateway:trust`

[Back to Node commands.](../README.md)

Repair local trust for the configured Orbit gateway root CA.

`gateway:trust` is a local trust repair command. It fetches the configured
gateway's root CA public certificate, installs it into the caller machine's OS
trust store, and records local trust metadata for later verification. It does
not onboard a control node, verify node identity, or mutate gateway fleet
intent.

## Usage

```bash
orbit gateway:trust [--json]
```

## Examples

```bash
orbit gateway:trust
orbit gateway:trust --json
```

## Arguments And Options

- `--json`: Output JSON.

## What Happens

`gateway:trust` repairs local gateway CA trust:

1. Resolve the configured local gateway endpoint.
2. Fetch the gateway root CA through the bootstrap-safe trust path.
3. Validate that the response contains a PEM-encoded root CA certificate.
4. Install the certificate into the local OS trust store.
5. Store local trust metadata, including the gateway endpoint, CA fingerprint,
   and trust timestamp.

The command is idempotent. If the same gateway CA is already trusted locally, it
reports success without changing gateway intent.

Use [`gateway:add`](../2_gateway-add/gateway-add.md) for first-time
control-node onboarding. `gateway:add` uses the same trust behavior as part of
the onboarding flow, then verifies `/api/me` and stores complete local gateway
configuration. Use [`doctor --family=node --self`](../node-doctor.md) when the
operator needs local gateway endpoint, identity, and trust diagnostics.

## Output

Human output shows a short progress tree and reports whether the gateway CA is
trusted.

JSON output reports the trusted gateway URL, trust status, and CA fingerprint
using the shared command envelope.

## Requirements

- A configured local gateway endpoint exists.
- The gateway root CA endpoint is reachable from the caller machine.
- The caller machine supports Orbit's local trust-store installation path.
- The process has the local OS privileges required to update the trust store.

## Related Commands

- [`gateway:add`](../2_gateway-add/gateway-add.md) - configure a control node
  for an already-issued gateway identity
- [`doctor --family=node`](../node-doctor.md) - verify local gateway trust and
  node identity drift

## Technical Contract

See [`gateway:trust` technical contract](technical/1_gateway-trust.md).
