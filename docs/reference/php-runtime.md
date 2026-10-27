---
title: "PHP runtimes"
description: "How Orbit selects a PHP version for an Instance, runs shared development and dedicated production PHP-FPM services, and refreshes production OPcache."
covers:
  - apps/gateway/app/Domain/Instances/{InstancePhpVersionCatalog,ComposerSourceClassifier,ProductionPhpRuntimeIdentity,ProductionPhpRuntimeManager}.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteProductionPhpRuntimeManager,RemoteProductionInstanceSourceLifecycle,ProductionPhpRuntimeConfigRenderer,ProductionPhpRuntimeConfiguration}.php
  - apps/gateway/app/Infrastructure/AppProd/{RemoteAppProdPhpFpmManager,RemoteAppProdSourceManager}.php
  - apps/gateway/app/Infrastructure/Nodes/{PhpFpmRuntimeIniRenderer,RemotePhpPackageManager}.php
  - apps/gateway/app/Infrastructure/Fleet/Footprint/PhpRuntimeFootprintArtifact.php
  - apps/gateway/app/Infrastructure/AppDev/{DevelopmentPhpFpmConfigRenderer,RemoteAppDevPhpFpmManager}.php
  - apps/agent/src/commands/{php_runtime,php_doctor,php_profile,php_shared}.rs
---

# PHP runtimes

