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
bin/e2e --prepare-blank
bin/e2e --prepare-control
bin/e2e --lifecycle
bin/e2e --control
```

The first ephemeral E2E harness uses Incus VMs on beast. Run
`bin/e2e --prepare-blank` to build or replace the reusable
`orbit-blank-ubuntu-26.04` image from the Ubuntu cloud image. The default
`composer test:e2e` path launches one disposable VM from that blank image,
injects an ephemeral SSH key for the non-`orbit` bootstrap user, verifies SSH
from beast into the VM, and deletes the VM. The blank image intentionally does
not use `orbit` as the bootstrap user so gateway and app provisioning tests can
prove Orbit creates or prepares the node-side `orbit` user itself.

Run `bin/e2e --prepare-control` to build or replace the reusable
`orbit-ready-control` image from the blank image. That lane ships the current
Orbit source and `bin/install-orbit` into a disposable VM, installs the control
node prerequisites and CLI as the non-`orbit` control user, verifies
`orbit --version`, then publishes the ready image. Run `bin/e2e --control` to
launch from that image and verify the ready control node over SSH.

These are backend and single-role smokes only; they do not yet validate a full
gateway/control/development-app/production-app topology.

Environment overrides:

```bash
ORBIT_LIVE_GATEWAY_SSH=gateway
ORBIT_LIVE_GATEWAY_PATH=~/orbit

ORBIT_E2E_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BLANK_IMAGE=orbit-blank-ubuntu-26.04
ORBIT_E2E_CONTROL_IMAGE=orbit-ready-control
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_CONTROL_USER=control
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```

The next E2E step is to create the remaining ready Incus lanes:

- ready snapshots for fast command-porting tests against prepared gateway,
  development app, and production app nodes.

Ready image aliases:

- `orbit-ready-control` via `bin/e2e --prepare-control`
- `orbit-ready-gateway` planned
- `orbit-ready-app-development` planned
- `orbit-ready-app-production` planned

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
