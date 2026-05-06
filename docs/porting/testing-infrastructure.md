# Testing Infrastructure

## Workstream

- [x] Clean unit/feature test baseline.
- [x] Default `composer test` runs in-memory Pest and excludes E2E.
- [x] Standing infrastructure test lane removed.
- [x] Restore ephemeral E2E.
  - [x] Add Incus backend preflight on beast.
  - [x] Add disposable blank Ubuntu VM lifecycle check.
  - [x] Create a blank snapshot lane for `e2e-provision` tests.
  - [x] Add reusable host installer needed by the base/provisioner topology.
  - [x] Create a stable base image lane for `e2e-feature` tests.
  - [x] Create a prepared superset topology lane from the base image for
    control, gateway, development app, and production app roles.
  - [x] Add first-gateway provisioning E2E lane (`composer test:e2e:provision -- --filter='NodeNewGateway'`)
    that exercises `node:new --role=gateway` on disposable base-provisioned VMs
    (`e2e-provision`).
  - [x] Add control-node onboarding E2E lane (`composer test:e2e:provision -- --filter='GatewayAdd'`)
    that exercises `gateway:add` on disposable base-provisioned VMs
    (`e2e-provision`).
  - [x] Create prepared development app topology lane for `e2e-feature` tests.
  - [x] Create prepared production app topology lane for `e2e-feature` tests.
- [x] Add E2E topology for gateway + control + development app + production
  app nodes.
  - Prepared topology build:
    `composer e2e:prepare-topology -- --force control-gateway-dev-prod`.
  - Docker-backed container-safe feature lane:
    `composer test:e2e`.
  - Docker-backed full topology contract lane:
    `composer test:e2e:topology-contract`.
- [x] Docker-backed feature E2E exists for future app read-command porting.
  - Use `composer test:e2e -- --filter='AppList'` and
    `composer test:e2e -- --filter='AppShow'` once those tests exist.
  - This lane is the default feature E2E lane for app read commands. Keep
    provisioning, WireGuard, SSH trust, host mutation, and destructive flows in
    `e2e-provision`.
- [x] E2E-IMAGE-ARCH-1: stable base image + per-run Orbit provisioner
  - [x] Provisioner script `bin/e2e-provision-node` + apt-deps helper
    `bin/_e2e-deps.sh` (single source of truth shared with the base preparer).
  - [x] `IncusBaseImagePreparer` + `e2e:prepare-base-image` (hidden, with
    `--force` and `--json`).
  - [x] `composer e2e:prepare-base-image` script.
  - [x] `IncusHost::pushBundle` + `IncusHost::provisionInstance` for the
    per-run bundle path.
  - [x] `e2e:prepare-topology --force` builds the source archive once
    (or reuses one via `--branch=<ref>` / `--source-archive=<path>`),
    bundles `bin/install-orbit` + `bin/e2e-provision-node` + the
    `~/.cache/orbit-e2e/composer` cache, and forwards the bundle to the
    builder.
  - [x] `IncusTopologyBuilder` clones every role from
    `orbit-base-ubuntu-26.04` and runs the provisioner before
    authorize/network/ceremony.
  - [x] Drop role-specific `controlImage`/`gatewayImage`/dev/prod aliases
    from `E2EConfig`, `IncusE2EImagePreparationOptions`, and
    `e2e:prepare-incus-images` (now `--role=blank` only).
  - [x] One-shot Beast cleanup
    (`incus image delete orbit-ready-{control,gateway,devapp,prodapp}`).
  - [x] Wall-time check on Beast completed for
    `e2e:prepare-topology -- --force control-gateway-dev-prod`: first
    successful rebuild roughly 3m03s; timed warm rebuild `real 205.71s`.
    Cold target passed; warm target missed by about 26s. Follow-up
    instrumentation landed via Solo todo 298 and measured `real 219.33s`, with
    copy/start plus initial Incus agent readiness as the confirmed bottleneck.
    Because Docker is now the default feature E2E lane, Incus warm-topology
    optimization is treated as future performance work rather than a blocker
    for app read-command porting.
  - [x] Re-run topology contracts on Beast against the new lane
    (`control-gateway-dev-prod` contract passed with 28 assertions).
  - [x] Rework `e2e-provision` tests that previously launched from
    `E2EImage::Control`/`Gateway` (now refused by `IncusProvider::aliasFor`)
    to base + provisioner. Focused provider tests and stale-reference audit
    pass; the full Incus provision-lane run passed via
    `E2E-PROVISION-VERIFY-1` (todo 290).
- [ ] Add provisioning/destructive coverage only in the `e2e-provision` lane
  when working on provisioning, WireGuard, SSH trust, host mutation, or app
  write/destructive commands.

## Foundation pieces

