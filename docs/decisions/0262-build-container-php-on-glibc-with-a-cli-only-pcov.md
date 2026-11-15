---
title: "ADR 0262: Build container PHP on glibc with a CLI-only PCOV"
sidebarTitle: "0262 Container PHP on glibc"
description: "In progress. The containers' static PHP build moves from a fully static musl binary to a static-php-cli build that is static except glibc. It gives php and frankenphp as before, and adds PCOV as a shared pcov.so that only the PHP CLI loads, so Pest TIA and coverage work in a container. The FrankenPHP server and production never load PCOV."
---

# ADR 0262: Build container PHP on glibc with a CLI-only PCOV

The runtime folder's [static PHP build](/reference/compute-drivers#static-php-build) moves from a fully static musl binary to a static-php-cli build that links everything statically except glibc. One build still gives `php` and `frankenphp`. PCOV comes from the same build as a shared `pcov.so` in the same folder part, and only the PHP CLI loads it. So Pest TIA and coverage work in a container, and the FrankenPHP server never loads PCOV.

## Status

In progress.

The owner decided on 2026-11-15.

Principle: [One way, one name](/mission#principles): one build stays the source of both binaries, and one file decides which program loads PCOV.

## Context

In a container, `php -m` shows neither `pcov` nor `xdebug`. So Pest TIA and `pest --coverage` cannot run there.

In static-php-cli v3, ext-pcov and ext-xdebug have `build-static: false`: static-php-cli builds them only as shared extensions. The current build is a fully static musl binary, and such a binary cannot `dlopen` a shared extension. That is why the recipe's `instances.php.without` lists `pcov` and `ffi` today.

[ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#php) requires one build for FrankenPHP and the PHP CLI, with the same PHP and the same extensions. Two builds, one for each binary, would break that.

The containers run Ubuntu 26.04.1. The `Static PHP build` workflow runs on `ubuntu-26.04`.

## Decision

- **One glibc build.** One static-php-cli build for each PHP version still gives `php` and `frankenphp`, with the same PHP and the same static extensions. Everything is linked statically except glibc. The build uses static-php-cli's target `x86_64-linux-gnu.2.17`: its Zig toolchain links against glibc 2.17, which is older than Ubuntu 26.04's, so the build runs in every container. The named CPU keeps the baseline x86_64 instructions. The pinned static-php-cli commit supports this target and shared extensions, so the pin stays.
- **PCOV as a shared extension.** The recipe's new field `instances.php.cli_extensions` lists `pcov`. The build makes each such extension shared and packs it as `modules/<name>.so`. The runtime folder unpacks it to `/opt/orbit-runtime/<version>/php/<php-version>/modules/pcov.so`, beside `bin/`. `without` lists only `ffi`.
- **Only the CLI loads PCOV.** The container phase, and the clone guest on clone, write `/etc/orbit/php/php-cli.ini` with one line: `extension=/opt/orbit-runtime/current/php/<php-version>/modules/pcov.so`, for the Project's PHP version, which is the version that `/usr/local/bin/php` links to. The PHP CLI reads `php-cli.ini` in its compiled-in config path, `/etc/orbit/php`. FrankenPHP's server has the SAPI name `frankenphp`, so it does not read the file. `frankenphp php-cli` takes the SAPI name `cli`, so it reads the file and loads PCOV, as `php` does. Only the server never loads PCOV.
- **Never in production.** A production container gets no `php-cli.ini`, so it never loads PCOV.
- **PCOV's defaults.** `pcov.enabled` stays at PCOV's default, on. Pest TIA and `pest --coverage` work with no flag.
- **Proof.** The check and the workflow accept a binary that is static except glibc and refuse one that needs any other shared library. They prove that the CLI with the CLI ini loads PCOV, that `pest --coverage` measures, and that the FrankenPHP server does not load PCOV. The runtime folder's check also requires that neither binary reads an ini file, because `frankenphp php-cli` cannot ignore one: it runs where `/etc/orbit/php` does not exist.

This decision amends the PHP part of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#php): the PHP CLI also loads PCOV. It supersedes nothing in 0250.

The reasons for glibc:

- **Extension loading.** A glibc binary can `dlopen`. PCOV needs it now, and other shared-only extensions can follow with one entry in `cli_extensions`.
- **Speed.** musl's memory allocator is slower for PHP than glibc's.

## Rejected alternatives

- **Keep musl and drop coverage in containers.** Pest TIA and coverage would need another machine, and the container would not be the place where the work runs.
- **A separate glibc CLI build beside the musl FrankenPHP.** Two builds would give the two binaries different PHP builds, against ADR 0250.
- **Load PCOV in `conf.d`.** Both binaries read the scan directory, so FrankenPHP would load PCOV too.
- **Turn `pcov.enabled` off and set it for each coverage run.** Pest TIA would then need a flag in every Project. The cost of the default is test time for Projects that use no coverage.

## Consequences

- The binaries need the container's glibc. A move of the container OS must keep a glibc at least as new as the build's.
- PHP runtimes notes that static-php's published glibc build crashed on certain CPUs ([Sury packages, not a custom PHP build](/reference/php-runtime#sury-packages-not-a-custom-php-build)). This build keeps the baseline x86_64 CPU target, and the runtime folder install runs the check on each Node, so a crash on a Node's CPU fails the install there.
- The change of `tools/static-php/craft.yml`, `bin/static-php-build` and the extension lists gives a new build id. The [two pull request flow](/reference/compute-drivers#change-a-static-build) applies: the first records the new `build` and `url` with `sha256: null`, main builds and publishes, and the second records the SHA-256. A build with `sha256: null` is not in the runtime folder. So no release may ship between the two pull requests, or `php/8.5` leaves the runtime folder of every container. In that window the Gateway also refuses to create or deploy a container for a PHP 8.5 Project, and its tests that do so fail.
- PCOV on by default costs test time in every CLI run of a container, also for Projects that use no coverage. Queue workers and other CLI Processes load it too.
- A `php` of another PHP version, started by its full path, reads the same `php-cli.ini`, which names the linked version's `pcov.so`. Only the Project's PHP version is linked, so this does not happen in normal use.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers)
- Detail: [Static PHP build](/reference/compute-drivers#static-php-build), [PCOV in the CLI only](/reference/php-runtime#pcov-in-the-cli-only), [PHP in a container](/reference/development-containers#php), [Shared folders](/reference/development-containers#shared-folders)
- Verify: the `Static PHP build` workflow's proof on main; Python tests of `bin/static-php-build` and the Swamp module `orbit_static_php`; Ansible fixture tests of the `php/<version>` part and the CLI ini; Pest tests of the recipe field and the folder part
