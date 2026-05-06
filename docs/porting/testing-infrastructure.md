# Testing Infrastructure

## E2E driver rubric

Every command-port E2E picks one of four lanes. The choice is mechanical:
match the test's needs to the lane's capabilities.

- **Docker feature** (default).
  - Helper: `e2eTopology(E2ETopologyKind::…)` from `tests/E2E/Support/Pest.php`.
  - Group: `pest()->group('e2e-feature', …)`.
  - Run: `composer test:e2e -- --filter='<Scenario>'`.
  - Use when the test exercises gateway/control flow, SSH between Docker
    instances, Supervisor + scheduler inside containers, or any product
    behavior that does not require host init or kernel networking.
- **Incus VM-feature.**
  - Helper: `e2eVmTopology(E2ETopologyKind::…)` requiring
    `E2ETopologyCapabilities::vm()`.
  - Group: `pest()->group('e2e-feature', …)`.
  - Run: same `composer test:e2e` command; the helper auto-skips on Docker.
  - Use only when the assertion needs real systemd, kernel networking,
    `iptables`/`nftables`, host init, trust-store mutation, or other
    VPS-realism behavior.
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
`test:e2e:provision`, `test:e2e:topology-contract`. Artisan helpers:
`e2e:prepare-base-image`, `e2e:prepare-topology`,
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

- **`bin/install-orbit`** — local control/gateway/app host prerequisite
  installer for Ubuntu and macOS. Installs PHP 8.5, Composer, Git, Orbit
  source, SQLite migrations, and the `orbit` symlink. Ubuntu gateway
  installs include WireGuard, Caddy, and PHP-FPM. Ephemeral E2E targets
  Ubuntu 26.04.
- **`bin/e2e-provision-node` + `bin/_e2e-deps.sh`** — per-run provisioner
  used by both `e2e:prepare-base-image` and `IncusTopologyBuilder` so the
  base image and topology stay byte-identical.
- **`IncusBaseImagePreparer`, `IncusTopologyBuilder`, `IncusHost`,
  `DockerHost`, `DockerTopologyBuilder`** under `app/E2E/Support/` provide
  the topology providers behind `e2eTopology()` / `e2eVmTopology()`.

## Setup playbook

Refresh the base image when apt/system dependencies change. Incus
provisioning runs on Beast only:

```bash
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast \
ORBIT_E2E_INCUS_HOSTS=beast \
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1 \
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
ORBIT_E2E_EXCLUSIVE_HOSTS=beast \
composer e2e:prepare-base-image -- --force
```

Rebuild the prepared topology from the current checkout when feature
assertions need fresh baseline command code:

```bash
ORBIT_E2E_HOST=beast \
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e \
composer e2e:prepare-topology -- --force control-gateway-dev-prod
```

Run feature tests with `composer test:e2e` (Docker default, Pest parallel,
checkout overlay per test). Narrow to a scenario with `--filter='<Name>'`.
Run provisioning tests with `composer test:e2e:provision -- --filter=<X>`
and leave failed VMs for inspection.