- [x] Incus-backed ephemeral E2E harness (retired legacy shell harness; now
    Artisan commands + Pest E2E suite)
  - Current commands: `php artisan e2e:*`
  - Current docs: `TESTING.md`
  - Current test script: `composer test:e2e`
  - Bootstrap slice implemented: beast preflight, disposable Ubuntu cloud VM
    launch, ephemeral SSH key injection, SSH readiness check from beast, and VM
    cleanup.
  - Base image lane implemented: `composer e2e:prepare-base-image -- --force`
    builds the reusable `orbit-base-ubuntu-26.04` Incus image with stable
    system dependencies.
  - Provisioning lane implemented: `composer test:e2e:provision` runs
    installer and host-mutation checks on disposable VMs only. Keep it
    out of the default feature lane.
  - First-gateway provisioning lane implemented: `composer test:e2e:provision -- --filter='NodeNewGateway'`
    launches disposable VMs, runs `orbit node:new --role=gateway`, and verifies
    the gateway is provisioned under the steady-state `orbit` user with a
    working Orbit installation.
  - First-gateway WireGuard enrollment lane implemented:
    `composer test:e2e:provision -- --filter='NodeNewWireGuard'` verifies real
    gateway/control WireGuard interfaces, gateway peer persistence, gateway API
    reachability through the WireGuard address, and idempotent first-gateway
    convergence on disposable Incus VMs.
  - Prepared topology lanes implemented: `composer e2e:prepare-topology -- --force control-gateway-dev-prod`
    builds reusable Incus templates for control, gateway, development app, and
    production app roles; `composer test:e2e:topology-contract` verifies the
    prepared full topology contract before feature tests rely on it.
  - Docker feature topology lane implemented for container-safe E2E:
    `composer e2e:prepare-docker-runtime -- --force`,
    `composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod`,
    and `composer test:e2e`. The recommended local topology is sidecar1 and
    sidecar2 as the primary Docker feature pool, with Beast as Docker overflow
    only when it is not holding an Incus provisioning lease. Incus provisioning
    runs on Beast only (`ORBIT_E2E_INCUS_HOSTS=beast`,
    `ORBIT_E2E_INCUS_HOST_SLOTS=beast:1`,
    `ORBIT_E2E_EXCLUSIVE_HOSTS=beast`). Docker is the default feature provider;
    Incus remains the VM-realism lane for provisioning, WireGuard, systemd, SSH,
    package installation, trust-store, and VPS-adjacent behavior.
- [x] Orbit host installer
  - Current implementation: `bin/install-orbit`
  - Current tests: `tests/Feature/Commands/NodeNewCommandTest.php`
  - Bootstrap slice implemented: local control/gateway/app host prerequisite
    installer for Ubuntu and macOS that installs PHP, Composer, Git, Orbit
    source, SQLite database state, migrations, and the `orbit` symlink. Ubuntu
    installs PHP 8.5 by default when the native package is available, with the
    same Ondrej PHP PPA pattern used by old Orbit as a fallback. Ubuntu gateway
    installs include WireGuard, Caddy, and PHP-FPM for first-gateway runtime
    verification. Ephemeral E2E
    uses Ubuntu 26.04 because its native PHP 8.5 packages avoid depending on
    Launchpad reachability from Incus guests.
  - UX note: follows the command-designer human output shape with immediate
    step-tree progress, stable error codes, quiet default logs, and `--verbose`
    for underlying package and shell command output.
  - Porting note: this is the first user touch point before any Orbit command
    can run on a fresh control node. It does not create gateway-owned node
    identity or WireGuard material. The ready control E2E lane intentionally
    installs as a non-`orbit` user because real control machines are expected to
    be user-owned, while gateway and app provisioning must create or prepare the
    node-side `orbit` user when needed.

## Current E2E setup for porting work

Use this setup before promoting new command-port E2E gates:

1. Build or refresh the stable Incus base image when apt/system dependencies
   change. Incus provisioning E2E runs on Beast only:

   ```bash
   ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast \
   ORBIT_E2E_INCUS_HOSTS=beast \
   ORBIT_E2E_INCUS_HOST_SLOTS=beast:1 \
   ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
   ORBIT_E2E_EXCLUSIVE_HOSTS=beast \
   composer e2e:prepare-base-image -- --force
   ```

2. Rebuild the prepared superset topology from the current checkout whenever
   feature assertions need fresh baseline command code:

   ```bash
   ORBIT_E2E_HOST=beast \
   ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
   composer e2e:prepare-topology -- --force control-gateway-dev-prod
   ```

   The Beast `orbit-e2e` Incus storage pool is ZFS-backed and is the expected
   Incus pool for practical VM-backed feature E2E. Without
   `ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e`, clones fall back to the host's
   default storage and are much slower.

3. For feature ports, prefer the fast aggregate first:

   ```bash
   composer test:e2e
   ```

   This runs `e2e-feature` tests with Pest parallel mode, uses the Docker
   topology provider by default, acquires hosts through the shared `.env.e2e`
   lease pool, overlays the current checkout per test via the Pest helpers,
   and reuses the checkout cache for the process.

4. Add scenario-specific gates with Pest filters or groups only when the
   command truly needs a narrower topology contract:

   ```bash
   composer test:e2e -- --filter='DnsList'
   composer test:e2e -- --filter='NodeShowGrant'
   ```

5. Keep provisioning, installer, host mutation, and destructive setup flows out
   of the default feature lane. Pair those todos with
   `composer test:e2e:provision -- --filter=<test>` and leave failed VMs
   inspectable.

Docker remains the likely long-term default for fast feature regression
because container topology reset is cheaper and the runtime backend +
Orbit Scheduler run identically inside containers. Incus remains the
VM-realism lane for host init, real SSH, sudo, package installation, real
WireGuard, trust-store mutation, and VPS-adjacent behavior.
