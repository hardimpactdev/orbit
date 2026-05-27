# E2E Prepared Topology Contract

> **For agentic workers:** Use `docs/testing/README.md` and
> `docs/testing/e2e/**` as authority before changing E2E code, tests, or
> prepared artifacts.

**Goal:** Keep E2E fast by building reusable role artifacts once, then composing
the smallest prepared topology each test needs.

**Architecture:** Feature E2E uses prepared Docker images and prepared Incus
role templates. The provisioning gate proves the reusable Incus superset build
path.

**Tech Stack:** Laravel 13, Pest 4, Docker, Incus VMs,
`orbit-base-ubuntu-26.04`, `bin/install-orbit`, `bin/e2e-provision-node`, and
the current Composer cache strategy.

## Current Shape

Docker feature tests use composable role images:

- `orbit-e2e:operator_base`
- `orbit-e2e:gateway_base`
- `orbit-e2e:app-dev_base`
- `orbit-e2e:app-prod_base`
- `orbit-e2e:agent_base`

Docker branch or worktree overrides use the same role tag shape with a custom
artifact set, for example `orbit-e2e:agent_agent-isolation`. The provider
resolves each role independently and falls back to the matching `_base` image
when a role-specific override is absent.

Incus feature tests use `orbit-base-ubuntu-26.04` as the only supported base
image and prepared role templates:

- `orbit-template-operator-base`
- `orbit-template-gateway-base`
- `orbit-template-app-dev-base`
- `orbit-template-app-prod-base`
- `orbit-template-agent-base`

Prepared Incus snapshots use `clean-<source-topology>-<artifact-set>`. Feature
tests boot only the roles required by the selected topology.

## Provision Gate

`composer test:e2e:provision` runs one Incus superset provisioning test:

1. Launch a fresh VM from `orbit-base-ubuntu-26.04`.
2. Install Orbit from the current source bundle on the operator.
3. Provision the gateway through the real gateway path.
4. Run `node:new` for app-dev, app-prod, and agent in parallel.
5. Snapshot the resulting role templates for prepared Incus feature tests.

Command-specific behavior belongs in in-memory Pest tests or prepared-topology
feature tests. The provision gate is for topology, installer, image, `node:new`,
WireGuard provisioning, and VM setup behavior.

## Cache Contract

Docker preparation uses a lockfile-keyed Composer cache volume by default.
`ORBIT_E2E_DOCKER_COMPOSER_CACHE` may bind a build-host cache directory.

Incus preparation stages `~/.cache/orbit-e2e/composer` into the provisioning
bundle when present. `composer e2e:prepare-topology` also accepts
`--composer-cache=<dir>` for an explicit cache directory.

## Verification

Use these gates for prepared topology changes:

```bash
composer test:e2e:topology-contract
composer test:e2e
```

Use this gate for provisioning contract changes:

```bash
composer test:e2e:provision
```
