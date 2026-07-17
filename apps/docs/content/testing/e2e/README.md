# E2E testing

Prepared E2E is a manual command surface. Agents, skills, hooks, release flows,
and default scripts must not run or delegate `composer test:e2e*` commands.
Use retained topology proof for ordinary feature verification when behavior
depends on a topology, gateway API and CA trust, registry-backed command
behavior, real VM behavior, or provisioning.

## Commands

These commands remain available when the user explicitly runs the aggregate lane
or one provider-specific lane from a shell.

```bash
# Prepared-topology feature aggregate
composer test:e2e

# Provider-specific prepared-topology lanes
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:topology-contract

# On-demand provider artifact/provision gates
composer e2e:preflight
composer test:e2e:provision:docker
composer test:e2e:provision:incus

# Human-only aggregate for both provider provision commands
composer test:e2e:provision

# Explicit artifact planning/preparation
composer e2e:ensure-artifacts

# Stale provider resource inventory and cleanup
composer e2e:reap-docker
composer e2e:reap-incus
```

When the user manually chooses E2E, feature E2E backed by prepared topologies
should run before any provider artifact/provision gate. The feature lanes
consume prepared source artifacts and prove behavior against the current
checkout. Incus provision is the fresh VM gate for installer, `node:new`, base
image, WireGuard, systemd, package installation, and host mutation paths.

Docker provision is not a normal post-`composer test:e2e` gate; it refreshes
Docker runtime/support images, prepared role images, Docker host artifact
distribution, or Docker topology-preparation artifacts. When the user also
checks production artifacts, prove the feature against source-prepared
topologies first. Then run only the affected provider artifact/provision gate
and the artifact-backed feature flow when that lane exists.

`composer test:e2e` runs `bin/orbit-e2e-artisan e2e:test`, which selects prepared-topology
lanes from `ORBIT_E2E_LANES` (`docker,incus` by default) and excludes
`e2e-provision`. Every selected lane must have its prepared artifacts, runner
capacity, and provider hosts available before feature tests start. Missing
Docker role images, support images, Incus templates, Incus snapshots, or runner
capacity fail the command. Missing artifacts include a scoped
`composer e2e:ensure-artifacts` command; feature lanes never run preparation
implicitly. Docker and Incus lanes run concurrently by default. Pass
`--sequential-lanes` for single-lane debugging output and
`--sequential-tests` when selected Pest files should run in one process.

The Docker lane probes configured test runners immediately before execution.
Unreachable runners are ignored only when the remaining reachable capacity still
meets `ORBIT_E2E_DOCKER_MIN_PROCESSES`. The default minimum is the lower of
eight workers and the planned Docker worker count. Restore the unavailable
runners before comparing E2E duration to the baseline, or lower
`ORBIT_E2E_DOCKER_MIN_PROCESSES` only for an intentionally degraded diagnostic
run.

When the aggregate selects both Docker and Incus, Docker ignores hosts that are
configured as Incus hosts before probing runner reachability. That keeps Docker
workers off Beast while the Incus lane is selected, including
`--sequential-lanes` aggregate runs where only one lane executes at a time.

## Provider capability matrix

Use this matrix to choose Docker only when the provider has the capability under
test.

| Capability | Docker | Incus |
| --- | --- | --- |
| Gateway API and CA trust | yes | yes |
| Registry-backed command behavior | yes | yes |
| Systemd process runtime backend | no | yes |
| Orbit Scheduler daemon | yes | yes |
| Real WireGuard interfaces and peer routing | no | yes |
| VM boot and package install mutation | no | yes |
| Real SSH daemon and sudo behavior | no | yes |
| OS trust-store mutation | no | yes |
| Host init on the node itself | no | yes |

## Provider selection

Use Docker for feature tests whose correctness depends on gateway API, CA trust,
registry state, command forwarding, current-checkout command behavior,
Docker-runtime process behavior, the `orbit-scheduler` service, or
Orbit-managed process and schedule lifecycle. This is the default for
prepared-topology `e2e-feature` tests. Docker can test command contracts,
registry state, validation, and Docker-runtime process behavior for process
features, but it must not claim real `systemd` lifecycle coverage.

Use Incus for tests whose correctness depends on real VM behavior: WireGuard
kernel networking, package installation, OS trust-store mutation, real SSH
daemon behavior, sudo prompts, host init, or `systemd` service lifecycle. Mark
these tests with `e2e-provider-incus` so Docker-only runs skip them without
probing an unsuitable provider.

When the user manually runs provisioning checks, run the Incus provision command
after the matching prepared-topology feature lane is green. Docker
image/runtime, prepared role image, Docker host artifact distribution, and
Docker topology preparer checks use `composer test:e2e:provision:docker`. Incus
VM, WireGuard, installer, `node:new`, and host-mutation checks use
`composer test:e2e:provision:incus`. Feature tests use prepared topology clones.

## Lane examples

Use these examples when a feature could fit more than one lane.

| Test concern | Lane | Why |
| --- | --- | --- |
| CLI validation, JSON envelopes, renderers, DTOs, authorization branches | In-memory Pest | Deterministic contract behavior; fake external boundaries. |
| Gateway API forwarding, CA trust, registry reads/writes, Saloon paths | Docker feature | Prepared topology is enough; fast and parallelizable. |
| Docker-backed tool intent and compose command generation | Docker feature | The command contract is gateway intent plus generated Docker command. |
| Runtime backend, process lifecycle, scheduler tick/heartbeat | Docker feature | Docker topologies run `orbit-gateway`, runtime containers, and `orbit-scheduler`. |
| Systemd-backed process lifecycle, such as OpenCode or PolyScope servers | Incus VM-feature | Requires real `systemd`, `systemctl`, and journal semantics. |
| Host-init service control and journal behavior | Incus VM-feature | Requires real systemd and host init semantics. |
| OS package installs, trust-store mutation, sudo, real SSH daemon behavior | Incus VM-feature | Depends on VM and OS behavior Docker does not model. |
| Docker runtime image, support image, or prepared role image changes | Docker provision | Refreshes the Docker substrate consumed by Docker feature tests. |
| Base image, installer, superset topology preparation, `node:new`, WireGuard routing | Incus provision | Proves the fresh VM provision path used to build reusable Incus artifacts. |

