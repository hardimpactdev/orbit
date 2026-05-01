# `orbit gateway:add [gateway_ip]`

[Back to Nodes commands.](../README.md)

Add a reachable Orbit gateway to the local CLI configuration.

Used after joining an existing Orbit WireGuard network. It discovers or verifies
the gateway, installs local gateway CA trust when needed, verifies the local
WireGuard identity against gateway-owned access policy, and stores the gateway
endpoint and trust material locally so subsequent Orbit commands know which
gateway to query.

## Usage

```bash
orbit gateway:add [gateway_ip] [--json]
```

## Examples

```bash
orbit gateway:add 10.6.0.2
orbit gateway:add 10.6.0.2 --json
```

## Arguments And Options

- `gateway_ip`: optional gateway WireGuard API address. When omitted, Orbit
  derives it from the active Orbit WireGuard network.
- `--json`: Output JSON.

## What Happens

`gateway:add` performs local control-node onboarding for an already-issued
gateway identity. It does not create gateway registry rows, local registry
mirror rows, WireGuard peer material, identity, or access policy; those are
owned by [`node:new`](../1_node-new/node-new.md) and gateway intent.

The command:

1. Derives or verifies the target gateway address.
2. Fetches the gateway root CA or trust bundle through the bootstrap-safe
   gateway trust path.
3. Checks whether local gateway trust and settings already match the target
   gateway.
4. Installs or refreshes the gateway root CA and local gateway configuration
   when local onboarding state is missing or stale.
5. Verifies the gateway API over HTTPS using the trusted gateway CA.
6. Verifies the local WireGuard identity against gateway-owned node identity and
   access policy. That identity must already have been minted on the gateway.
7. Stores local gateway API configuration, gateway WireGuard IP, and trust
   material.
8. Makes the stored gateway the default endpoint used by subsequent Orbit
   commands.

If the gateway is already configured and verified, `gateway:add` exits
successfully as converged. Broader node drift is handled by
[`doctor --family=node --fix`](../node-doctor.md).

`gateway:add` does not need SSH access to the gateway or any app node, does not
provision hosts, does not mint access grants, and does not repair unrelated node
drift.

First-gateway bootstrap via `node:new --role=gateway --host=<host>
--control-name=<control-name>` already completes initiating control-node
onboarding; that initiating control node must not run `gateway:add` afterward.

## Output

Human output uses a progress tree while the command resolves the gateway,
fetches trust material, verifies reachability, verifies identity, and stores
local configuration.

JSON output includes the configured gateway reference, verified local node
identity, command result action, and local onboarding state.

## Requirements

- The gateway has already issued a WireGuard identity and active node record for
  this machine. See [Node identity issuance](../README.md#node-identity-issuance).
- The local machine has imported that WireGuard configuration and joined the
  active Orbit WireGuard network.
- The gateway can expose its root CA or trust bundle through the Orbit network
  before this machine has local OS-level trust installed.

## Related Commands

- [`node:new`](../1_node-new/node-new.md) — create or enroll nodes, including
  first-gateway bootstrap
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`doctor --family=node`](../node-doctor.md) — verify node drift including
local gateway configuration

## Technical Contract

See [`gateway:add` technical contract](technical/1_gateway-add.md).
