# `orbit gateway:add [gateway_ip]`

[Back to Gateway commands.](../README.md)

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

## Arguments and options

- `gateway_ip`: optional gateway WireGuard API address. When omitted, Orbit
  derives it from the active Orbit WireGuard network.
- `--json`: Output JSON.

## What Happens

Run `gateway:add` after joining the Orbit WireGuard network to configure your local CLI to use the gateway.

`gateway:add` performs local client onboarding for an already-issued
gateway identity. It does not create gateway registry rows, local registry
mirror rows, WireGuard peer material, identity, or access policy; those are
owned by [`node:new`](../../1_node/1_node-new/node-new.md) and gateway configuration.

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

The trusted gateway root CA is the same root that Orbit-managed app,
workspace, proxy, gateway, and future tool route certificates chain to.
`gateway:add` installs local trust for that root during onboarding, but route
certificate issuance and serving-node TLS files are owned by the commands and
doctor families that apply those routes.

If the gateway is already configured and verified, `gateway:add` exits
successfully as converged. Broader node drift is handled by
[`doctor --family=node --restore`](../../1_node/node-doctor.md).

If local gateway settings already exist and only OS trust for the gateway CA is
missing or stale, use [`gateway:trust`](../2_gateway-trust/gateway-trust.md).

`gateway:add` does not need SSH access to the gateway or any node, does not
provision hosts, does not mint access grants, and does not repair unrelated node
drift.

First-gateway bootstrap via `node:new --template=gateway --host=<host>
--operator-name=<operator-name>` already completes the onboarding for the initiating client;
that initiating client must not run `gateway:add` afterward.

## Output

Your local CLI shows progress while the command resolves the gateway,
fetches trust material, verifies reachability, verifies identity, and stores
local configuration.

JSON output includes the configured gateway reference, verified local node
identity, command result action, and local onboarding state.

## Requirements

- The gateway has already issued a WireGuard identity and active node record for
  this machine. See [Node identity issuance](../../1_node/README.md#node-identity-issuance).
- The local machine has imported that WireGuard configuration and joined the
  active Orbit WireGuard network.
- The gateway can expose its root CA or trust bundle through the Orbit network
  before this machine has local OS-level trust installed.

## Related Commands

Use these commands before or after `gateway:add` to complete node enrollment and manage trust.

- [`node:new`](../../1_node/1_node-new/node-new.md) — create or enroll nodes,
  including first-gateway bootstrap
- [`gateway:trust`](../2_gateway-trust/gateway-trust.md) — repair local
  gateway CA trust after onboarding
- [`node:list`](../../1_node/3_node-list/node-list.md) — list registered nodes
- [`node:show`](../../1_node/4_node-show/node-show.md) — show node details
- [`doctor --family=node`](../../1_node/node-doctor.md) — verify node drift
  including local gateway configuration

## Technical Contract

See [`gateway:add` technical contract](technical/1_gateway-add.md).
