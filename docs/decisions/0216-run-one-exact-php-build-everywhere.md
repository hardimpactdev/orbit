---
title: "ADR 0216: Run one exact PHP build everywhere"
sidebarTitle: "0216 One PHP build"
description: "In progress. The machine software recipe names one exact PHP build, the Sury patch version and the loaded extension set, for Nodes, the Gateway, task VMs, and CI."
---

# ADR 0216: Run one exact PHP build everywhere

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

The machine software recipe owns the PHP build. One installer, the remote program `php.install-build`, installs it and checks it on every machine. Nothing else installs PHP on an Orbit machine.

### The recipe names the PHP build

The recipe gets a `php` object:

- `versions` maps each PHP version, `8.5` and `8.4`, to its `packages` and its `extensions`. `packages` lists the `php<version>-*` pins. `extensions` lists the module names that `php -m` prints for the CLI, in lower case and sorted, PCOV included.
- `shared` lists the Sury pins that every version needs: `php-common` and `libgd3`.
- `ci` names the version CI runs, `8.5`.
- `rollback` names the Ubuntu tool that repacks an installed build, `dpkg-repack`. Every profile with a PHP set gets it.
- `pcov` lists the profiles whose PHP CLI loads PCOV: `app-dev`, `base`, and `ci-runner`.
- `purge_extras` lists the profiles that purge PHP packages outside the recipe. Only `ci-runner` may be on it.
- `mirror` is the base URL of Orbit's copy of the pinned files.

A profile names a PHP set as `php:<version>`. The loader expands it to the shared pins, the version's packages, and the rollback tool. A profile cannot name a PHP pin directly. `app-dev` and the base image name `php:8.5` and `php:8.4`. `app-prod`, `websocket`, and `gateway` name `php:8.5`. An `app-prod` Node still gets PHP 8.4 when one of its Instances selects it. A new `ci-runner` profile names `php:8.5` for a roleless Node that runs CI jobs, such as Sabre. Until [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role) builds the `ci-runner` role, a roleless Node selects this profile with `node:converge-recipe --profile=ci-runner`, and the Node records it. ADR 0213 takes this profile for the tools of its runner containers; when that role is built, the role selects the profile and the `--profile` option goes away.

The loader rejects a recipe in which:

- a `php<version>-*` pin is not in that version's set, or a set names an unknown pin;
- the PHP core pins of one version, the ones whose version starts with `<version>.`, have different versions. They must all equal `php<version>-common`;
- a Sury pin is built for another Ubuntu release than `ubuntu.release`;
- the extension list is unsorted, has duplicates, or lacks `core` or `pcov`;
- `ci` names a version that the recipe does not declare;
- `purge_extras` names another profile than `ci-runner`, or `mirror` is not an HTTPS URL.

### One installer

`php.install-build` is a [remote program](/reference/remote-programs). Recipe convergence and the PHP step of an Instance send it to the Node. The task VM image build runs it in the guest, and `bin/php-build install` runs it on a CI machine. Each run follows the same steps:

1. It refuses another PHP apt source, and PHP packages of the handled versions outside the recipe set. Both refusals come before any download or change and name what they found. A plan run, which a dry run uses, stops here.
2. It deletes the Sury apt source of earlier Orbit releases, so `apt upgrade` cannot move PHP.
3. It downloads each pinned file that is not installed at its version, from the mirror first and then from the pin's URL. It refuses a file whose SHA-256 digest, name, version, or architecture differs from the pin.
4. It keeps the installed build for a rollback: a cached `.deb` of each package that the change replaces, or one that `dpkg-repack` writes. It never deletes a file from the cache, `/var/cache/orbit/php-packages`.
5. It installs the files with `--no-remove` and `--allow-downgrades`. Package hooks cannot start or restart a service.
6. It loads PCOV in the CLI only on the PCOV profiles, and never in PHP-FPM. It checks that `php<version> -m` prints the recipe's extensions.
7. It restarts PHP-FPM after a change. It validates each master with `php-fpm -t` first, then checks after the restart that the service is active, that `php-fpm -m` loads the required extensions and not PCOV, and that every pool socket answers a FastCGI request.
8. When a check after the swap fails, it reinstalls the previous build with `--allow-downgrades`, restores the module links, and restarts the masters again.

