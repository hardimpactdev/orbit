# Testing

This directory is the authoritative lane map for Orbit verification. Read it
before adding, changing, debugging, or running E2E tests.

## Ownership

E2E is root-owned monorepo verification, not a gateway product surface. The only
public entry points are the root Composer scripts (`composer test:e2e` and its
provider variants); operators and CI do not need to know where the harness
lives.

The harness is migrating out of `apps/gateway/tests/E2E` into a dedicated
`apps/e2e` application. The topology/provider contract suite and its support
layer now live in `apps/e2e`; the remaining app/node/resource/infra E2E suites
move in later pre-S3 batches. Completing this extraction is a pre-S3
requirement: S3/RustFS E2E coverage starts in `apps/e2e`, not in the gateway
harness. During the migration the gateway runner keeps working through a
temporary Composer PSR-4 bridge that resolves `App\E2E\Support\*` from
`apps/e2e`.

`apps/e2e` is a stock Laravel 13 application that hosts the external Orbit
runner. It is a full Laravel app so the topology/provider harness can use the
framework (the `Process`, filesystem, and related facades, paths, and helpers)
rather than re-implementing them. "External" describes how it drives the system
under test, not the absence of a framework: it prepares Docker/Incus topologies,
drives `apps/cli/orbit` and the gateway HTTP API, and inspects externally
observable results — CLI output, API responses, files, containers, routes,
services, and node state. It must not import the gateway application's own
namespaces (`apps/gateway`'s services, Eloquent models, controllers, jobs, or
internal actions) as the test subject. Gateway product behavior stays in
`apps/gateway` and is not moved into `packages/core` as a convenience dump for
the E2E app; small pure values the harness needs (for example container image
identities) are mirrored locally in `apps/e2e` and kept in sync.

During the migration, immediate E2E support helpers live under `apps/e2e`
internal support namespaces. Do not create `packages/e2e-support` or move
helpers to `packages/core` unless a separate architecture task approves that
location later.

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

## Development lane invariant

These rules order the lanes above into a development workflow:

- Source-checkout E2E (the Docker/Incus prepared-topology lanes that overlay the
  current checkout) is the normal feature feedback loop.
- Retained prepared topologies are for manual diagnosis and debugging only. They
  use the same prepared-topology substrate, are not standing live infrastructure,
  and must be explicitly released or reaped after use.
- Findings from a retained topology are codified back into ordinary
  prepared-topology Pest E2E tests; the durable assertion lives in Pest, not in a
  kept-alive topology.
- Binary acceptance is a separate release-candidate lane that runs after
  source-checkout E2E has passed. It proves the built CLI artifact and does not
  replace the source-checkout feature loop.
- Provisioning proves installer and `node:new` provisioning behavior, not the
  inner development loop.

Any retained-topology command surface is delivered later as part of the
`apps/e2e` extraction chain. It is not added as new public gateway Artisan
surface; public operator commands remain owned by `apps/cli/orbit`.

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
