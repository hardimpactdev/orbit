# Testing Infrastructure

## E2E driver rubric

Every command-port E2E picks one of four lanes. The choice is mechanical:
match the test's needs to the lane's capabilities.

- **Docker feature** (default).
  - Helper: `e2eTopology(E2ETopologyKind::…)` from `tests/E2E/Support/Pest.php`.
  - Group: `pest()->group('e2e-feature', …)`.
  - Run: `composer test:e2e:docker -- --filter='<Scenario>'`.
  - Use when the test exercises gateway/control flow, SSH between Docker
    instances, Supervisor + scheduler inside containers, or any product
    behavior that does not require host init or kernel networking.
- **Incus VM-feature.**
  - Helper: `e2eVmTopology(E2ETopologyKind::…)` requiring
    `E2ETopologyCapabilities::vm()`.
  - Group: `pest()->group('e2e-feature', 'e2e-provider-incus', …)`.
  - Run: `composer test:e2e:incus -- --filter='<Scenario>'`.
  - Use only when the assertion needs real systemd, kernel networking,
    `iptables`/`nftables`, host init, trust-store mutation, or other
    VPS-realism behavior. The helper skips when Incus or the required
    prepared topology is unavailable.
- **Incus provision.**
  - Group: `pest()->group('e2e-provision')`.
  - Run: `composer test:e2e:provision -- --filter='<Scenario>'`.
  - Use for installer, WireGuard enrollment, SSH trust establishment, and
    any destructive host mutation. Launches disposable base/blank VMs;
    leaves failed VMs inspectable by default.
- **`lane=none`.**
  - Only for docs-only, pure refactor, or no observable runtime behavior
    outside in-memory Pest. Record the reason in the workstream entry.

Docker is the default because container topology reset is cheap and the
runtime backend + scheduler run identically in containers. Incus carries
the real-host realism behavior; reach for it only when the rubric forces it.

## Status

E2E harness is complete. Both Docker and Incus topology providers are
available; provisioning tests run on Beast (Incus) and feature tests run on
the shared Docker pool by default. Composer scripts: `test`, `test:e2e`,
`test:e2e:docker`, `test:e2e:incus`, `test:e2e:provision`,
`test:e2e:topology-contract`. `test:e2e` runs the selected prepared-topology
feature lanes through `php artisan e2e:test`; `ORBIT_E2E_LANES` defaults to
`docker,incus`, and provisioning stays separate. Artisan helpers:
`e2e:prepare-incus-images`, `e2e:prepare-topology`,
`e2e:prepare-docker-runtime`, `e2e:prepare-docker-topology`.

Recent command-port lanes:

- [x] First-gateway provisioning: `composer test:e2e:provision -- --filter='NodeNewGateway'`.
- [x] First-gateway API verification: `composer test:e2e:provision -- --filter='NodeNewGatewayApiVerify'`.
- [x] First-gateway CA verification: `composer test:e2e:provision -- --filter='NodeNewGatewayCaVerify'`.
- [x] Gateway-owned development app provisioning: `composer test:e2e:provision -- --filter='NodeNewDevelopmentApp'`.
- [x] Gateway-owned production app provisioning: `composer test:e2e:provision -- --filter='NodeNewProductionApp'`.
- [x] Control-node gateway onboarding: `composer test:e2e:provision -- --filter='GatewayAdd'`.
- [x] Gateway trust repair: `composer test:e2e:provision -- --filter='GatewayTrust'`.

The only outstanding item is non-blocking:

- [ ] Add provisioning/destructive coverage to the `e2e-provision` lane as
  new provisioning, WireGuard, SSH trust, host mutation, or app
  write/destructive commands land.

## Foundation

- **`bin/install-orbit`** — local control-node installer for Ubuntu and
  macOS. Installs the runtime prerequisites Orbit needs to run and provision
  hosts (PHP 8.5, Composer, Git, OpenSSH client, WireGuard tooling,
  Supervisor/Caddy/PHP-FPM on Ubuntu), installs the Orbit source, runs SQLite
  migrations, and links `orbit` into the executable path. Gateway and app
  roles are created by `node:new`, not by installer flags.
- **`bin/e2e-provision-node`** — per-run control-template helper used by
  `IncusTopologyBuilder`. It installs Orbit on the control template from the
  current source bundle. Gateway and app templates are then created through
  real `node:new` calls from that control node.
- **`IncusTopologyBuilder`, `IncusHost`,
  `DockerHost`, `DockerTopologyBuilder`** under `app/E2E/Support/` provide
  the topology providers behind `e2eTopology()` / `e2eVmTopology()`.

## Setup playbook

Refresh the blank Incus image when the bootstrap image shape changes. Incus
provisioning runs on Beast:

```bash
ORBIT_E2E_HOST=beast \
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
php artisan e2e:prepare-incus-images --role=blank --force
```

Rebuild the prepared topology from the current checkout when feature
assertions need fresh baseline command code. This refreshes a staged chain of
role templates: control is installed from the blank image and snapshotted as
`clean-control`; gateway, development app, and production app are then added
through real `node:new` calls from the control node and snapshotted as
`clean-control-gateway`, `clean-control-gateway-dev`, and
`clean-control-gateway-dev-prod` respectively:

```bash
ORBIT_E2E_HOST=beast \
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
composer e2e:prepare-topology -- --force control-gateway-dev-prod
```

The force path streams `[orbit-e2e] <phase> started|done|failed` checkpoints to
STDERR, so a long topology refresh shows which phase currently owns the wait
without corrupting JSON output on STDOUT.

Run Docker feature tests with `composer test:e2e:docker -- --filter='<Name>'`
(Pest parallel, checkout overlay per test). Run Incus VM-feature tests with
`composer test:e2e:incus -- --filter='<Name>'`; they use prepared Incus
topology clones and skip when Incus is unavailable. Run the aggregate prepared
feature lane with `composer test:e2e`; override its lane set with
`ORBIT_E2E_LANES=docker`, `ORBIT_E2E_LANES=incus`, or
`ORBIT_E2E_LANES=docker,incus`. Run provisioning tests with
`composer test:e2e:provision -- --filter=<X>` and leave failed VMs for
inspection.
