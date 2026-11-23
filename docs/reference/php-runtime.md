---
title: "PHP runtimes"
description: "How Orbit selects a PHP version for an Instance, runs shared development and dedicated production PHP-FPM services, and refreshes production OPcache."
covers:
  - apps/gateway/app/Domain/Instances/{InstancePhpVersionCatalog,ComposerSourceClassifier,ProductionPhpRuntimeIdentity,ProductionPhpRuntimeManager}.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteProductionPhpRuntimeManager,ProductionPhpRuntimeConfigRenderer,ProductionPhpRuntimeConfiguration}.php
  - apps/gateway/app/Infrastructure/Nodes/{PhpFpmRuntimeIniRenderer,RemotePhpPackageManager}.php
  - apps/gateway/app/Infrastructure/Fleet/Footprint/PhpRuntimeFootprintArtifact.php
  - apps/gateway/app/Infrastructure/AppDev/{DevelopmentPhpFpmConfigRenderer,RemoteAppDevPhpFpmManager}.php
  - apps/agent/src/commands/{php_runtime,php_profile,php_shared}.rs
---

# PHP runtimes

On Linux host Nodes (`x86_64` / `amd64` only), Orbit installs the exact PHP build pinned by the [machine software recipe](/reference/compute-drivers#php-build) from Sury packages and serves each site through PHP-FPM over a Unix socket. Development checkout Instances share one PHP-FPM service per PHP version, while each production PHP Instance gets its own dedicated PHP-FPM service and OPcache. [Container Instances](#container-php) run a static PHP build with FrankenPHP instead of host PHP-FPM.

## Select the PHP version

The Gateway reads `composer.json` from the Instance's [application directory](/reference/projects#application-directory) (or repository root for non-Laravel monorepos) before publishing the runtime or DNS. It checks PHP 8.5 first, then PHP 8.4, and selects the first version satisfied by `require.php`.

| Source | Result |
| --- | --- |
| No `composer.json` | No PHP runtime is prepared. |
| `composer.json` without `require.php` | PHP 8.5. |
| Constraint satisfied by 8.5 or 8.4 | First match (8.5 before 8.4). |
| Invalid or unsatisfied constraint | `app-dev.php_version_unsupported` or `app-prod.php_version_unsupported`. |
| Missing recipe build or bad package digest | `app-dev.php_package_source_unavailable` or `app-prod.php_package_source_unavailable`. |

The selected version is recorded in the Instance's [source profile](/domains/applications#provision-the-application-endpoint); there is no manual PHP version input field. For `apps/site/public`, PHP-FPM's working directory is `<checkout>/apps/site` in development or `<production-home>/current/apps/site` in production, while Caddy's document root stays `apps/site/public`.

## PHP build

The PHP step on `app-dev` and `app-prod` Nodes installs each selected version from the recipe using `php.install-build` ([PHP build installer](/reference/compute-drivers#install-the-php-build)). When installed packages already match the recipe, nothing is downloaded.

During Instance package steps:

- Extra packages outside the recipe stay installed (`--no-remove`). Full recipe convergence refuses unmanaged PHP packages instead.
- PCOV is loaded in the CLI only on `app-dev` Nodes.
- On `app-dev`, only the shared `php<version>-fpm` service restarts after passing `php-fpm<version> -t`; stopped services stay stopped until pool publication starts them.
- On `app-prod`, package installation does not restart running dedicated masters; they pick up the new build on their next restart or deployment.
- Settings-only changes validate and gracefully reload active masters (`process_control_timeout = 30s`). Any master with invalid pools stays running until pool publication repairs it.

Install failures return `app-dev.php_install_failed` or `app-prod.php_install_failed`. The [Tools](/reference/tools#protected-packages) apt manager cannot install, update, adopt, or remove managed PHP packages.

## Development runtime

Host development checkouts share the distribution `php<version>-fpm` service. Each application directory of an Instance gets its own pool and socket (`orbit-app-instance-<id>`, plus a hashed suffix for additional [web-root Routes](/reference/routes#serve-several-web-roots)), written to `/etc/php/<version>/fpm/pool.d/orbit-scopes.conf`.

Pool convergence runs under a Node-wide shared-pool lock:

1. Checks the `chdir` of every installed and desired pool as root, skipping any pool whose directory is missing so one broken pool never stops other sites.
2. Installs any needed [PHP build](#php-build) without starting the service.
3. Stages and validates each version's pool set with `php-fpm<version> -t`.
4. Publishes changed files, enables and reloads (or starts) each service, and restores prior files and service state on failure.
5. Fails with `app-dev.php_pool_directory_missing` after publishing valid pools if any desired pool had a missing directory.

[Instance removal](/reference/instance-removal#native-order) runs this convergence after clearing the Route and before deleting the checkout so PHP-FPM never retains a pool pointing at a deleted directory.

Orbit publishes `/etc/php/<version>/mods-available/orbit-runtime.ini` and enables it for FPM only (`/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini`). Development pools check file timestamps on every request (`opcache.validate_timestamps = 1`, `opcache.revalidate_freq = 0`, stock 2-second `opcache.file_update_protection`).

### Container PHP

Container Instances use a static PHP build and FrankenPHP instead of host PHP-FPM. Every container receives four runtime settings in `/etc/orbit/php/conf.d/99-orbit-runtime.ini` (restarting running `orbit-*` units if changed):

| Setting | Value | Purpose |
| --- | --- | --- |
| `memory_limit` | `1G` | Matches CI test memory limit. |
| `auto_prepend_file` | `/opt/orbit-runtime/current/php-data-bridge/bridge/prepend.php` | Loads [PHP Data Bridge](https://github.com/nckrtl/php-data-bridge) on web requests so Conn can inspect requests (disable with `collect.enabled: false` in `php-data-bridge.json`). |
| `zend.exception_ignore_args` | `1` | [Production-safe settings](#production-safe-settings). |
| `session.upload_progress.enabled` | `0` | [Production-safe settings](#production-safe-settings). |

#### Production-safe settings

The static PHP CLI compiles in `zend.exception_ignore_args=1` and `session.upload_progress.enabled=0` so container PHP never runs with unsafe built-in defaults (mitigating PHP 8.5 fatal-error backtrace CVEs). Development containers also enforce both in `99-orbit-runtime.ini`.

Production release containers ship `/etc/orbit/php/php.ini` built from `php.ini-production` with these overriding lines:

| Setting | Value |
| --- | --- |
| `zend.exception_ignore_args` | `1` |
| `session.upload_progress.enabled` | `0` |
| `display_errors` | `0` |
| `display_startup_errors` | `0` |
| `expose_php` | `0` |

#### PCOV in the CLI only

Development containers load PCOV for the PHP CLI only (never in the FrankenPHP web server) by writing `/etc/orbit/php/php-cli.ini` with `extension=/opt/orbit-runtime/current/php/<php-version>/modules/pcov.so` when the module exists in the mounted runtime folder. Both `php` and `frankenphp php-cli` use the `cli` SAPI and read `php-cli.ini`; FrankenPHP's `frankenphp` web SAPI ignores it. Projects without PHP and production containers receive no `php-cli.ini`.

## Production runtime

Each host production PHP Instance (`laravel-app`, `symfony-app`, or a routed Laravel `monorepo`) gets a dedicated PHP-FPM service and OPcache for its Unix user (`laravel-package` selects a PHP version but does not run PHP-FPM):

| Part | Name or path | Owner |
| --- | --- | --- |
| Service | `orbit-<production-user>-php<version>-fpm.service` | Gateway |
| Pool | `orbit-<production-user>` | Gateway |
| Socket | `/run/php/<production-user>.sock`, mode `0660`, group `caddy` | Gateway |
| Generated files | `/etc/orbit/php-fpm/<production-user>/generated/` (including `master.ini`) | Gateway |
| Local tuning | `/etc/orbit/php-fpm/<production-user>/local.conf` | Operator |

Additional [web-root Routes](/reference/routes#web-roots-on-production) add pools (`orbit-<production-user>-<suffix>`, socket `/run/php/<production-user>.<suffix>.sock`) under the same master so one OPcache refresh covers all pools. Before the first deploy, the pool uses `releases/initial`; after a deploy or rollback selects a release, Orbit reconciles the pool to `current` and records the resolved release in the generated pool file so release switches restart the dedicated master cleanly.

Convergence holds a per-user lock (`production-php:<user>`), verifies the ownership marker `/etc/orbit/php-fpm/<user>/orbit.identity`, and validates effective configuration so `local.conf` cannot override user, socket, PHP version, paths, or `chroot`. Orbit tracks pending and applied generation receipts (including boot ID, master PID, and start time) so interrupted retries converge reliably while an unattended package restart is not flagged as drift. `local.conf` is seeded once and never overwritten by Orbit; failed starts restore previous generated files and service state.

## Request and upload limits

The managed host FPM module sets `post_max_size = 64M` and `upload_max_filesize = 64M` (64 MiB, covering multipart overhead) on both `app-dev` and `app-prod`. Dedicated production masters inherit these values from FPM `conf.d` before loading `master.ini`. The agent validates both limits (positive, up to 1 GiB, upload no larger than POST) with `php-fpm -i` before activation. Host PHP-FPM sites route through Caddy with no Caddy request-body size cap (neither nginx nor FrankenPHP is used on host PHP-FPM sites).

The `php-runtime` [Node footprint artifact](/reference/node-provisioning#converge-the-orbit-footprint) distributes this module during `orbit node:converge <node>` and automatic fleet rollouts, skipping PHP versions not installed on a Node.

## OPcache settings

Development and production PHP-FPM masters use these OPcache directives:

| Directive | `app-dev` | `app-prod` |
| --- | --- | --- |
| `opcache.enable` | On | On |
| `opcache.memory_consumption` | 512 | 256 |
| `opcache.interned_strings_buffer` | 64 | 32 |
| `opcache.max_accelerated_files` | 65407 | 65407 |
| `opcache.validate_timestamps` | 1 (per pool) | 0 |
| `opcache.jit` / `opcache.jit_buffer_size` | disable / 0 | disable / 0 |

Nodes with both roles use `app-dev` values for the shared service and `app-prod` values for each dedicated production master. `opcache.preload`, `file_cache`, and `huge_code_pages` stay off.

## Process management

Every pool uses `pm = ondemand`, `pm.process_idle_timeout = 10s`, and `pm.max_requests = 500`, with `pm.max_children = 10` in development and `20` in production (tunable in `local.conf`). The Gateway's own `orbit-gateway` pool uses 8 children and a 600-second request limit (stopping remote API work after 570 seconds; see [Activity](/cli/activity#interrupted-requests)).

## Refresh production code

Each [deployment](/reference/deployments) and rollback gracefully reloads the Instance's dedicated PHP-FPM service so in-flight requests finish and new workers start with an empty OPcache without affecting other Instances. Calling `opcache_reset()` from PHP CLI cannot reach the FPM master's cache.

## Check a Node

Inspect a production runtime on its Node:

```sh
systemctl is-active orbit-<production-user>-php8.5-fpm.service
systemctl cat orbit-<production-user>-php8.5-fpm.service
stat /run/php/<production-user>.sock
find /etc/orbit/php-fpm/<production-user> -maxdepth 2 -type f -print
```

[Doctor](/cli/doctor/doctor#what-each-family-checks) checks role-service liveness; native PHP provisioning owns package, pool, and configuration validation.

## Why it works this way

### PCOV only where checks run

PCOV is part of PHPStan's cache key, so `app-dev` Nodes, development container CLIs, task VMs, and CI load it for shared caches and Pest coverage, while production Nodes and FrankenPHP web workers skip its overhead.

### Sury packages, not a custom PHP build

For host PHP-FPM Nodes, Orbit installs pinned Sury `.deb` packages from the [machine software recipe](/reference/compute-drivers#one-php-build) rather than maintaining a custom host build or pulling moving upstream apt indexes. Every host Node runs the exact same digest-verified build with automatic rollback if PHP-FPM fails to start.

### One master per production user

Pools under one master share a single OPcache. Giving each production user its own master isolates OPcache resets and tuning (`local.conf`) to that application, while keeping `opcache.validate_timestamps = 1` in development avoids the ~80 ms cost of disabling OPcache entirely. Tracing JIT stays off because it gives Laravel no measurable gain and introduces crash classes.

### Publish pools before starting PHP-FPM

PHP-FPM refuses to start if any pool references a missing `chdir`. Installing packages without starting the service, skipping pools whose directories are missing during convergence, and removing pools before deleting checkouts keep one broken or removed checkout from taking down other sites on the same Node.

### PHP from Composer

Reading `require.php` from `composer.json` keeps the repository as the single source of truth instead of duplicating a PHP version field on Instances.
