---
title: "ADR 0215: Run one exact PHP build everywhere"
sidebarTitle: "0215 One PHP build"
description: "In progress. The machine software recipe names one exact PHP build, the Sury patch version and the loaded extension set, for Nodes, the Gateway, task VMs, and CI."
---

# ADR 0215: Run one exact PHP build everywhere

The [machine software recipe](/reference/compute-drivers#machine-software-recipe) is the single source of truth for Orbit's PHP build. It names, for each PHP version, the exact Sury packages and the extensions the PHP CLI loads. Every machine that runs Orbit's PHP installs that build and checks it: development and production Nodes, the Gateway, task VMs, and CI. A PHP patch release is one recipe change.

## Status

In progress.

Principle: [One way, one name](/mission#principles). One recipe entry defines the PHP build, and every environment installs it the same way. It also serves [Deterministic first](/mission#principles): each machine compares its PHP version and loaded extensions with the recipe and fails on a difference.

## Context

PHPStan writes `PHP_VERSION_ID` and the sorted `get_loaded_extensions()` into its result cache metadata. Pint keys its cache on the full PHP version. A cache from another PHP build is discarded. CI publishes its caches at `refs/orbit/ci-cache/<sha>`, and [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) imports them into new workspaces. Today the builds never match, so `composer check` in a fresh workspace runs these tools cold.

Before this decision the PHP build differed by machine:

- The recipe pinned exact Sury `.deb` files for PHP 8.5 and 8.4, but only the `app-dev` profile and the base image used them.
- The PHP step of the `app-dev` and `app-prod` roles installed bare package names from the Sury apt source, and only when a package was missing. A Node kept the build of the day it was set up, so two Nodes ran different builds.
- The `websocket` role and the Gateway machine had PHP from the Ubuntu archive.
- Hosted CI used `shivammathur/setup-php` with `php-version: '8.5'`. It floats to the newest patch and loads about 26 more extensions, such as `amqp`, `apcu`, `mongodb`, and `soap`. It cannot pin a patch release.
- The self-hosted runners had hand-installed PHP: Sabre by hand, and the shard containers from the Sury source on the day `tools/ci-runners/setup.sh` ran.
- The PHP version and extension list were repeated in the recipe, the PHP step, the runner setup script, the quickstart, the E2E guest scripts, and CI.

The maintainer's policy is that PHP stays in sync on every machine at the latest stable patch.

## Decision

The machine software recipe owns the PHP build. The PHP role, recipe convergence, the image build, and CI install that build and check it. Nothing else installs PHP on an Orbit machine.

### The recipe names the PHP build

The recipe gets a `php` object:

- `versions` maps each PHP version, `8.5` and `8.4`, to its `packages` and its `extensions`. `packages` lists the `php<version>-*` pins. `extensions` lists the module names that `php -m` prints for the CLI, in lower case and sorted, PCOV included.
- `shared` lists the Sury pins that every version needs: `php-common` and `libgd3`.
- `ci` names the version CI runs, `8.5`.

A profile names a PHP set as `php:<version>`. The loader expands it to the shared pins and the version's packages. A profile cannot name a PHP pin directly. `app-dev` and the base image name `php:8.5` and `php:8.4`. `app-prod`, `websocket`, and `gateway` name `php:8.5`. An `app-prod` Node still gets PHP 8.4 when one of its Instances selects it. A new `ci-runner` profile names `php:8.5` for a roleless Node that runs CI jobs, such as Sabre. It is a recipe profile, not a Node role: `node:converge-recipe --profile=ci-runner` selects it, and the Node records it.

The loader rejects a recipe in which:

- a `php<version>-*` pin is not in that version's set, or a set names an unknown pin;
- the PHP core pins of one version, the ones whose version starts with `<version>.`, have different versions. They must all equal `php<version>-common`;
- a Sury pin is built for another Ubuntu release than `ubuntu.release`;
- the extension list is unsorted, has duplicates, or lacks `core` or `pcov`;
- `ci` names a version that the recipe does not declare.

### Nodes install the recipe build

The PHP step of `app-dev` and `app-prod` installs the recipe's `.deb` files, not the newest candidate of an apt source. It downloads each file, checks its SHA-256 digest and its package name, version, and architecture, then installs it with `--allow-downgrades`. A package at another version, older or newer, is replaced. The Orbit Sury apt source is retired. The step deletes its source file and keyring, so `apt upgrade` cannot move PHP off the recipe. It still refuses another PHP apt source.

The step keeps the PHP-FPM safety rules of [PHP runtimes](/reference/php-runtime#development-runtime): installation only enables the shared service, and publication starts it. When packages changed and the service runs, the step reloads it only after `php-fpm<version> -t` passes. PCOV loads in the CLI and never in PHP-FPM, on every role. The step then compares `php<version> -m` with the recipe's extension list and fails on a missing or extra module.

[Recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe) covers every role that runs PHP, the Gateway included, and the `ci-runner` profile, because their profiles name the PHP set. It enables PCOV for the CLI only and checks the CLI's loaded extensions before any service restarts. It validates PHP-FPM with `php-fpm<version> -t` before it restarts the shared service. When the restart fails, it restores the PHP-FPM module links it changed, starts the service again, and stops. Production and Gateway Nodes are converged one at a time after this change merges.

### Tools cannot own role PHP packages

The apt Tool Manager protects `php<version>-*`, `php-common`, and `libgd3`. Adoption returns `tool.adoption_unsupported` with block `protected`. Install and update return `tool.package_role_owned` (HTTP 409) and change nothing. `tool:remove` of an existing Tool record for such a package, such as Tools 23 and 24 on Shark, deletes only the record and runs no package manager command; the package stays installed.

### CI installs the recipe build

`bin/php-build install`, through the local action `.github/actions/php`, replaces `setup-php` on hosted runners. It reads the recipe, downloads the `ci` version's `.deb` files into a cache that `actions/cache` keeps, checks each digest, removes every other PHP package, and installs the set. It installs a pinned Composer. `bin/php-build verify` then fails unless `PHP_VERSION` and `php -m` match the recipe. A job that ran without coverage keeps PCOV loaded and sets `pcov.enabled=0`, so the loaded extension set stays the same.

The self-hosted Sabre and shard runners do not install packages in a job. Their jobs run `bin/php-build verify` and fail with the expected and found build when the host differs. Recipe convergence with the `ci-runner` profile installs the build on Sabre, and `tools/ci-runners/setup.sh container` installs it in a shard container. The quickstart installs the Gateway machine's first PHP with `sudo bin/php-build install`.

### A PHP bump is one pull request

To move to a new Sury build, one pull request changes every pin of that version together: URLs, digests, and versions. The loader refuses a partial change. `bin/php-build bump` writes that change from Sury's signed package index. The daily `PHP build bump` workflow runs it and opens the pull request when the pins change. A maintainer merges it. After merge:

1. Each task VM host builds a new base image from the recipe.
2. Nightly recipe maintenance, or `php artisan node:converge-recipe`, brings each Node to the new build. The next PHP step of an Instance does the same for its versions.
3. CI installs the new build on its next run. The self-hosted runners fail until an operator converges Sabre and reruns the shard container setup.

## Rejected alternatives

- **`setup-php` with a floating version.** It cannot pin a patch, it loads extensions that Orbit does not use, and a hosted runner gets a new patch on its own schedule. Caches would still not match.
- **A pin for each environment.** CI, Nodes, and images would each keep a copy of the version and the extension list. The copies drift, and drift is the failure this decision removes.
- **A static PHP build.** [PHP runtimes](/reference/php-runtime#sury-packages-not-a-custom-php-build) explains why Orbit uses Sury packages: a static build is slower, lacks extensions, and makes Orbit own PHP security updates.
- **Install `package=version` from the Sury apt source.** Sury publishes only its newest build, so an exact version disappears from the index as soon as Sury moves on. The recipe's files are checked by digest, and the same files build images and converge Nodes.
- **A Gateway command and the GitHub App for the bump.** Nightly recipe maintenance opens its snapshot pull request that way and merges it by itself. A PHP bump changes software on every machine, so a maintainer reviews it. A scheduled workflow with a Python script needs no Gateway, PHP, or database to run.
- **Roll back the packages when PHP-FPM fails to start.** Sury no longer serves the previous build, so the previous files do not exist. Convergence validates the configuration and the loaded extensions before the restart instead, restores only the configuration it changed, and stops for the operator.
- **A `ci-runner` Node role.** A role adds services, firewall rules, Doctor checks, and an API surface. Sabre needs only its PHP build, so a recipe profile that a roleless Node selects is enough.
- **Load PCOV only on development machines.** The loaded extension set is part of PHPStan's cache key. PCOV records nothing until a test calls `\pcov\start()`, so it stays loaded in the CLI everywhere.

## Consequences

- PHPStan and Pint caches that CI publishes fit every workspace, Node, and developer machine that runs the recipe build.
- Every machine runs the same PHP behavior and the same security patches. A patch release reaches the whole fleet through one reviewed recipe change.
- Sury removes a file from its pool when it publishes a newer build. Until the recipe moves to the new build, a machine that does not have the pinned packages yet cannot install them. CI keeps its downloaded files in `actions/cache`, which hides this while the cache is warm. Keep the recipe on the newest Sury build.
- A Node that runs an extra PHP extension, or a PHP package that an operator installed by hand, fails the PHP step until the operator removes it.
- `app-prod`, `websocket`, and the Gateway machine now get `php8.5-pcov`, and `websocket` gets `php8.5-fpm`. PCOV is loaded in their CLI.
- A dedicated production PHP-FPM master keeps the build it started with until its next restart, which the next deployment of a new release causes.
- An operator updates the self-hosted runners after each PHP bump. Nightly maintenance skips roleless Nodes, so the operator converges Sabre.
- A bump pull request that `GITHUB_TOKEN` opens starts no CI. The repository secret `ORBIT_PHP_BUMP_TOKEN` avoids that.

## Affects

- Components: apps/e2e, apps/gateway
- ADRs: none
- Detail: [Compute drivers: Machine software recipe](/reference/compute-drivers#machine-software-recipe), [PHP runtimes](/reference/php-runtime), [Node provisioning: Package sources](/reference/node-provisioning#package-sources), [Tools: Protected packages](/reference/tools#protected-packages), [Feature delivery: CI](/reference/implementation-loop#ci)
- Verify: `RecipeTest`, `RemotePhpPackageManagerTest`, `ConvergeNodeRecipeTest`, `AptToolManagerTest` and the Tool action tests, the recipe convergence and image program tests, and the `bin/php-build` tests in `apps/e2e`
