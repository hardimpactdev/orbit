# TESTING.md

Environment-specific verification for the clean Orbit rebuild.

## Verification Model

Orbit has two supported test lanes:

1. In-memory Pest tests for deterministic command, service, database, renderer,
   and contract coverage.
2. Ephemeral VM E2E tests for real SSH, provisioning, image, and host-mutation
   coverage.

Standing live infrastructure is not a test lane. Do not use persistent gateway,
control, or app nodes as verification targets.

## In-Memory Pest

Use in-memory Pest tests for ordinary development:

```bash
composer test
```

This lane must not require real SSH, hcloud, Incus mutation, or standing
infrastructure. Fake process, gateway, provider, and transport boundaries in
Pest when the behavior is a command or contract concern.

## Ephemeral VM E2E

Use ephemeral E2E for full lifecycle, provisioning, destructive, or host
mutation checks against disposable VMs:

```bash
composer test:e2e
bin/e2e --preflight
bin/e2e --prepare-blank
bin/e2e --prepare-control
bin/e2e --prepare-gateway
bin/e2e --lifecycle
bin/e2e --control
bin/e2e --node-new-gateway
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

Run `bin/e2e --prepare-devapp` to build or replace the reusable
`orbit-ready-devapp` Incus image from the blank image. That lane ships the
current Orbit source and `bin/install-orbit` into a disposable VM, installs the
development-app node prerequisites and CLI as the `orbit` user with
`--role=app`, verifies `orbit --version`, then publishes the ready image.

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
command returns a success response, that `LocalGatewaySettings` is populated in
the control node database, and that the gateway CA is installed in the local OS
trust store. Full HTTPS verification and idempotent rerun convergence depend on
gateway web server/TLS infrastructure in the ephemeral harness; this lane is an
explicit bootstrap-partial gate. The VMs are destroyed at the end unless
`ORBIT_E2E_KEEP=1`.

These are backend and single-role E2E checks only; they do not yet validate a full
gateway/control/development-app/production-app topology.

Environment overrides:

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BLANK_IMAGE=orbit-blank-ubuntu-26.04
ORBIT_E2E_CONTROL_IMAGE=orbit-ready-control
ORBIT_E2E_GATEWAY_IMAGE=orbit-ready-gateway
ORBIT_E2E_DEVAPP_IMAGE=orbit-ready-devapp
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_CONTROL_USER=control
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```

## E2E Lanes

The ephemeral E2E suite is split into two explicit lanes at the Pest group level:

- **`e2e-provisioning`** — tests that mutate disposable VMs from blank or ready
  images and exercise setup flows such as blank VM lifecycle, control node smoke,
  gateway onboarding, and node provisioning. These tests are grouped with
  `pest()->group('e2e-provisioning')` at the file level and run via
  `composer test:e2e:provisioning`.

- **`e2e-feature`** — tests that start from prepared topology clones and verify
  ported commands, forwarding chains, or read-only behavior. They must not run
  installer or provisioning commands unless the feature under test explicitly
  requires it. These tests are grouped with `pest()->group('e2e-feature')` at the
  file level and run via `composer test:e2e:features`.

Both lanes still carry the umbrella `e2e` group via `tests/Pest.php`, so
`composer test:e2e` continues to run all ephemeral tests together.

Live or standing infrastructure smoke tests are sunset. Do not use persistent
 gateway, control, or app nodes as verification targets.

## Topology Kinds

The `e2e-feature` lane uses prepared topology clones. Choose the smallest topology
that covers the behavior under test:

| Kind | Nodes | Use when |
| --- | --- | --- |
| `control` | 1 control | Fastest. Use for control-node-only commands. |
| `control-gateway` | control + 1 gateway | Use for gateway trust, onboarding, or node-registry flows. |
| `control-gateway-dev` | control + gateway + 1 dev app | Use for app or workspace commands that need a development app node. |
| `control-gateway-dev-prod` | control + gateway + dev + 1 prod app | Full topology. Slowest but most realistic. Use for production-app flows or full-stack verification. |

## Commands

```bash
# Default in-memory Pest (excludes E2E)
composer test

# Ephemeral E2E lanes (requires ORBIT_E2E=1)
composer test:e2e:provisioning
composer test:e2e:features

# Prepare or replace a topology clone for the feature lane
composer e2e:prepare-topology -- --force control-gateway-dev-prod

# Reap stale Incus VMs and images created by E2E tests
composer e2e:reap-incus
```

## Environment

```bash
ORBIT_E2E=1                           # Enable ephemeral E2E tests
ORBIT_E2E_PROVIDER=incus              # Backend provider (incus is current)
ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2  # Comma-separated Incus host pool
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4    # VM quota per host
ORBIT_E2E_TOPOLOGY_STRATEGY=minimal   # Topology selection strategy
ORBIT_E2E_TOPOLOGY_RESET=fresh-clone  # Reset strategy for topology clones
```

Environment overrides for the legacy `bin/e2e` harness (still supported during
the transition):

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BLANK_IMAGE=orbit-blank-ubuntu-26.04
ORBIT_E2E_CONTROL_IMAGE=orbit-ready-control
ORBIT_E2E_GATEWAY_IMAGE=orbit-ready-gateway
ORBIT_E2E_DEVAPP_IMAGE=orbit-ready-devapp
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_CONTROL_USER=control
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```
