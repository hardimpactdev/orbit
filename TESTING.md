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
bin/live-smoke --control
```

The live smoke path must run ON the control node (mini). It runs local tests,
then runs `update:all` (which SSHes to gateway and beast) and prints `node:list`.

Use ephemeral E2E only for full lifecycle, provisioning, destructive, or host
mutation checks:

```bash
composer test:e2e
bin/e2e --preflight
bin/e2e --prepare-blank
bin/e2e --prepare-control
bin/e2e --prepare-gateway
bin/e2e --lifecycle
bin/e2e --control
bin/e2e --node-new-gateway
bin/e2e --gateway-trust
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

Run `bin/e2e --prepare-gateway` to build or replace the reusable
`orbit-ready-gateway` Incus image from the blank image. That lane ships the
current Orbit source and `bin/install-orbit` into a disposable VM, installs the
gateway node prerequisites and CLI as the `orbit` user, bootstraps gateway-local
identity and root CA via `orbit:internal:bootstrap-gateway-local`, verifies
`orbit --version`, then publishes the ready image.

Run `bin/e2e --node-new-gateway` to exercise the first-gateway bootstrap path.
This lane launches one disposable VM from the ready control image and one from
the blank image, injects an ephemeral SSH key into both, runs
`orbit node:new gateway-1 --role=gateway --host=<gateway-ip> --ssh-user=<bootstrap-user> --control-name=control-1 --json`
from the control VM, and verifies that the control registry contains both nodes,
that the gateway has Orbit installed under the steady-state `orbit` user (not the
bootstrap user), and that `orbit --version` works on the gateway as the `orbit`
user. Verification uses `incus exec` to run `orbit --version` as the `orbit`
user; SSH access from the control VM to the gateway as `orbit` is not yet
verified because runtime-user SSH key distribution is not yet implemented.
The VMs are destroyed at the end unless `ORBIT_E2E_KEEP=1`.

Run `bin/e2e --gateway-add` to exercise control-node onboarding against a
prepared gateway VM. This lane launches one disposable VM from the ready control
image and one from the ready gateway image, injects an ephemeral SSH key into
both, configures a dummy network interface on the gateway VM with the expected
WireGuard IP (10.6.0.2), seeds the control node identity into the gateway
database, starts the gateway API server, and runs
`orbit gateway:add 10.6.0.2 --json` from the control VM. It verifies that the
command returns a success response. Full HTTPS verification depends on gateway
web server infrastructure in the ephemeral harness. The VMs are destroyed at the
end unless `ORBIT_E2E_KEEP=1`.

Run `bin/e2e --gateway-trust` to exercise local gateway CA trust repair against
a prepared gateway VM. This lane launches one disposable VM from the ready
control image and one from the ready gateway image, injects an ephemeral SSH key
into both, configures a dummy network interface on the gateway VM with the
expected WireGuard IP (10.6.0.2), starts the gateway API server, seeds the
gateway node into the control database, and runs `orbit gateway:trust --json`
from the control VM with sudo. It verifies that the command returns a trusted
success response, that the CA certificate is installed in the local OS trust
store, and that a re-run reports `already_trusted`. The VMs are destroyed at the
end unless `ORBIT_E2E_KEEP=1`.

These are backend and single-role smokes only; they do not yet validate a full
gateway/control/development-app/production-app topology.

Environment overrides:

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BLANK_IMAGE=orbit-blank-ubuntu-26.04
ORBIT_E2E_CONTROL_IMAGE=orbit-ready-control
ORBIT_E2E_GATEWAY_IMAGE=orbit-ready-gateway
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
- `orbit-ready-gateway` via `bin/e2e --prepare-gateway`
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
