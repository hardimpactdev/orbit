# Testing

This directory is the authoritative lane map for Orbit verification. Read it
before adding, changing, debugging, or running E2E tests.

## Related product docs

Use these product docs when a verification lane depends on contracts outside
this testing tree.

- [Command contracts](../domains/README.md) — behavior contracts under test.
- [Machine-readable command catalog](../command-catalog.md) — catalog drift
  guards and regeneration.
- [Runtime execution lanes](../execution-lanes.md) — which lane a topology proof
  exercises.
- [Command UX](../ux/README.md) — admitted renderer and prompt primitives.
- [Quality gates](quality-gates.md) — gate baselines and retained evidence.
- [Security section pattern](../abstractions/17_security.md) — family-owned
  security issue keys used in doctor/probe coverage.

## Ownership

E2E is root-owned monorepo verification, not a gateway product surface. The only
public entry points are the root Composer scripts (`composer test:e2e` and its
provider variants); operators and CI do not need to know where the harness
lives.

`apps/e2e` implements the E2E harness. That application owns the harness, the
prepared-topology/provider support layer, the test suites driven externally, and
the runner itself (`e2e:test` plus the `e2e:prepare-*`, `e2e:reap-*`,
`e2e:preflight`, and `e2e:ensure-artifacts` commands). The root Composer E2E
scripts run through `apps/e2e` (via `bin/orbit-e2e-artisan` and `apps/e2e`
Pest); there is no E2E runner owned by the gateway. New S3/SeaweedFS E2E coverage
is added under `apps/e2e`, never under `apps/gateway/tests/E2E`.

A small set of unit tests in `apps/gateway/tests/Feature/E2ESupport` remain
gateway-owned. They assert gateway-resolved behavior of the shared support
classes through a temporary Composer PSR-4 bridge that maps
`App\E2E\Support\*` to `apps/e2e`, along with the gateway `update`/`update:all`
unit tests. See
`docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md` for the full
split and rationale.

`apps/e2e` is a stock Laravel 13 application. It is a full Laravel app so the
topology/provider harness can use the framework (the `Process`, filesystem, and
related facades, paths, and helpers) rather than re-implementing them.
"External" describes how it drives the system under test, not the absence of a
framework. It prepares Docker/Incus topologies, drives the Orbit CLI entry point
on each node and the gateway HTTP API, and inspects externally observable
results: CLI output, API responses, files, containers, routes, services, and
node state.

`apps/e2e` must not import the gateway application's own namespaces
(`apps/gateway`'s services, Eloquent models, controllers, jobs, or internal
actions) as the test subject. Gateway product behavior stays in `apps/gateway`
and is not moved into `packages/core` as a convenience dump for the E2E app;
small pure values the harness needs (for example container image identities) are
mirrored locally in `apps/e2e` and kept in sync.

When E2E support helpers are needed, place them under `apps/e2e` internal
support namespaces. Do not create `packages/e2e-support` or move helpers to
`packages/core` unless a separate architecture task approves that location.

## Verification model

Orbit has three supported verification lanes:

1. In-memory Pest tests for deterministic command, service, database, renderer,
   and contract coverage.
2. Retained topology proof for feature behavior that needs an integrated
   topology or real VM/runtime surface.
3. Manual prepared E2E backed by Docker or Incus when the user explicitly runs
   the Composer command from a shell.

Native Orbit Agent/Tauri app behavior uses the implementing Mac as the runtime
surface under lane 2. For non-Markdown `apps/macos` diffs, the finalization
packet records `Retained topology proof: passed - host topology kind=host-macos;
host=...; os=...; command=...; evidence=...`. This proves the macOS host running
the native app, not a retained Incus topology.

Provider provisioning is the on-demand artifact-preparation and installer
verification substrate behind those feature lanes. Docker and Incus provider
provision commands are explicit and can be delegated independently when each
provider actually changed:

- `composer test:e2e:provision:docker` rebuilds and distributes the Docker
  runtime/support images and prepared role images for the configured Docker
  host pool.