Three options set the differences between callers:

| Caller | Extra packages | PHP-FPM restarts |
| --- | --- | --- |
| Recipe convergence | Refused, or purged on the `ci-runner` profile | The shared service and each dedicated production master; any failure rolls back |
| PHP step of an Instance | Kept, so a deploy never removes a site's extension | The shared service only; a service whose pools fail `php-fpm -t` is left running for the pool publication. Dedicated masters on the previous build are reported. |
| Image build | Refused | None; the image runs no service |
| `bin/php-build install` | Refused, or purged with `--purge-extras` | The shared service |

[Recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe) covers every role that runs PHP, the Gateway included, and the `ci-runner` profile. [Nightly recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance) converges these Nodes one at a time, production and the Gateway only in its overnight window. A roleless Node that recorded the `ci-runner` profile joins its service tier. An operator can still converge one Node deliberately with `node:converge-recipe`.

### Orbit mirrors the pinned files

A workflow on `main` downloads every pinned PHP file, checks its digest, and publishes it as an asset of the `php-build-mirror` release, named `<package>_<sha256>.deb`. The installer tries this mirror before Sury, so a rollback, a new Node, or a CI run still gets the exact build after Sury deletes it.

### Tools cannot own role PHP packages

The apt Tool Manager protects `php<version>-*`, `php-common`, and `libgd3`. Adoption returns `tool.adoption_unsupported` with block `protected`. Install and update return `tool.package_role_owned` (HTTP 409) and change nothing. `tool:remove` of an existing Tool record for such a package, such as Tools 23 and 24 on Shark, deletes only the record and runs no package manager command; the package stays installed.

### CI installs the recipe build

`bin/php-build install --ci --purge-extras`, through the local action `.github/actions/php`, replaces `setup-php` on hosted runners. It keeps the cache directory in `actions/cache` and installs a pinned Composer. `bin/php-build verify` then fails unless `PHP_VERSION`, each package version, and `php -m` match the recipe. A job that runs without coverage keeps PCOV loaded and sets `pcov.enabled=0`, so the loaded extension set stays the same.

The self-hosted Sabre and shard runners do not install packages in a job. Their jobs run `bin/php-build verify` and fail with the expected and found build when the host differs. Recipe convergence with the `ci-runner` profile installs the build on Sabre, and `tools/ci-runners/setup.sh container` installs it in a shard container with `--purge-extras`. The quickstart installs the Gateway machine's first PHP with `sudo bin/php-build install`.

The pull request that builds this decision merges only after its CI runs, so the self-hosted runners must hold the recipe build first. Before merge, an operator runs from the pull request branch, with the maintainer's approval:

1. On Sabre: `sudo bin/php-build install --ci --purge-extras`.
2. On Beast and Shark: `tools/ci-runners/setup.sh container NAME` for each shard container.

Then the pull request's CI runs, and main stays green after the merge.

### A PHP bump is one pull request

To move to a new Sury build, one pull request changes every pin of that version together: URLs, digests, and versions. The loader refuses a partial change. `bin/php-build bump` writes that change from Sury's signed package index. Nightly recipe maintenance runs it beside its snapshot pull request and opens a PHP build pull request through the Gateway's GitHub App when the pins change. Its branch is not a date branch, so the nightly auto-merge never takes it, and a maintainer reviews and merges it. After merge:

1. The mirror workflow publishes the new files.
2. Each task VM host builds a new base image from the recipe.
3. Nightly recipe maintenance, or `php artisan node:converge-recipe`, brings each Node to the new build. The next PHP step of an Instance does the same for its versions.
4. CI installs the new build on its next run. The self-hosted runners fail until an operator converges Sabre and reruns the shard container setup.

## Rejected alternatives

