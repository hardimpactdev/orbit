# E2E testing

Use E2E when the behavior depends on a prepared topology, gateway API and CA
trust, registry-backed command behavior, real VM behavior, or provisioning.

## Commands

Use these commands to run the aggregate lane or one provider-specific lane.

```bash
# Prepared-topology feature aggregate
composer test:e2e

# Provider-specific prepared-topology lanes
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:topology-contract

# Superset provisioning lane
composer e2e:preflight
composer test:e2e:provision:docker
composer test:e2e:provision:incus

# Human-only aggregate for both provider provision commands
composer test:e2e:provision

# Explicit artifact planning/preparation
composer e2e:ensure-artifacts
```

Run prepared-topology feature E2E before provider provision gates. The feature
lanes consume prepared source artifacts and prove behavior against the current
checkout. Provider provision gates are final verification for fresh installer,
`node:new`, image, runtime artifact, and host mutation paths. When a change also
affects production artifacts, prove the feature against source-prepared
topologies first, run the provider provision gate, then run the feature flow
that consumes the built CLI/gateway assets when that artifact-backed lane
exists.

`composer test:e2e` runs `bin/orbit-gateway-artisan e2e:test`, which selects prepared-topology
lanes from `ORBIT_E2E_LANES` (`docker,incus` by default) and excludes
`e2e-provision`. Every selected lane must have its prepared artifacts, runner
capacity, and provider hosts available before feature tests start. Missing
Docker role images, support images, Incus templates, Incus snapshots, or runner
capacity fail the command. Missing artifacts include a scoped
`composer e2e:ensure-artifacts` command; feature lanes never run preparation
implicitly. Docker and Incus lanes run concurrently by default. Pass
`--sequential-lanes` for single-lane debugging output and
`--sequential-tests` when selected Pest files should run in one process.

## Provider capability matrix

Use this matrix to choose Docker only when the provider has the capability under
test.

| Capability | Docker | Incus |
| --- | --- | --- |
| Gateway API and CA trust | yes | yes |
| Registry-backed command behavior | yes | yes |
| Docker process runtime backend | yes | yes |
| Explicit Supervisor residual runtime | conditional | yes |
| Orbit Scheduler daemon | yes | yes |
| Real WireGuard interfaces and peer routing | no | yes |
| VM boot, cloud-init, package install mutation | no | yes |
| Real SSH daemon and sudo behavior | no | yes |
| OS trust-store mutation | no | yes |
| Host init on the node itself | no | yes |

## Provider selection

Use Docker for feature tests whose correctness depends on gateway API, CA trust,
registry state, command forwarding, current-checkout command behavior, the Docker
process runtime backend, the `orbit-scheduler` service, or
Orbit-managed process and schedule lifecycle. This is the default for
prepared-topology `e2e-feature` tests.

Use Incus for tests whose correctness depends on real VM behavior: WireGuard
kernel networking, cloud-init, package installation, OS trust-store mutation,
real SSH daemon behavior, sudo prompts, or host init. Mark these tests with
`e2e-provider-incus` so Docker-only runs skip them without probing an unsuitable
provider.

Provisioning and installer changes finish with provider-specific provision
commands after the relevant prepared-topology feature lane is green. Docker
image/runtime changes belong in `composer test:e2e:provision:docker`. Incus VM,
WireGuard, installer, `node:new`, and host-mutation changes belong in
`composer test:e2e:provision:incus`. Feature tests use prepared topology clones.

## Lane examples

Use these examples when a feature could fit more than one lane.

| Test concern | Lane | Why |
| --- | --- | --- |
| CLI validation, JSON envelopes, renderers, DTOs, authorization branches | In-memory Pest | Deterministic contract behavior; fake external boundaries. |
| Gateway API forwarding, CA trust, registry reads/writes, Saloon paths | Docker feature | Prepared topology is enough; fast and parallelizable. |
| Docker-backed tool intent and compose command generation | Docker feature | The command contract is gateway intent plus generated Docker command. |
| Runtime backend, process lifecycle, scheduler tick/heartbeat | Docker feature | Docker topologies run `orbit-gateway`, runtime containers, and `orbit-scheduler`. |
| Host-init service control and journal behavior | Incus VM-feature | Requires real systemd and host init semantics. |
| OS package installs, trust-store mutation, sudo, real SSH daemon behavior | Incus VM-feature | Depends on VM and OS behavior Docker does not model. |
| Docker runtime image, support image, or prepared role image changes | Docker provision | Refreshes the Docker substrate consumed by Docker feature tests. |
| Base image, installer, superset topology preparation, `node:new`, WireGuard routing | Incus provision | Proves the fresh VM provision path used to build reusable Incus artifacts. |

When in doubt, start with Docker feature. Move to Incus only when the assertion
would be false confidence in Docker because the kernel, VM boot, host init, or
OS package/trust layer is the behavior under test.

## E2E lanes

The ephemeral E2E suite is split into two explicit Pest group lanes:

- `e2e-feature` starts from prepared topology clones and verifies ported
  commands, forwarding chains, or read-only behavior. Run Docker-eligible feature
  tests with `composer test:e2e:docker`; run Incus-only feature tests with
  `composer test:e2e:incus`. The aggregate `composer test:e2e` runs both
  prepared-topology feature lanes and fails if either selected provider cannot
  supply the required prepared topology.
- `composer test:e2e:provision:docker` refreshes the Docker runtime/support
  images and prepared role images used by the Docker feature lane.
- `composer test:e2e:provision:incus` runs the `e2e-provision` Pest group in an
  isolated namespace. It launches a fresh base VM, installs Orbit on the
  operator, provisions the gateway, runs `node:new` for app-dev, app-prod, and
  agent in parallel, and bakes websocket against app-dev Redis. Run this after
  the source-prepared Incus feature lane, not before it.

`composer test:e2e:provision` is a human-only aggregate alias for both provider
provision commands. Agents must never run it; agents choose
`composer test:e2e:provision:docker` or `composer test:e2e:provision:incus`.

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

Provider provision gates are intentionally on demand because they mutate or
prove expensive prepared artifacts and are much slower than prepared-topology
feature tests. Treat them as final verification gates for provider substrate and
production-artifact changes.

## Source-Dev And Artifact-Prod

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