- `composer test:e2e:provision:incus` runs the fresh Incus VM provision gate:
  base VM, Orbit install, gateway provisioning, parallel `node:new` for app-dev,
  app-prod, and agent, then websocket runtime baking against app-dev Valkey.

`composer test:e2e:provision` is a convenience alias reserved for humans who
want to run both provider provision commands. Agents must not run the aggregate.
Agents also must not run E2E/provision test commands for a specific provider.

The normal feature order is focused Pest first, then retained topology proof
when behavior touches the integrated topology. Prepared E2E commands are
manual-only lanes; agents, skills, hooks, release flows, and default scripts do
not run or delegate them. Incus provision is the fresh VM gate for installer,
`node:new`, base image, WireGuard, systemd, package installation, and host
mutation changes only when the user explicitly invokes that Composer command.

Docker provision is not a normal post-`composer test:e2e` gate; it is a Docker
artifact refresh for runtime/support images, prepared role images, Docker host
artifact distribution, or Docker topology preparation changes. When production
artifact behavior matters and the user manually chooses an artifact lane, run
only the affected provider provision/artifact gate, then run the feature flow
that consumes the built CLI/gateway assets when that artifact-backed lane
exists.

Standing live infrastructure is not a test lane. Do not use persistent gateway,
operator, or app nodes as verification targets. The Orbit Agent host-Mac proof
is different: the implementing Mac is the native app runtime under test, not a
standing fleet node.

For the MONO local-executor migration, prepared Docker and Incus E2E are the
primary verification paths. Standing live infrastructure is diagnostic only.
Docker feature tests backed by a source mount, together with retained Docker or
retained/live source-mounted Incus topologies, form the fast feedback loops.
Production installs remain a separate native CLI binary artifact lane. In
source-mounted nodes,
`/usr/local/bin/orbit` points directly at `<source>/apps/cli/orbit`, mutable
node-local Orbit state lives under `~/.config/orbit`, and executor operation
tokens are verified through the gateway API so nodes do not carry executor
token signing material.

## Pest versions

Orbit uses Pest for PHP/Laravel in-memory coverage. All active Composer
projects run the Pest 5 / PHPUnit 13 line:

| Project | Pest line | Notes |
| --- | --- | --- |
| `apps/gateway` | Pest 5 + `pest-plugin-laravel` 5 | PHPUnit 13 |
| `apps/docs` | Pest 5 + `pest-plugin-laravel` 5 | PHPUnit 13; runtime `php` stays `^8.3` (Pest 5 is `require-dev` only and needs PHP ≥ 8.4 at test time) |
| `apps/e2e` | Pest 5 + `pest-plugin-laravel` 5 | PHPUnit 13 (safe in-memory suite; external E2E groups remain manual) |
| `apps/cli` | Pest 5 + `pest-plugin-laravel` 5 | PHPUnit 13; Laravel Zero 13 allows Symfony Process ^7.4.13\|^8.1, ending the former Pest 4 exception |
| `packages/core` | Pest 5 | PHPUnit 13 (transitive) |
| `packages/sdk` | Pest 5 | PHPUnit 13 (transitive) |

`apps/cli` additionally carries `illuminate/validation` in `require-dev`: the
Laravel Zero foundation test teardown loads `FormRequest` whenever
`illuminate/http` is installed, and the CLI ships `illuminate/http` without the
full framework.

A focused architecture guard in the gateway suite asserts all six Pest 5
manifests.

## Development lane invariant

These rules order the lanes above into a development workflow:

- Focused in-memory Pest tests are the fast feature loop. Incus or Docker
  topologies retained with a source mount are the default integrated-topology
  proof when the feature needs real topology behavior. The exception is native
  Orbit Agent/Tauri behavior, where the matching integrated proof target is the
  implementing Darwin host running the macOS app.