- **`setup-php` with a floating version.** It cannot pin a patch, it loads extensions that Orbit does not use, and a hosted runner gets a new patch on its own schedule. Caches would still not match.
- **A pin for each environment.** CI, Nodes, and images would each keep a copy of the version and the extension list. The copies drift, and drift is the failure this decision removes.
- **A static PHP build.** [PHP runtimes](/reference/php-runtime#sury-packages-not-a-custom-php-build) explains why Orbit uses Sury packages: a static build is slower, lacks extensions, and makes Orbit own PHP security updates.
- **Install `package=version` from the Sury apt source.** Sury publishes only its newest build, so an exact version disappears from the index as soon as Sury moves on. The recipe's files are checked by digest, and the same files build images and converge Nodes.
- **An installer for each caller.** Convergence, the Instance step, images, and CI each had their own install code, and the copies differed in their safety checks. One program gives every machine the same rollback, extras check, and health checks.
- **Mirror the files in the Gateway's package cache.** That cache lives on each task VM host and serves no other machine, and GitHub-hosted runners cannot reach the Gateway. A release in Orbit's repository serves Nodes, images, and every CI runner.
- **Stop for the operator when PHP-FPM does not start.** A production site would stay down until an operator acts. The installer keeps the previous build as files, so it restores it at once.
- **Purge extra PHP packages on every Node.** A client site can depend on an extension that an operator installed. Convergence refuses instead, and only a machine that runs nothing but CI jobs purges.
- **A separate scheduled GitHub workflow for the bump.** It would duplicate the nightly recipe pull request flow and its GitHub App, and a pull request that `GITHUB_TOKEN` opens starts no CI.
- **Auto-merge the PHP bump with the snapshot.** A PHP bump changes software on every machine, so a maintainer reviews it. The snapshot pull request keeps its narrow one-line auto-merge.
- **Wait for the `ci-runner` role of ADR 0213.** Sabre's hand-managed PHP would keep breaking the cache match until that role is built. A profile that a roleless Node selects manages Sabre now, and the role selects the same profile when ADR 0213 is built.
- **Load PCOV in every CLI.** It would make the loaded extension set equal everywhere, but production and the Gateway would pay its overhead in every CLI command. Caches are only used where checks run: on `app-dev` Nodes, task VMs, and CI, which all load PCOV.

## Consequences

- PHPStan and Pint caches that CI publishes fit every workspace, task VM, and developer machine that runs the recipe build.
- Every machine runs the same PHP behavior and the same security patches. A patch release reaches the whole fleet through one reviewed recipe change.
- A failed PHP-FPM start after a PHP change restores the previous build at once, from cached or repacked files.
- The cache directory grows with each build, because the installer deletes no file. An operator can delete files of builds that no Node runs any more.
- A Node with a PHP extension outside the recipe refuses recipe convergence until the operator removes it. The PHP step of an Instance keeps such an extension.
- `app-prod`, `websocket`, and the Gateway machine now get `php8.5-pcov` and `dpkg-repack`, and `websocket` gets `php8.5-fpm`. PCOV stays off in their CLI.
- Recipe convergence restarts each dedicated production PHP-FPM master after a PHP change, with a brief restart of each site. The PHP step of an Instance reports such masters instead, and their next restart loads the new build.
- An operator converges Sabre once with `--profile=ci-runner`; nightly maintenance converges it after that. An operator reruns the shard container setup after each PHP bump.
- The Gateway runs `bin/php-build bump`, so it needs `python3` and `gpgv`.

## Affects

- Components: apps/e2e, apps/gateway
- ADRs: [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role) uses the `ci-runner` profile that this decision adds
- Detail: [Compute drivers: Machine software recipe](/reference/compute-drivers#machine-software-recipe), [Remote programs](/reference/remote-programs#programs), [PHP runtimes](/reference/php-runtime), [Node provisioning: Package sources](/reference/node-provisioning#package-sources), [Tools: Protected packages](/reference/tools#protected-packages), [Feature delivery: CI](/reference/implementation-loop#ci)
- Verify: the direct program tests `tests/Programs/test_php_install_build.py`, `RecipeTest`, `RemotePhpPackageManagerTest`, `ConvergeNodeRecipeTest`, `AptToolManagerTest` and the Tool action tests, the image program tests, and the `bin/php-build` tests in `apps/e2e`
