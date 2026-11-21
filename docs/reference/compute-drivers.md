---
title: "Compute drivers"
description: "The machine software recipe, Sury and static PHP builds, shared Instance folders, Instance container host preparation, and nested fleet checks."
covers:
  - apps/gateway/app/{Domain/TaskVms,Infrastructure/TaskVms,Domain/Compute}/**
  - apps/gateway/{app/Infrastructure/MachineSoftware/**,app/Console/Commands/MachineRecipeCommand.php,resources/machine-software/**}
  - .github/workflows/{php-build-mirror,git-build-watch}.yml
  - "{bin/{php-build,git-build-watch}}"
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/{2027_01_29_000001_drop_upcloud_task_vm_support,2027_02_01_000001_migrate_task_vm_host_pools,2027_02_02_000000_add_task_vm_runtime}.php
  - swamp/workflows/{workflow-task-nested-apply,workflow-task-nested-ready,workflow-proof-fleet-restore}.yaml
---

# Compute drivers

Orbit pins host and container software through one declarative machine software recipe (`apps/gateway/resources/machine-software/recipe.json`), paired with container host preparation and nested-fleet proof workflows.

## Machine software recipe

`apps/gateway/resources/machine-software/recipe.json` declares the pinned Ubuntu release (`26.04`), target architecture (`amd64` only), tool versions, [PHP build](#php-build), Git packages, role tool sets, and shared Instance folders.

### Recipe schema

The loader accepts UTF-8 JSON with `schema_version: 1` only and rejects unknown keys, duplicate keys, or unsupported architectures (`arm64` Linux is rejected):

| Field | Contract |
| --- | --- |
| `schema_version` | Integer `1` |
| `ubuntu.release`, `ubuntu.snapshot` | Ubuntu release (`26.04`) and UTC snapshot ID (`YYYYMMDDTHHMMSSZ`) |
| `architectures` | Exactly `["amd64"]` |
| `tools`, `common` | Known tool pins (`ubuntu`, `artifact`, or `internal` source) and tools shared by every role |
| `php` | [PHP build](#php-build) pins (`versions`, `shared`, `ci`, `rollback`, `pcov`, `opentelemetry`) |
| `artifact_mirror` | Base HTTPS URL of the [recipe artifact mirror](#the-recipe-artifact-mirror) |
| `git` | Exact pinned `ppa:git-core/ppa` packages and `usn_since` review date |
| `roles` | Additional tool and PHP sets per role (`gateway`, `app-dev`, `app-prod`, `operator`, `vpn`, `router`, `ingress`, `metrics`, `database`, `analytics`, `ci-runner`, `swamp`) |
| `base` | Additional tool and PHP sets for the base profile (`deno`, `swamp`, `vp-cli`, `rust-std`, etc.; no Pi) |

### Show and validate the recipe

Inspect or validate the recipe from the Gateway:

```bash
php artisan orbit:machine-recipe --validate [--validate-ubuntu] [--role=<role>] [--architecture=amd64] [--json]
```

Canonical recipe JSON hashes to a deterministic SHA-256 recipe identity (combining `recipe.json` with the pinned agent delivery manifest).

### Shared Instance folders

Container hosts publish two read-only shared folders built from the recipe:

- **`/opt/orbit-runtime` (`runtime`):** Static glibc PHP binaries, Composer, Vite+ runtime (`vp`), Node.js, pnpm, Bun, `chrome-headless-shell`, and the 26 pinned Chromium `browser-*` libraries and fonts.
- **`/opt/orbit-agent` (`agent`, `root:2750`):** `t3`, `conn-server`, `claude-code`, `codex`, `antigravity`, `chrome-headless-shell`, and `agent-browser`.

#### The Vite+ home

The runtime folder places Vite+ under `/opt/orbit-runtime/vite-plus/<digest>`. Containers set `VP_HOME=/var/lib/orbit/vite-plus-home` and `VP_NO_UPDATE_CHECK=1`, keeping Project lockfiles independent from the host recipe pin.

## PHP build

### One PHP build

Every Linux Node installs PHP from the same pinned Sury `.deb` package set (`recipe.json` `php`), cached in `/var/cache/orbit/php-packages` with `dpkg-repack` rollback copies. PCOV is enabled for CLI only; OpenTelemetry is enabled for FPM only. Managed INI settings include `99-cve-2026-109618-upload-progress.ini`.

### Install the PHP build

`php.install-build` downloads each pinned `.deb` over HTTPS from the [recipe artifact mirror](#the-recipe-artifact-mirror) first (falling back to Sury on transport errors), verifies SHA-256, package name, version, and architecture, removes legacy PHP apt sources, and installs in one `apt` transaction. `php.inspect-build` audits live packages and `php.configure-runtime` applies managed OPcache and upload limits.

### The recipe artifact mirror

`artifact_mirror` caches pinned Sury PHP `.deb` files, Git packages (`git-build-watch`), and third-party tarballs under Orbit's mirror (managed via `bin/php-build`, `bin/git-build-watch`, and `bin/recipe-artifacts bump`). Installers try the mirror first, fall back to upstream only on transport failures, and stop immediately on checksum mismatches.

### Static PHP build

Production and development containers use the static glibc PHP builds defined in `tools/static-php/craft.yml` and built via `bin/static-php-build` (`static-php.yml`, `orbit_static_php` release assets, configured in `instances.php`).

## Prepare an Instance container host

Converging `app-dev` (or a `ci-only` `ci-runner` Node) prepares the Incus host for [Instance containers](/reference/development-containers#prepare-a-node):

- Verifies the `orbit-instances` ZFS pool (or btrfs on `nested-app-dev`).
- Creates bridge `orbitinst0` (with `raw.dnsmasq=port=0` so containers have no direct DNS), bridge/NIC ACLs, Squid egress proxy, and the shared `/opt/orbit-runtime` and `/opt/orbit-agent` folders.
- Installs `/usr/local/libexec/orbit-instance-power` and fast-wake systemd units, and builds the stock (and optional nested-fleet) container images.

## Build the nested fleet in the converge

Nested proof fleets run an inner Orbit fleet of three Nodes (`gateway`, `app-dev`, `app-prod`) inside a single container ([Nested fleet](/reference/development-containers#nested-fleet)).

### Apply and check the nested fleet

Three Swamp workflows manage nested-fleet candidate testing:

- `task-nested-apply`: Syncs the checkout onto the nested Gateway, reconciles Swamp grants, and waits for the local Swamp listener.
- `task-nested-ready`: Verifies all three inner Nodes, agents, and services are healthy.
- `proof-fleet-restore`: Restores the nested fleet back to its clean `base` snapshot between proof batches.

## Task VMs

The Incus task VM runtime and manual topology harness have been removed. All task groups and proofs now run in container [task copies](/reference/development-containers#lifecycle) cloned from a Project's default Instance.

### Placement invariant

Container task copies inherit placement from their Project's default Instance and require a configured AI router model route (`409 ai_router.disabled` when enabling `tasks` without AI router). The WireGuard allocator reserves `10.44.0.128/25` outside standard peer allocation.

### Declare a topology

Task groups do not declare topologies; use a Project's [nested fleet setting](/reference/development-containers#nested-fleet) when a Project needs an isolated multi-Node fleet.

### The topology seed

Manual topology seed disks are replaced by container default seeds (`seed-<commit>`) and CI proof seeds (`instance.seed_copy`).

### Refresh the base snapshot

Task VM base snapshot refreshes (`task_vm.image_refresh`) are removed. Default Instances with a nested fleet refresh automatically after a verified Gateway release ([Refresh after a release](/reference/development-containers#refresh-after-a-release)).

### Image fallback

`TaskGroup.current_recipe` remains in the schema for compatibility (`false` by default) and is ignored by container task copies.

### VM power

Task VM parking and resuming are replaced by container [sleep and wake](/reference/development-containers#sleep-and-wake).

### Request a preview

Interactive previews run directly on development container Routes rather than disposable task VM previews.