- Retained prepared topologies are for feature proof, manual diagnosis,
  debugging, and performance testing. They use the same prepared-topology
  substrate, are not standing live infrastructure, and must be explicitly
  released or reaped after use. Acquire Incus with
  `composer e2e:incus -- --start --topology=<kind>` and Docker with
  `composer e2e:dev-topology -- --provider=docker --kind=<kind>`. Reap Incus
  with `composer e2e:incus -- --stop --id=<id>` or Docker with
  `composer e2e:dev-topology:release -- <id>`. Refresh the current checkout in a
  running retained Incus topology with `composer e2e:incus -- --sync --id=<id>`;
  the sync is one-way from the initiating worktree to the runner-host source
  mount and then to each VM-local runtime overlay. Keep the local worktree as
  source of truth, run commands from the retained VM's runtime overlay, and treat
  VM-side edits as disposable unless explicitly copied back. See
  [prepared topologies — retained dev topologies](e2e/prepared-topologies.md#retained-dev-topologies).
- Findings from a retained topology should be codified back into focused Pest
  coverage when a deterministic assertion exists. Keep the retained topology
  transcript or artifact as the integrated-topology proof for the feature.
- Binary/runtime artifact verification is a separate release-candidate lane that
  runs after source-mounted lanes pass. It builds the native CLI binary and
  Docker runtime image, publishes a `topology-candidate` manifest to a
  topology-reachable artifact source, activates the desired manifest on a stable
  candidate channel, and catches packaging and installer regressions before
  GitHub release publication. Live release-candidate acceptance sets the
  gateway source with `orbit manifest:update <url>`, runs `update:all` against
  that candidate channel, and clears the source with `orbit manifest:remove`
  when the gateway should return to GitHub. GitHub release creation is only the
  promotion of the exact tested assets.
- Provisioning proves installer and `node:new` provisioning behavior only when
  the user explicitly runs that Composer command as substrate/artifact
  verification. It is not the inner development loop.

The retained-topology command surface lives in `apps/e2e`
(`composer e2e:incus`, `composer e2e:dev-topology`, and
`composer e2e:dev-topology:release`). It is not added as new public gateway
Artisan surface; public operator commands remain owned by the Orbit CLI surface.

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
| Quality-gate lanes, baselines, and retained evidence under `.orbit/quality-gates/` | [Quality gates](quality-gates.md) |

## Commands

These commands are the normal entry points for local verification.

```bash
# Default in-memory Pest suite
composer test

# Documentation quality gate
composer docs-lint

# Broad local quality gate
composer quality-check

# Focused Orbit Agent headless service checks
hostname
uname -s
sw_vers
cd apps/agent && cargo test
cd apps/agent && cargo fmt -- --check
cd apps/agent && cargo check
cd apps/agent && cargo clippy --all-targets -- -D warnings

# Focused Orbit Agent macOS UI Cargo/Tauri checks
cd apps/macos && cargo test
cd apps/macos && cargo fmt -- --check
cd apps/macos && cargo check
cd apps/macos && cargo clippy --all-targets -- -D warnings

# Manual prepared-topology feature E2E
composer test:e2e
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:topology-contract

# Manual provider artifact/provision verification
composer e2e:preflight
composer test:e2e:provision:docker
composer test:e2e:provision:incus
```

Run the narrowest useful check while developing. For the headless Orbit Agent
service, run focused Cargo checks from `apps/agent`. For the macOS tray UI, run
focused Cargo/Tauri checks from `apps/macos`. Broad `composer quality-check`
includes both Rust surfaces for handoff. For native `apps/macos` diffs, also
capture the host-Mac topology row from the implementing Darwin host and use
Computer Use when native tray/menu rendering is in scope.

Run `composer quality-check` before handing off a broad code change. When
behavior touches the integrated topology, capture retained topology proof for
the matching topology and provider.

Provisioning and Docker artifact/image/preparer commands run only when the user
explicitly invokes the matching Composer command from a shell.
Agents must not run any `composer test:e2e*` command, including the aggregate
`composer test:e2e:provision`; those commands are reserved for user-run checks.
