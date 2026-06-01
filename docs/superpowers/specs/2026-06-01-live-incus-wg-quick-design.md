# Live Incus wg-quick Design

## Goal

Make `composer e2e:incus -- --live --topology=<topology>` create a retained
Incus topology that is reachable from the local Mac without manual tunnel or
gateway setup.

The command should keep the WireGuard GUI app reserved for real or long-lived
production connections. Disposable E2E tunnels are owned by `wg-quick`, making
them listable, verifiable, and stoppable through command-line tooling.

## Product Decisions

- `wg-quick` is the only automated local tunnel backend for live E2E Incus
  topologies.
- WireGuard.app import, activation, listing, and teardown are out of scope.
- Human `--live` runs may mutate the local host by writing a generated
  WireGuard config, starting the tunnel, and adding the local Orbit gateway.
- The command provides an opt-out path for manual setup when host mutation is
  not wanted.
- The local gateway entry defaults to `incus-<id>` and is set as the active
  gateway by `orbit gateway:add`.
- Generated tunnel names use an Orbit-owned prefix, such as
  `orbit-e2e-<id>`, so test tunnels remain distinct from user-managed
  production VPNs.

## Command Contract

The primary command remains:

```bash
composer e2e:incus -- --live --topology=<topology>
```

In the default human path, the command:

1. acquires and retains the requested Incus topology;
2. overlays the current checkout;
3. mints a local operator WireGuard identity from the topology operator VM;
4. rewrites the peer endpoint to `ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT` or
   `--wireguard-endpoint=<host:port>`;
5. writes the config to an Orbit-managed E2E path;
6. starts the tunnel with `wg-quick up`;
7. runs local `orbit gateway:add <gateway-ip> --name=<gateway-name>`;
8. verifies gateway API reachability through the local tunnel.

Manual mode skips steps 6 through 8 and prints the generated config plus the
commands needed to finish locally.

## Local Tunnel Rules

The generated `wg-quick` interface name must be stable for the retained topology
id and safe for repeated diagnosis. Starting an already-active tunnel should
produce a helpful message rather than duplicate local state.

The command verifies tunnel state with `wg show interfaces`. A started live E2E
tunnel must be visible there. GUI-managed `utun*` tunnels are ignored because
they belong to WireGuard.app and are not safely controlled by `wg-quick`.

Stopping a retained live topology should bring down the matching `wg-quick`
tunnel before releasing Incus resources when local setup was performed. If the
tunnel is already down, stop continues and reports that local tunnel cleanup was
already complete.

## Progress Tree

Human output uses the progress tree renderer described in
`apps/docs/content/ux/commands/progress/progress-tree.md`.

Initial step list:

```text
┌ Preparing live Incus topology
○ Validate live endpoint
○ Acquire topology
○ Mint local operator identity
○ Write WireGuard config
○ Start local tunnel
○ Add local gateway
○ Verify gateway API
└ Live Incus topology <id> ready
```

Manual mode renders the same tree but marks local tunnel and gateway setup as
skipped with a clear follow-up footer.

## JSON Contract

`--json` keeps one top-level `success` or `error` envelope.

The success payload includes:

- `id`;
- `topology`;
- `gateway_ip`;
- `gateway_name`;
- `wireguard.endpoint`;
- `wireguard.interface`;
- `wireguard.config_path`;
- `wireguard.started`;
- `wireguard.gateway_added`;
- `commands.stop`;
- `commands.gateway_check`.

Handled failures use existing command vocabulary where possible:

- `validation_failed` for missing endpoint or invalid options;
- `local_wireguard_unavailable` when `wg-quick` or `wg` is missing;
- `local_wireguard_failed` when `wg-quick up` fails;
- `local_gateway_failed` when local `orbit gateway:add` fails;
- `gateway_unreachable` when the tunnel starts but the gateway API cannot be
  reached.

## Testing

Feature tests should not mutate the developer machine. The command needs a
small process-runner boundary for local `wg`, `wg-quick`, and `orbit` calls so
tests can fake successful setup, unavailable tooling, repeated starts, and
failed gateway verification.

Focused tests cover:

- default `--live` starts a `wg-quick` tunnel and adds the named gateway;
- manual mode still prints the config and follow-up commands;
- missing `wg-quick` fails before host mutation;
- stop tears down the recorded local tunnel when it was started by Orbit;
- human output contains the progress tree labels.

## Documentation Updates

Update the retained topology docs to describe automatic `wg-quick` setup,
manual opt-out, listing with `wg show interfaces`, and teardown behavior.

Update the environment docs to keep
`ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=<host>:51820` as the endpoint source for
trusted LAN access to the Incus host.
