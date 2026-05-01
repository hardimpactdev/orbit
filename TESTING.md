# TESTING.md

Environment-specific verification for the clean Orbit rebuild.

## Current Real Nodes

The standing real-node smoke path uses the gateway registry:

- `gateway`: `10.6.0.2`, SSH alias `gateway`
- `mini`: `10.6.0.8`, registered as a control node
- `beast`: `10.6.0.7`, registered as an app node

`gateway` owns the current `nodes` registry used by `update:all`.

## Verification Lanes

Use local tests for ordinary development:

```bash
composer test
```

Use standing live-node smoke for fast non-destructive integration checks:

```bash
composer test:live
bin/live-smoke --gateway
```

The live smoke path runs local tests, SSHes to the gateway, runs `update:all`,
runs gateway-side tests, and prints `node:list`.

Use ephemeral E2E only for full lifecycle, provisioning, destructive, or host
mutation checks:

```bash
composer test:e2e
```

The ephemeral E2E harness is not restored yet. Until it exists, `composer
test:e2e` exits with a message instead of touching standing live nodes.

Environment overrides:

```bash
ORBIT_LIVE_GATEWAY_SSH=gateway
ORBIT_LIVE_GATEWAY_PATH=~/orbit
```

## Standing Live Node Rule

Standing live nodes may be used for read-only or idempotent smoke checks only.
Allowed examples:

- `node:list`
- gateway reachability
- local and gateway-side test suites
- `update:all`, because it is the intended update mechanism
- command discovery and help output

Do not run write/destructive E2E against standing live nodes. These belong to
ephemeral E2E:

- provisioning or bootstrap
- `node:new`
- destructive remove or prune flows
- firewall, DNS, proxy, or host service mutations
- app, workspace, process, or doctor repair/adoption flows that create, delete,
  or mutate real artifacts