Orbit installs the exact PHP build that the [machine software recipe](/reference/compute-drivers#php-build) pins, from Sury packages, and serves each site through PHP-FPM over a Unix socket. Development Instances on a Node share one PHP-FPM service per PHP version. Each production Instance that serves PHP gets its own PHP-FPM service, with its own OPcache.

Managed PHP supports amd64 only, because [Linux Nodes are x86_64 only](/reference/node-provisioning#machine-architecture). Orbit never substitutes packages from another architecture.

## Select the PHP version

The Gateway reads the source's `composer.json` once, before it publishes the runtime or DNS. For a routed Laravel app, source inspection reads `composer.json` and `artisan` from the [application directory](/reference/projects#application-directory), not from an unrelated repository-root Composer project. It tries PHP 8.5, then PHP 8.4, and picks the first version that the `require.php` constraint allows.

With root `apps/site/public`, the working directory for PHP-FPM is `<checkout>/apps/site` in development or `<production-home>/current/apps/site` in production; Caddy's document root remains the corresponding `apps/site/public`. Root `public` keeps the checkout or release root as the application working directory.

Before the first production deployment, `current` is absent, so the dedicated pool starts in the application's directory under `releases/initial`. Once a deployment or rollback selects a release, Orbit reconciles the pool to the application's directory under `current` before refreshing its PHP cache. It validates the selected directory before starting or restarting FPM.

The Gateway renders each production runtime's files. The [Node agent](/reference/node-agent#commands) checks and publishes them, as root, with `php.converge-production-runtime`; `php.remove-production-runtime` removes them, and `php.refresh-production-cache` refreshes the cache. Each command first checks the root-owned marker `/etc/orbit/php-fpm/<user>/orbit.identity`, and each takes the agent's lock `production-php:<user>`, so two changes to one runtime never overlap. Service metrics read the generated pool with `php.inspect-production-pool`, which takes no lock.

The generated pool records the resolved release as a comment. A release switch changes those bytes and restarts that Instance's dedicated service, so existing workers cannot keep the previous release as their working directory. A confirmed no-op reconciliation does not restart the service.

Before publishing a changed pool, Orbit durably records a pending generation. After it starts and verifies the master, it durably records the applied generation with the boot ID, master PID, and process start time, then clears the pending receipt. A retry restarts FPM when the published bytes match but acknowledgment is missing.

Doctor and convergence reject a pending generation or a receipt that does not match the desired release. They accept a running master that started after the recorded one: after a host reboot, or after a restart in the same boot. With no pending receipt, Orbit has no change in progress, so that master loaded the files Orbit confirmed. An unattended package upgrade that restarts the service is therefore not drift and does not make convergence restart it again. A receipt that names a master newer than the running one is rejected.

The check cannot see a change made outside Orbit, such as an operator who edits the generated files and restarts the service, or reverts an edit without a restart. Orbit accepts that risk; the byte checks of the generated files still report an edit that remains.

Doctor accepts the application's directory in the initial release only while `current` is absent. After selection it expects the application's directory under `current`.

| Source | Result |
| --- | --- |
| No `composer.json` | No PHP. Orbit prepares no PHP runtime. |
| `composer.json` without `require.php` | PHP 8.5. |
| A constraint that 8.5 or 8.4 meets | The first match, 8.5 before 8.4. |
| An invalid constraint, or one that neither version meets | `app-dev.php_version_unsupported` or `app-prod.php_version_unsupported`. |
| The recipe has no build for the version, or a pinned package cannot be downloaded or fails its digest | `app-dev.php_package_source_unavailable` or `app-prod.php_package_source_unavailable`. |

The Instance records the selected version in its [source profile](/domains/applications#provision-the-application-endpoint). There is no input or output field to choose a version. The Node role installs, configures, and removes every selected version.

## PHP build

The PHP step of the `app-dev` and `app-prod` roles installs the recipe's [PHP set](/reference/compute-drivers#php-build) of each selected version with `php.install-build`, the one [PHP build installer](/reference/compute-drivers#install-the-php-build). Recipe convergence, the task VM image build, and CI run the same program. The step reads the set from the recipe; it keeps no list of its own.

For package changes, the PHP step of an Instance uses these options:

- It keeps PHP packages outside the recipe and installs with `--no-remove`, so a deploy never removes an extension that a site uses. Recipe convergence refuses such packages instead.
- It loads PCOV in the CLI only on an `app-dev` Node.
- On app-dev, it restarts only the shared `php<version>-fpm` service. Its pools must pass `php-fpm<version> -t`.
- A production Instance PHP step restarts no active master.
- A service whose pools fail validation keeps running. The pool publication that follows removes a stale pool and reloads the service.
- A stopped service stays stopped until pool publication starts it.

Package changes restart no dedicated production master. The step logs the masters left running, which may still use the previous PHP build or settings. Their next restart, such as the next deployment, loads the new build.

The shared and dedicated PHP-FPM global configurations set `process_control_timeout = 30s`, so later graceful reloads allow in-flight requests up to 30 seconds to finish. The first reload after rollout still uses the old timeout already loaded in the running master; it can cut in-flight requests once. Runtime module publication reports every active master whose configuration check fails; the Gateway logs those deferred units for repair.

A settings-only change follows the [PHP build installer's reload policy](/reference/compute-drivers#install-the-php-build): it validates every active master, including dedicated production masters, and gracefully reloads those that pass. An Instance step keeps a master with invalid configuration running, reports it as deferred, logs it, and continues with the other masters. It keeps the INI so that master loads the setting at its next successful reload or restart. Pool publication can then repair the Instance's configuration without another master's invalid configuration blocking the step.

Recipe convergence instead fails and restores the previous settings when a master fails validation. Rollback reloads masters that were reloaded and restarts only masters that were restarted.

When every package is at its recipe version, the installer downloads nothing, and the step logs `PHP packages match the recipe; skipped the package download.` A refusal or a failed install fails the step with `app-dev.php_install_failed` or `app-prod.php_install_failed`. Another PHP apt source, a missing file, or an unsupported system fails it with `app-dev.php_package_source_unavailable` or `app-prod.php_package_source_unavailable`. The error carries the installer's log.

The [Tools](/reference/tools#protected-packages) apt manager cannot install, update, adopt, or remove the role's PHP packages.

Orbit does not recover missing source profiles on older Instances. [Projects: One public name without compatibility](/reference/projects#one-public-name-without-compatibility) explains the no-legacy-support rule.

## Development runtime

Development sites share the distribution service `php<version>-fpm`. Each application directory of an Instance has its own pool and socket: `orbit-app-instance-<id>` for the default directory, and a suffixed name for a directory that a [Route with a web root](/reference/routes#serve-several-web-roots) serves. The Gateway renders desired pools from stored state. The native `php.converge-shared-pools` command publishes each version into `/etc/php/<version>/fpm/pool.d/orbit-scopes.conf`. Instance creation, transfer, and removal run that convergence.

The read-only `php.inspect-shared-pools` command reports missing working directories and the versions whose desired pools can run. Doctor uses the same read. The Gateway installs those versions, then calls `php.converge-shared-pools` once with all desired configurations. When no shared pools remain desired, the Gateway sends an empty configuration directly, without account, inspection, or package preflights. The mutation holds one Node-wide shared-pool lock while it discovers installed state, retires pools that move between versions, activates their replacements, and recovers failures. It never sends installed configuration back to PHP for parsing.

One PHP-FPM convergence runs these steps:

1. It reads each `orbit-scopes.conf`. As root, it checks the working directory (`chdir`) of every installed pool and of every pool that stored state renders.
2. It skips a pool whose working directory is missing. [Doctor](#doctor) reports that pool.
3. It installs the [PHP build](#php-build) without starting the service. A stale pool can keep PHP-FPM from starting, but it cannot block the step that removes it.
4. It stages each version's desired pools with the other pool files and validates every set with `php-fpm<version> -t` before publication.
5. It moves changed candidates into place, enables the services, and reloads them. The reload also starts a stopped or failed service.
6. It starts a stopped or failed service whose file is already current.
7. If publication or activation fails, it restores touched versions in reverse order with their prior file modes and service state.
8. A convergence that skipped a pool fails with `app-dev.php_pool_directory_missing` after it publishes every version. The error names the pool.

Step 3 downloads nothing when every package is already at its recipe version. A failed restoration reports `php.shared_pool_recovery_failed`; the Gateway maps it to the existing runtime failure.

A restored file leaves out a pool whose working directory is gone, because that file could never pass `php-fpm -t` again. The final error makes Instance creation, deployment, transfer, and a Project root change report the missing directory. Without it, they would succeed and leave a site that answers `502`.

PHP-FPM refuses to start while any pool names a missing `chdir`, so one such pool would stop every site of that version. That is why convergence skips such a pool and still publishes the others.

Instance removal runs this convergence for every development Instance, also when the Instance never became active or its Route was destroyed first. It runs after the Route target is cleared and before the checkout is deleted. At that point stored state renders no pool for the Instance, so the convergence removes the pool and reloads PHP-FPM while its directory still exists. See [Instance removal](/reference/instance-removal#runtime-cleanup).

Orbit publishes one module per version at `/etc/php/<version>/mods-available/orbit-runtime.ini` and enables it for FPM only, as `/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini`. The CLI keeps stock settings. At each convergence, the native `php.configure-runtime` command compares the module with the installed file and repairs the link. It gracefully reloads active shared and dedicated production masters when the module, its enablement, or the shared global configuration changed and their pools pass `php-fpm<version> -t`. A master with invalid pools stays running until pool publication repairs it. A failed publication restores the previous module and link and reloads the masters already changed.

A development pool checks every file on every request, so a saved file is served at once:

```ini
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 0
```

`opcache.file_update_protection` stays at its stock 2 seconds, because file times have one-second resolution.

## Production runtime

Each production Instance that serves PHP has a dedicated service for its Unix user. Production users on one Node share the installed PHP packages, but not a PHP-FPM master.

| Part | Name or path | Owner |
| --- | --- | --- |
| Service | `orbit-<production-user>-php<version>-fpm.service` | Gateway |
| Pool | `orbit-<production-user>` | Gateway |
| Socket | `/run/php/<production-user>.sock`, mode `0660`, group `caddy` | Gateway |
| Generated files | `/etc/orbit/php-fpm/<production-user>/generated/`, including `master.ini` | Gateway |
| Local tuning | `/etc/orbit/php-fpm/<production-user>/local.conf` | Operator |

A [Route with a web root](/reference/routes#web-roots-on-production) adds a pool for its application directory to the same master: `orbit-<production-user>-<suffix>`, with socket `/run/php/<production-user>.<suffix>.sock` and its working directory under `current`. The suffix is the same hash of the relative directory as in development. The generated pool file holds that pool's process settings, because `local.conf` tunes only the default pool. Convergence refuses a pool whose directory is not inside a release, and checks each socket after a start. Without such a Route, the generated files are unchanged. All pools share the master's OPcache, so one cache refresh covers them.

Orbit seeds `local.conf` with its defaults for a new service. After that, provisioning, retries, updates, and removal never change it. Removal keeps the ownership marker beside the tuning, so the same runtime can be created again without adopting an unowned file. A different identity still refuses.

The generated files set the identity: the user, pool, socket, PHP version, home, and application path. Before it starts or reloads the service, the Rust agent validates the effective configuration. It refuses a `local.conf` that changes the identity, including a `chroot` directive. Doctor uses the same tuning rule. It never adopts an existing user, service, socket, or file that belongs to something else.

A failed start restores the generated files and service state from before the change. An interrupted change resumes from the recorded identity.

The shared `/etc/orbit` directory stays `root:root` with mode `0711`, so production users can reach their Schedule scripts without listing the directory. `/etc/orbit/php-fpm` stays closed to application users.

A `laravel-app` or `symfony-app` Instance serves PHP. A `monorepo` Instance serves PHP only when it has a Route and a Laravel source. A `laravel-package` can select a PHP version from its Composer constraint but is not classified as a Laravel application or served through PHP-FPM. Other types start no PHP-FPM master. Production source inspection applies the Instance's project type when it classifies the Composer and Artisan metadata. It [checks the production checkout](/reference/instance-cloning#destination-checks) as the production user, even when the SSH user's home is private.

## Request and upload limits

The managed FPM module sets `post_max_size = 64M` and `upload_max_filesize = 64M` for both `app-dev` and `app-prod`. Each PHP size is 64 MiB. The POST limit covers the complete request, including multipart overhead, so a file upload must fit inside that limit. Application validation can impose a smaller limit. The CLI keeps its stock settings, and Orbit leaves production `local.conf` tuning unchanged.

Dedicated production services scan the same FPM `conf.d` directory before their generated `master.ini`, so they inherit these limits. Each master keeps its own OPcache settings. The agent requires both size settings to be positive PHP size quantities at most 1 GiB, with the upload limit no greater than the POST limit and verifies the effective values with `php-fpm -i` before activation.

The `php-runtime` [Node footprint artifact](/reference/node-provisioning#converge-the-orbit-footprint) publishes the module for the recipe profile’s PHP versions and the versions selected by active Instances on app Nodes. These are candidate versions: the agent leaves an absent PHP-FPM binary untouched. A recipe version that is not installed cannot fail the fleet rollout. Its digest covers the renderer, selected profile, and candidate PHP versions; it never reads live inventory.

After a Gateway release supplies the new renderer and agent, `orbit node:converge <node>` applies it to existing Nodes and reloads valid active masters. The automatic fleet rollout runs the same convergence after release adoption and image promotion; a merge alone changes no running Node. Instance PHP convergence also publishes the module. Recipe convergence publishes it on app Nodes after the PHP installer succeeds, even when the footprint digest already matches. A version installed later therefore receives the module before recipe convergence records success.

Orbit routes these applications through Caddy to PHP-FPM. The managed Caddy site and reverse-proxy configuration adds no request-body size cap. This path uses neither nginx nor FrankenPHP.

## OPcache settings

The development module and each production `master.ini` apply these values.

| Directive | `app-dev` | `app-prod` |
| --- | --- | --- |
| `opcache.enable` | On | On |
| `opcache.memory_consumption` | 512 | 256 |
| `opcache.interned_strings_buffer` | 64 | 32 |
| `opcache.max_accelerated_files` | 65407 | 65407 |
| `opcache.validate_timestamps` | 1, per pool | 0 |
| `opcache.jit` / `opcache.jit_buffer_size` | disable / 0 | disable / 0 |

A shared service on a Node with both roles uses the `app-dev` values. A dedicated production master always uses the `app-prod` values. `opcache.preload`, `file_cache`, and `huge_code_pages` stay off.

## Process management

Every pool uses `pm = ondemand` with `pm.process_idle_timeout = 10s` and `pm.max_requests = 500`. `pm.max_children` is 10 for a development pool and 20 for a production pool. Production tuning lives in `local.conf`.

The Gateway's own `orbit-gateway` pool uses 8 children and a 600-second request limit. Its API commands stop remote work after 570 seconds; see [Activity](/cli/activity#interrupted-requests).

## Refresh production code

A production master keeps compiled code until a cache refresh. Each [deployment](/reference/deployments) and rollback refreshes the cache of its own service:

1. The Node agent checks that the service is active and owns the expected socket.
2. It asks for `opcache_reset()` through that socket, inside the service. The FastCGI request sets the parameter `ORBIT_OPCACHE_ACTION=reset`. A request without it only reads the cache status.
3. It reports success only after a later request shows the reset finished.

The refresh never touches another Instance's socket and never reloads a service. `opcache_reset()` from the PHP CLI cannot reach the service's cache. Laravel's `php artisan optimize` is an application deploy step.

| Code | Cause |
| --- | --- |
| `app-prod.php_cache_socket_unavailable` | The socket does not answer. |
| `app-prod.php_cache_association_invalid` | The service or socket does not match the record. |
| `app-prod.php_cache_reset_rejected` | PHP rejected the reset. |
| `app-prod.php_cache_reset_pending` | The reset did not finish before the deadline. |

## Doctor

On every Node with an active `app-dev` or `app-prod` role, [Doctor](/cli/doctor) reports `role.php_pool_directory_missing` for each Orbit pool whose working directory is missing. It reports the issue on the `app-dev` role, or else on `app-prod`. A pool in a live `orbit-scopes.conf` with a missing directory stops that PHP version from starting at its next restart; the next PHP-FPM convergence on the Node removes it. A pool that only stored state renders is skipped by convergence until its directory exists.

The read-only `doctor.inspect-production-instance` agent command compares the Node state. It shares pool selection and generation receipt checks with PHP runtime convergence. Doctor also checks that each production PHP Instance has one service, pool, and socket, and shares none of them. It compares the generated files and rejects a `local.conf` that changes the identity. It also checks the service's `ExecStart` and `PHP_INI_SCAN_DIR`, the master process, its socket, and the user and parent of each worker. An idle pool with no workers is valid.

Doctor reads only. It never starts PHP-FPM, sends a request, reloads a service, or resets a cache. It does not compare allowed tuning with Orbit's defaults. A process that exits while Doctor reads it is not drift. When Doctor cannot read a required fact, it reports `instance.inspection_failed`.

## Check a Node

Run these commands on the Node to inspect one production runtime:

```sh
systemctl is-active orbit-<production-user>-php8.5-fpm.service
systemctl cat orbit-<production-user>-php8.5-fpm.service
stat /run/php/<production-user>.sock
find /etc/orbit/php-fpm/<production-user> -maxdepth 2 -type f -print
```

Another production user must show a different service, socket, master, and cache.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### PCOV only where checks run

PCOV is part of PHPStan's cache key, so the machines that share CI caches must load it: `app-dev` Nodes, task VMs, and CI. Production Nodes and the Gateway run no checks, so their CLI does not pay its overhead.

### Sury packages, not a custom PHP build

A static-php build is 10 to 15 percent slower on Orbit's Nodes, lacks `pdo_sqlite` and `pdo_pgsql`, and its glibc variant crashes on some CPUs. A custom build would also make Orbit own PHP security updates. So Orbit uses the pinned Sury packages.

### One installer with a rollback

Each caller once had its own install code, and their safety checks differed. One program gives every machine the same extras check, rollback, and health checks. The installer keeps the previous build as files, so a PHP-FPM that fails to start after a change gets its previous build back at once instead of waiting for an operator.

### The recipe's files, not the Sury apt source

Sury's apt index carries only its newest build. Installing from it gave each Node the build of the day it was set up, and an exact `package=version` disappears from the index when Sury moves on. The recipe's `.deb` files are checked by digest, and the same files build task VM images and converge Nodes. So every machine runs one build, and the caches that depend on it fit everywhere. [Compute drivers: One PHP build](/reference/compute-drivers#one-php-build) gives the reasons for one build.

### OPcache sizing per role

The stock limit of 10,000 cached files overflows with two Laravel checkouts on one Node. OPcache memory belongs to the master, so sizing is set per service. Timestamp checks are a per-pool setting.

### Development checks every file

Checking every file costs about 1.5 ms per request. Turning OPcache off costs about 80 ms. So development keeps OPcache on and checks every request.

### No JIT

The tracing JIT gives a Laravel request no measurable gain and has known crash classes.

### One master per production user

All pools under one master share one OPcache. A reset through one pool's socket clears the cache of every pool. So each production user gets its own master, and a refresh never affects another application. The cost is one cache allocation and service per production Instance.

### Tuning stays on the Node

The operator tunes `local.conf` on the Node. Storing tuning in the Gateway was rejected: it adds storage and synchronization for settings the operator can edit in place. Tuning in the generated files was rejected, because Orbit rewrites them.

### Publish pools before starting PHP-FPM

On 2026-10-08, an unattended package upgrade restarted PHP 8.5 FPM on a development Node. The service did not start, because `orbit-scopes.conf` still named three removed task workspaces whose directories were gone. Every PHP 8.5 site on the Node was down. A convergence could not repair it, because package installation started the service before it rewrote the pools.

So installation now only enables the service, publication starts it, and convergence never renders a pool for a missing directory. Validating the candidate with `php-fpm -t` and restoring the previous file on a failed start still protect a running service from a bad change. Instance removal also withdraws the pool and reloads PHP-FPM before it deletes the checkout, so a removal never leaves a live pool that names a deleted directory.

### PHP from Composer

A PHP project already states its supported versions in `composer.json`. A PHP version field on the Instance was rejected, because it would duplicate that intent and need its own update lifecycle.