When the user needs an E2E pass and the provider is unclear, Docker feature is
the smaller manual lane. Incus is only needed when the assertion would be false
confidence in Docker because the kernel, VM boot, host init, or OS package/trust
layer is the behavior under test.

## E2E lanes

The ephemeral E2E suite is split into two explicit Pest group lanes:

- `e2e-feature` starts from prepared topology clones and verifies ported
  commands, forwarding chains, or read-only behavior. Docker-eligible feature
  tests use `composer test:e2e:docker`; Incus-only feature tests use
  `composer test:e2e:incus`. The aggregate `composer test:e2e` runs both
  prepared-topology feature lanes and fails if either selected provider cannot
  supply the required prepared topology.
- `composer test:e2e:provision:docker` rebuilds and distributes the Docker
  runtime/support images and prepared role images used by the Docker feature
  lane. It is an artifact refresh, not a behavioral provisioning proof after an
  ordinary `composer test:e2e` pass.
- `composer test:e2e:provision:incus` runs the `e2e-provision` Pest group in an
  isolated namespace. It launches a fresh base VM, installs Orbit on the
  operator, provisions the gateway, runs `node:new` for app-dev, app-prod, and
  agent in parallel, and bakes websocket against app-dev Valkey. For a manual
  full Incus proof, this follows the Incus feature lane backed by a prepared
  source.

`composer test:e2e:provision` is a human-only aggregate alias for both provider
provision commands. Agents must never run it. Agents also must not run E2E test
commands for a specific provider.

Each prepared topology has its own contract group:

| Topology | Contract group | Feature group |
| --- | --- | --- |
| `operator` | `e2e-topology-contract-operator` | `e2e-feature-operator` |
| `operator_gateway` | `e2e-topology-contract-operator_gateway` | `e2e-feature-operator_gateway` |
| `operator_gateway_app-dev` | `e2e-topology-contract-operator_gateway_app-dev` | `e2e-feature-operator_gateway_app-dev` |
| `operator_gateway_app-dev_app-prod` | `e2e-topology-contract-operator_gateway_app-dev_app-prod` | `e2e-feature-operator_gateway_app-dev_app-prod` |
| `operator_gateway_app-dev_app-prod_ingress` | `e2e-topology-contract-operator_gateway_app-dev_app-prod_ingress` | `e2e-feature-operator_gateway_app-dev_app-prod_ingress` |
| `operator_gateway_agent` | `e2e-topology-contract-operator_gateway_agent` | `e2e-feature-operator_gateway_agent` |
| `operator_gateway_app-prod_ingress` | `e2e-topology-contract-operator_gateway_app-prod_ingress` | `e2e-feature-operator_gateway_app-prod_ingress` |
| `operator_gateway_app-dev_websocket` | `e2e-topology-contract-operator_gateway_app-dev_websocket` | `e2e-feature-operator_gateway_app-dev_websocket` |
| `operator_gateway_app-dev_app-prod_websocket` | `e2e-topology-contract-operator_gateway_app-dev_app-prod_websocket` | `e2e-feature-operator_gateway_app-dev_app-prod_websocket` |
| `operator_gateway_app-dev_app-prod_agent_websocket` | `e2e-topology-contract-operator_gateway_app-dev_app-prod_agent_websocket` | `e2e-feature-operator_gateway_app-dev_app-prod_agent_websocket` |

`composer test:e2e:topology-contract` proves the Docker
`operator_gateway_app-dev_app-prod_agent_websocket` topology contract. It is a
quick topology health check that boots every current prepared workload role,
while `composer test:e2e` excludes topology-contract tests and runs feature
assertions only.

Provider artifact/provision gates are intentionally on demand because they
mutate or prove expensive prepared artifacts and are much slower than
prepared-topology feature tests. Treat Docker provision as Docker artifact
refresh only, and Incus provision as the fresh VM provisioning gate.

## Source-dev and artifact-prod

Orbit keeps development ergonomics and production artifact validation in
separate topology lanes:

- `source-dev` topologies copy or bind-mount the current worktree and may point
  `/usr/local/bin/orbit` directly at `<source>/apps/cli/orbit`. They are for
  fast iteration against live topology behavior and do not prove production
  artifacts.
- `artifact-prod` topologies use built CLI binaries, digest-pinned
  `orbit-gateway` images, release manifest snapshots, and production image
  acquisition. They validate that production artifacts work without an Orbit
  source checkout on gateway-only hosts.

Host PHP is required in production only on nodes with `app-dev` or `app-prod`
roles, where app-source workflows use Composer and Artisan against app code.
Gateway-only, router-only, vpn-only, database-only, websocket-only, S3-only, and
operator-only production nodes do not require host PHP or Composer.

Verification uses prepared topology lanes or the provisioning gate. Persistent
gateway, operator, and app nodes are diagnostic targets only.

## Details

Use the detail pages for provider setup, topology contracts, and performance
diagnostics.

- [E2E provisioning](provisioning.md)
- [Prepared topologies](prepared-topologies.md)
- [Docker E2E](docker.md)
- [Incus E2E](incus.md)
- [E2E environment](environment.md)
- [E2E performance](performance.md)
