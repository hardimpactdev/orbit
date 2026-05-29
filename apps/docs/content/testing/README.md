# Testing

This directory is the authoritative lane map for Orbit verification. Read it
before adding, changing, debugging, or running E2E tests.

## Verification model

Orbit has three supported test lanes:

1. In-memory Pest tests for deterministic command, service, database, renderer,
   and contract coverage.
2. Feature E2E backed by Docker for prepared-topology command, registry, gateway
   API, and CA trust coverage.
3. The Incus provisioning gate for the reusable superset topology: fresh base
   VM, Orbit install, gateway provisioning, parallel `node:new` for app-dev,
   app-prod, and agent, then websocket runtime baking against app-dev Redis.

Standing live infrastructure is not a test lane. Do not use persistent gateway,
operator, or app nodes as verification targets.

For the MONO local-executor migration, prepared Docker and Incus E2E are the
primary verification paths. Standing live infrastructure is diagnostic only.
Prepared topology images provide Orbit-capable hosts, including the host PHP CLI
needed by the CLI/local-executor artifact. The current checkout is synced into
hosts during topology preparation or test setup.

## Lane map

Use this table to choose the smallest documentation page that matches the
change.

| Concern | Start here |
| --- | --- |
| Ordinary command, service, database, renderer, and contract behavior | [In-memory tests](in-memory/README.md) |
| Fast Pest feedback and default suite performance | [In-memory performance](in-memory/performance.md) |
| Prepared topology E2E lanes, provider selection, and command aliases | [E2E testing](e2e/README.md) |
| Superset provisioning, image, installer, and topology-preparation flows | [E2E provisioning](e2e/provisioning.md) |
| Prepared topology contracts and checkout overlay behavior | [Prepared topologies](e2e/prepared-topologies.md) |
| Docker feature host pools and Docker topology behavior | [Docker E2E](e2e/docker.md) |
| Incus VM-feature behavior and host capacity | [Incus E2E](e2e/incus.md) |
| E2E environment variables and lease namespaces | [E2E environment](e2e/environment.md) |
| E2E timing baselines and resource diagnostics | [E2E performance](e2e/performance.md) |

## Commands

These commands are the normal entry points for local verification.

```bash
# Default in-memory Pest suite
composer test

# Documentation quality gate
composer docs-lint

# Broad local quality gate
composer quality-check

# Prepared-topology feature E2E
composer test:e2e
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:topology-contract

# Superset provisioning E2E
composer e2e:preflight
composer test:e2e:provision
```

Run the narrowest useful check while developing. Run `composer quality-check`
before handing off a broad code change. When behavior touches the integrated
topology, run the E2E lane for the matching prepared topology and provider.
Provisioning changes belong in `composer test:e2e:provision`, which proves the
single Incus superset build path.
