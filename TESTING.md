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
bin/e2e --preflight
bin/e2e --lifecycle
```

The first ephemeral E2E harness uses Incus VMs on beast. The default
`composer test:e2e` path launches one disposable Ubuntu cloud VM, injects an
ephemeral SSH key, verifies SSH from beast into the VM, and deletes the VM.
This is a backend lifecycle smoke only; it does not yet provision Orbit roles
or validate a gateway/control/app topology.

Environment overrides:

```bash
ORBIT_LIVE_GATEWAY_SSH=gateway
ORBIT_LIVE_GATEWAY_PATH=~/orbit

ORBIT_E2E_HOST=beast
ORBIT_E2E_IMAGE=images:ubuntu/24.04/cloud
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```

The next E2E step is to create two Incus lanes:

- blank snapshots for provisioning tests from base Ubuntu plus SSH only.
- ready snapshots for fast command-porting tests against prepared control,
  gateway, and app nodes.

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
