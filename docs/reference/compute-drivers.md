---
title: "Compute drivers"
description: "Incus task VMs for every Project."
covers:
  - apps/gateway/{app/{Domain/TaskVms,Infrastructure/TaskVms,Jobs/TaskVms,Actions/TaskVms,Domain/Compute}/**,app/Console/Commands/{RefreshTaskVmImageCommand,RefreshTaskVmImagesCommand}.php}
  - apps/gateway/app/{Models/TaskVm.php,Models/TaskVmImageBuild.php,Console/Commands/*ImageBuildsCommand.php,Console/Commands/PrepareTaskVmHostCommand.php,Console/Commands/PrepareTaskVmHubCommand.php,Console/Commands/ResolvesTaskVmHostNode.php}
  - apps/gateway/{app/Infrastructure/MachineSoftware/**,app/Console/Commands/MachineRecipeCommand.php,resources/machine-software/**,resources/remote/task-vms/**}
  - .github/workflows/{php-build-mirror,git-build-watch}.yml
  - {bin/{php-build,git-build-watch}}
  - apps/gateway/{config/task_vms.php,resources/task-vms/**,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/gateway/database/migrations/2027_01_29_000001_drop_upcloud_task_vm_support.php
  - swamp/workflows/{workflow-task-vm-image-ansible,workflow-operation-task-vm-image-refresh,workflow-task-nested-apply,workflow-task-nested-ready,workflow-proof-fleet-restore}.yaml
---

# Compute drivers

Schedule log viewers use the owning Node and the fixed Schedule journal unit. Opening a [live log stream](/reference/live-logs) does not provision a task VM or run a workflow.

Every task group runs in its own [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster. This holds for every Project, `orbit` included. See [Orbit Project](#orbit-project).

Task VMs are off until you [configure them](#configure-task-vms). Until then no task group can start.

Task VM enrollment and Node convergence share the public hub peer renderer. Enrollment writes only its guest peer; converging the VPN hub publishes the complete recorded list.

## Machine software recipe

The Node.js artifact pin is `24.22.1`, including the [October 21, 2026 security fixes](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases). Nodes and task images install it through the shared recipe installer. Vite+ project pins and retained releases select their own runtimes and must be updated separately.

The repository's machine software recipe is [`apps/gateway/resources/machine-software/recipe.json`](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/resources/machine-software/recipe.json). It declares one Ubuntu snapshot, tool version pins, the [PHP build](#php-build), and the tool sets for `gateway`, `app-dev`, `app-prod`, `operator`, and the task VM base image. Project dependencies, workspace setup, credentials, Node identity, and service configuration are not recipe inputs.

The recipe pins both the Vite+ runtime (`vp`) and CLI (`vp-cli`) to `1.4.0`. Project lockfiles stay independent: [deployment admission](/reference/tools#project-environments-in-the-vite-store) accepts a manager at least as new as the Project's locked Vite+ release, including Projects on `1.2.x`. A recipe update does not update those lockfiles.

The loader and validator define the recipe contract. The [base snapshot refresh](#refresh-the-base-snapshot) converges the base VM to that document. Claims [copy the base snapshot](#claims-copy-the-base-snapshot), and a topology that a contributor acquires by hand [copies its Nodes from its topology seed](/reference/incus-topologies#one-template-node).

### Recipe schema

The recipe is UTF-8 JSON, not executable PHP or a list of shell commands. The loader accepts schema version `1` only. It rejects missing fields, unknown fields, duplicate object keys, wrong types, empty required values, duplicate list entries, and unsupported sources, architectures, releases, roles, or tool identifiers. A schema change requires an explicit loader change; it is not a way to pass arbitrary installation instructions to a host.

| Field | Contract |
| --- | --- |
| `schema_version` | Integer `1`; this identifies the format, not the machine software version |
| `ubuntu.release` | Ubuntu release used by the current base image, initially `26.04` |
| `ubuntu.snapshot` | UTC snapshot identifier in `YYYYMMDDTHHMMSSZ` form, used at `https://snapshot.ubuntu.com/ubuntu/<snapshot>/`; a syntactically valid date alone does not prove availability |
| `architectures` | Exactly `["amd64"]`, the Debian name for x86_64. Linux Nodes are x86_64 only, so the loader refuses `arm64`. The agent's recipe commands also refuse a host whose `dpkg --print-architecture` is not `amd64`, at stage `architecture` |
| `tools` | Object keyed by the loader's known tool identifiers; each value has a concrete `version` (an Ubuntu floor or exact artifact version) and a `source` of `ubuntu`, `artifact`, or `internal` |
| `common` | Tool identifiers included in every role and the base image |
| `php` | The [PHP build](#php-build): `versions`, `shared`, `ci`, `rollback`, `pcov`, and `opentelemetry` |
| `artifact_mirror` | The HTTPS base URL, without a trailing slash, of the [recipe artifact mirror](#the-recipe-artifact-mirror). Installers try it before each artifact's own URL |
| `git` | The Git package set, `packages`, and the review date of the Git security notices, `usn_since` |
| `roles` | Object requiring `gateway`, `app-dev`, `app-prod`, and `operator`; optional profiles use known Node role names. Each value lists additional tool identifiers and PHP sets |
| `base` | List of additional tool identifiers and PHP sets for the task VM base image; this is an image profile, not a Node role |

An `ubuntu` tool names its Debian `package`. Its `version` is the complete Debian version, including epoch and distribution revision when present, not just an upstream release or a constraint. It resolves only from the selected Ubuntu snapshot's signed package indexes for the declared release and architectures. Every pin must equal the newest version across the release's archive, updates, and security pockets on every declared architecture. An older pin or one available only from today's rolling archive fails online validation.

Recipe installs use a temporary snapshot source and leave the normal Ubuntu sources in place, so unattended-upgrades can continue installing security updates. Application repairs the exact snapshot source file written by earlier Orbit convergence. It first saves that file beside the APT configuration as `ubuntu.sources.orbit-snapshot-backup`, then restores archive, updates, backports, and security sources with the Ubuntu archive keyring, and logs the repair through `logger` with the `orbit-recipe` tag. Other source content is preserved.

Node convergence and image installs refuse Ubuntu downgrades. Exact Debian artifacts permit downgrades only after an APT simulation rejects every non-artifact downgrade; installed Ubuntu recipe versions remain explicit install targets. Installation ends with `apt-get check` and `dpkg --audit`; audit output also causes failure. Sury PHP artifacts remain exact: the PHP build installer owns their installation and verification, and the Ubuntu floor rule never applies to them.

The recipe pins both Ubuntu `docker.io` and `containerd` for Docker roles and the base image. Migration reads those pins rather than choosing a separate containerd version.

An `artifact` tool has an `artifacts` object with exactly one key, `amd64`. Each entry contains an HTTPS `url` and exactly one digest field, `sha256` or `sha512`, with a full lowercase hexadecimal digest. A URL must identify the pinned release, not a moving installer or a `latest` alias. Artifact contents, not the spelling of the URL or a successful HTTP response, are the integrity check. The validator downloads and hashes the bytes without executing or installing them.

Debian artifact filenames omit a version epoch; the pin and package metadata retain it. Architecture-independent `.deb` packages declare `all` in their metadata.

Git and `git-man` are exact Debian artifacts from Launchpad's `ppa:git-core/ppa`, built for the recipe's Ubuntu release. The `git.packages` list names the owned packages. Both belong to `common`, so every Node profile and task VM image gets the same build. The loader requires matching versions and SHA-256 pins for both packages.

PPAs delete superseded builds. The recipe-level `artifact_mirror` serves every external Debian artifact as `<package>_<digest>.deb`. The `php-build-mirror` release holds all these artifacts. `bin/php-build mirror` and its workflow skip assets already published. Each installer and the validator tries the mirror before the original URL. A transport failure permits fallback; an integrity failure stops the operation.

Exact Git pins block automatic Ubuntu and PPA security updates. Orbit owns the delay between an upstream fix and a reviewed recipe bump. The daily `Git build watch` workflow queries Launchpad for published Git builds for the recipe release and Ubuntu’s security notice API. It opens or updates one GitHub issue for a newer build or a notice published on or after `git.usn_since`. The check job uploads its report with a read-only token. Only a separate publishing job on main's scheduled or manual runs gets `issues: write`; pull-request runs never publish issues. Network and parsing failures fail the workflow visibly.

Review each notice even when its Ubuntu version differs from the PPA build; the check does not claim that Ubuntu version ordering proves a PPA fix. Close the issue only after reviewing the fixes and rolling out the pin. Advance `git.usn_since` explicitly after reviewing the covered notices.

A maintainer bumps Git by hand. To bump Git, verify the PPA's signed `resolute` indexes, change both package versions, URLs, and SHA-256 digests together for amd64, download and hash both files, and validate the recipe. After merge, the mirror workflow retains the files.

The loader has a closed catalog of supported tool identifiers and their source kinds. Each `php<version>-<name>` pin of a declared PHP version is an `artifact` tool; the [PHP build](#php-build) decides which of them exist. A new tool needs a catalog change and validation tests as well as a recipe entry. Every profile reference must resolve to a catalogued entry in `tools`; an unused entry, a missing tool, or an incompatible source is an error. The recipe must record a concrete version, including for an Ubuntu floor: `latest`, `stable`, `lts`, version ranges, and unversioned installer URLs are not pins.

An `internal` tool is an Orbit-built binary pinned by `source_commit` (12 to 40 lowercase Git hexadecimal characters), an exact `version`, and architecture-specific entries in `artifacts`. Each entry has a simple `filename` and a full lowercase `sha256`; filenames resolve under the Gateway's sandbox-artifacts directory (or the command's `--artifact-directory` override). Source commit and digest identify the software; the directory and filename are only byte locations and are not part of the identity. The default directory is `$ORBIT_HOME/sandbox-artifacts`, using the Gateway's configured Orbit home.

Internal pins do not require publishing HTTPS releases. Third-party tools still require HTTPS artifact pins. Online validation fails clearly if a local file is missing or its digest differs. It never executes the binary. Existing installers keep their pre-install hash verification.

The Pi pin comes from `apps/pi-server` at source commit `23e7198f7c98`, file `pi-server-23e7198f7c98-linux-x64`, SHA-256 `df3aa5aea1788c3bcfdd576d0124b252b640c66c2bfa78e69b985bca9f39f438`. It is an `amd64` build, like every recipe artifact. It must equal `task_vms.pi.artifact_sha256`: the enrolment check compares the Pi build of the image with that setting. The first pin was `e5933d4c8696`.

A profile's effective set is the union of `common` and its own list, sorted by tool identifier. A `php:<version>` entry expands to that version's PHP set. Profiles cannot refer to other profiles, so role inheritance cannot hide software or form cycles. A multi-role Node's set is the union of its roles, not the base image set.

### Swamp worker

The one task VM base profile includes the official Swamp binary, pinned by version and SHA-256. Its release matches the orchestrator's `swamp serve`: `20261103.000636.0-sha.db3f6373`. Roll out a reviewed recipe pin and matching serve release together. Image verification checks the installed binary against its artifact digest. No fleet or account credential belongs in the image.

Before publishing the base image, the builder installs the Gateway's public Orbit root certificate at `/usr/local/share/ca-certificates/orbit-root.crt` and refreshes the system trust store. Orbit CA rotation requires rebuilding the base image before new task VMs use that CA. The worker explicitly uses this certificate for its WebSocket and HTTP/2 connections. Native VM enrollment starts its exact Node worker before Pi preparation. Task group work starts no worker on the VM; it runs from Node 209 over official SSH.

### Role and base tool sets

The recipe pins the OS packages and tool distributions for every profile. Exact versions, artifact URLs, and digests live in the recipe.

| Profile | Tools in addition to `common` |
| --- | --- |
| `common` | Bootstrap packages: `ca-certificates`, `curl`, Git and `git-man` from git-core, `gnupg`, `libnss-resolve`, `openssh-client`, `sudo`, `ufw`, and `wireguard` |
| `gateway` | Caddy from the Caddy project's release, not Ubuntu's Caddy package, and `php:8.5` |
| `app-dev` | `acl`, `attr`, Caddy, Composer, `docker.io`, OpenSSL, `php-curl`, `php-xml`, `unzip`, Vite+ (`vp`), Node, pnpm, npm/npx, Bun, and the `sqlite3` client for SQLite reads; `php:8.5` and `php:8.4` |
| `app-prod` | The application prerequisites and JavaScript/Composer managers, as for `app-dev`, and `php:8.5` only. The PHP step installs `php:8.4` when an Instance selects it |
| `swamp` | Caddy, and the `mysql` and `psql` clients for reads of registered external databases |
| `websocket` | Caddy, Composer, OpenSSL, `php-curl`, `php-xml`, and `php:8.5` |
| `ci-runner` | `incus`, `acl`, `attr`, `unzip`, and `php:8.5`. The [CI runner role](/reference/node-provisioning#ci-runner-role) selects this profile through the normal role union |
| `operator` | No additional role prerequisite tools; this is the recipe profile for a roleless operator Node, not a new Node role |
| `base` | The full `app-dev` set, explicit image-build packages described below, the nested Incus packages, Rust with Cargo/clippy/rustfmt, the Node agent, Pi, and the `swamp` role's SQL clients |

The base list explicitly contains the app-dev tools. It does not inherit a role. One pinned distribution can supply several binaries, such as npm and npx, or Rust and Cargo. Every fetched input must have a recorded digest. Production Nodes receive only their role tools.

The base also includes the existing image builder's 26 explicit Ubuntu packages: `openssh-server`; workspace tools `rsync`, `bind9-dnsutils`, `gcc`, and `libc6-dev`; and the 21 Chromium libraries that `resources/machine-software/recipe.json` lists for Playwright's headless Chromium. These are base-only tools, not common or role inputs. Each pin is the newest version in that authenticated snapshot, identical on both architectures.

The base also holds the nested Incus packages, so a task VM can run a nested topology without an install:

- The exact Incus 7.0 LTS package set, whose recovery API adopts the [topology seed](#the-base-vm-and-its-snapshot).
- `dnsmasq-base`, for the nested bridge.
- `btrfs-progs`, for the pool of the topology seed.
- `nftables`, for the isolation table.

Zabbly bundles QEMU, qemu-img, virtiofsd, and firmware with Incus. No separate Ubuntu packages provide them. The recipe installs packages without recommended packages. The `base` profile is amd64 only, because Pi is. The topology Nodes start from the same base and carry these packages unused.

The base also holds `fcgiwrap` for a topology's `gateway` VM. It serves the topology's Git repositories over HTTP, with Git's own `git-http-backend`. The base does not hold the `dnsmasq` service: no topology VM holds the `vpn` role, and the package's own unit fails at boot on any other Node, because systemd-resolved holds port 53. A `vpn` Node gets `dnsmasq` from its role. `dnsmasq-base` stays, for the nested Incus bridges.

After the packages, the image build adds `orbit` to `incus-admin`, because cloud-init adds groups only to a user it creates. Software verification checks the membership.

The cloud-init of a template builder declares the `orbit` account with UID 1000 and its own primary group. It runs no account-creation script. The `check` phase requires both UID and GID 1000. A taken ID or a pre-existing account with different IDs fails the build; Orbit does not renumber accounts or accept another group. Image smoke checks require the same IDs again.

### PHP build

The recipe is the single source of truth for Orbit's PHP build. Every machine that runs Orbit's PHP installs this build and checks it: development and production Nodes, the Gateway, the VPN private DNS listener, task VMs, and [CI](/reference/implementation-loop#one-php-build-in-ci). A PHP build is the exact Sury package versions and the modules available to CLI and PHP-FPM.

PHP supports amd64 only, like every recipe tool. The artifact mirror takes only the PHP artifacts declared in the recipe.

| Field | Contract |
| --- | --- |
| `php.versions.<version>.packages` | The `php<version>-*` pins of that version. Every such pin in `tools` must be in this list |
| `php.versions.<version>.extensions` | The build's modules, in lower case, sorted, without section headers. `Zend OPcache` is `zend opcache`. The list includes `core`, CLI-only `pcov`, and FPM-only `opentelemetry` |
| `php.shared` | Pins that every version needs: `php-common` and `libgd3` |
| `php.ci` | The version that CI installs |
| `php.rollback` | The Ubuntu tool that repacks an installed build for a rollback, `dpkg-repack`. Every profile with a PHP set gets it |
| `php.pcov` | The profiles whose PHP CLI loads PCOV: `app-dev`, `base`, and `ci-runner` |
| `php.opentelemetry` | The profiles that install OpenTelemetry and load it in PHP-FPM only: `app-dev` only |

The `vpn` profile names `php:8.5` because `orbit-private-dns.service` runs that CLI runtime. Its recipe identity changes with this membership, as with every profile change. VPN-only Nodes keep the shared PHP-FPM service disabled and inactive, including after package updates; an existing mask stays in place. A Node with a Gateway or application role instead validates and refreshes its active FPM masters.

After the PHP installer reports `applied` on an active VPN Node, recipe and VPN role convergence publish private DNS through the existing publication path. Publication self-tests the installed release and replaces a stale listener behind its active socket, then verifies socket ownership and the loaded catalog. A failed self-test leaves the running listener untouched and fails convergence clearly.

A profile names a PHP set as `php:<version>`. The set is the shared pins, the version's packages, and the rollback tool. OpenTelemetry follows `php.opentelemetry`. A profile cannot name a PHP pin directly to choose individual packages from a set. A version that no profile names is an error.

Extensions with their own release, such as `igbinary`, `imagick`, `opentelemetry`, `pcov`, and `redis`, keep their own versions. The loader also rejects a recipe in which:

- the PHP core pins of a version have different versions. A core pin has a version that starts with `<version>.`, and it must equal `php<version>-common`;
- a Sury pin is built for another Ubuntu release: its version must contain `+ubuntu<ubuntu.release>`;
- `php.ci` names an undeclared version;
- `artifact_mirror` is not an HTTPS URL.

PCOV loads in the CLI of the `php.pcov` profiles only, and never in PHP-FPM. Those are the machines that run checks and share CI caches, so their loaded extension set matches CI's. The other PHP profiles, `app-prod`, `websocket`, and `gateway`, keep PCOV installed but not loaded.

OpenTelemetry is installed for the `php.opentelemetry` profiles and enabled in PHP-FPM only. The `app-dev` profile needs its `OpenTelemetry\Instrumentation\hook()` API for php-data-bridge, an FPM-only bridge that instruments web requests. The installer always disables OpenTelemetry in the CLI, including links enabled by Sury's package hooks. This preserves the CLI extension parity with CI that PHPStan's shared result cache needs.

On a Node with both app-dev and app-prod, dedicated production FPM masters also load OpenTelemetry because they share the Node's FPM extension configuration. Production, CI-runner, base, and Gateway profiles do not install its packages. If a Node loses app-dev, convergence keeps an installed OpenTelemetry package as a known disabled package and disables it in both CLI and FPM. The recipe lists OpenTelemetry among the build's packages and modules; the CLI module check always excludes it.

#### Install the PHP build

`php.install-build`, a native [agent command](/reference/node-agent#commands), is the one installer of the PHP build. `php.inspect-build` checks the same typed pins without changing the machine. Recipe convergence and the PHP step of an Instance call these commands through the agent runner. The image build runs it in the guest, and `bin/php-build install --profile=PROFILE` runs it on a bootstrap or CI machine.

Build the checkout's agent first with `cargo build --release --locked` in `apps/agent`. The local adapter sends typed input with that binary's digest and refuses a missing binary or a failed command. Install and `bin/php-build verify --profile=PROFILE` require a recipe profile. Both derive the packages and loaded CLI extensions from that profile, including PCOV and OpenTelemetry policy. A profile that does not declare the selected PHP version is refused.

After installation, recipe convergence on app Nodes and an Instance PHP step call `php.configure-runtime` to publish the Orbit FPM module. The Gateway supplies the selected version, supported release, OPcache settings, and [request and upload limits](/reference/php-runtime#request-and-upload-limits). The agent publishes and verifies the module, gracefully reloads valid active shared and dedicated production masters when it changes, and enables the shared service without starting it. A failed publication restores the previous file and module link and reloads the masters already changed.

The `php-runtime` Node footprint also publishes this module during Node convergence and fleet rollout. The agent skips an absent FPM binary without writing files. Recipe convergence publishes again after installation, so the later installation receives the module even when the footprint digest matches.

The installer owns `/etc/php/<version>/{fpm,cli}/conf.d/99-cve-2026-109618-upload-progress.ini` for every PHP version it installs. It writes `session.upload_progress.enabled = 0` to mitigate CVE-2026-109618 and CVE-2026-109619, which allow unauthenticated remote code execution through session upload progress in multipart requests. It replaces the operator's emergency file at the same path and repairs missing or changed files on later convergence.

A settings-only change validates every active PHP-FPM master, including dedicated production masters, regardless of the caller's FPM scope. It gracefully reloads those that pass. An Instance step reports and logs invalid masters as deferred, keeps the INI, and continues reloading the other masters. Recipe convergence instead fails and restores the previous settings. Rollback uses reload for masters that were reloaded and restart only for masters that were restarted. Images have no active masters, so this reload is a no-op.

Merging this mitigation does not change the recipe identity, so it starts no fleet rollout or image rebuild. A Node receives the file at its next PHP installer run: an Instance PHP step. Task VM base images and topology seeds receive it at their next rebuild through the same installer.

The recipe pins PHP 8.4.27 and 8.5.12, which fix CVE-2026-109618 and CVE-2026-109619. The drop-in stays as defence in depth. Disabling upload progress is not a complete mitigation; installing the fixed builds is required. See the [PHP security release notice](https://www.php.net/archive/2026.php#2026-10-21-5).

Before it changes anything, the installer checks the machine:

1. It refuses another PHP apt source, such as `ppa:ondrej/php`, and names it. With `purge` it deletes the source instead.
2. It finds installed PHP packages outside the handled recipe sets. OpenTelemetry packages kept disabled after app-dev removal do not count.
3. With `refuse` it refuses such packages. With `keep` it leaves them installed. With `purge` it purges them later in the run.
4. A plan run, such as a dry run, stops here and lists the packages it would change.

Then it gets the files:

1. It deletes the Sury apt source of earlier Orbit releases, so `apt upgrade` cannot move PHP.
2. It downloads missing pinned files with one `curl --parallel --parallel-max 4` process per source pass. It tries `artifact_mirror` first, then the pin's URL for files with transport failures.

   Each file uses a separate candidate path. All files pass verification before rollback preparation and serial package installation. An interrupt stops the curl process and its active transfers before the installer exits.

   Each transfer has a 15-second connection limit. Each source pass divides a 300-second budget across its batches of four transfers and sets curl's total transfer limit accordingly. Both source passes fit within 600 seconds of the installer's 900-second limit.
3. It refuses a file whose SHA-256 digest, package name, version, or architecture differs from the pin.
4. It keeps every file in `/var/cache/orbit/php-packages`. A cached file with the expected digest is not downloaded again. A corrupt entry is replaced.

   Cache writes stage a complete file in the cache directory, check its digest, then rename it atomically into place. The cache grows with each build; an operator can delete the files of builds that no machine runs.

Then it keeps the installed build for a rollback:

1. For each package that the change replaces or purges, it looks for a `.deb` of the installed version in Orbit's cache.
2. Next it looks in apt's archive cache, `/var/cache/apt/archives`.
3. Otherwise it rebuilds the installed package from its files. `dpkg-repack --generate` writes the build tree, and the installer builds the file with `dpkg-deb`. A missing `dpkg-repack` is first installed at its recipe version.
4. Without a rollback file, it refuses with `rollback_unavailable` before any change.

Every rollback file must carry the installed package name, version, and architecture. The installer checks the file, not a tool's exit code: `dpkg-repack` exits 0 even when it writes no file. Each source has an integrity check:

- A cached file is named after its SHA-256 digest, and its bytes must still match that name.
- An apt archive file must match the MD5 sums that dpkg recorded for the installed package, in `/var/lib/dpkg/info/<package>.md5sums`.
- An apt archive file is not used when dpkg recorded no MD5 sums.
- A rebuild comes from the installed files themselves.

The rebuild leaves out each conffile that is not on disk. An operator or Orbit may remove a conffile on purpose, such as the default `www` pool of `php<version>-fpm`, and `dpkg-repack` alone then refuses the package. Sury deletes old builds and the mirror holds only pinned ones, so a download by version is not a reliable source.

The installer removes its temporary work directory with the same privilege used to repack packages. Repacked trees belong to root even when the installer runs as the enrolled SSH user. Rollback files are already in Orbit's cache before this cleanup and the package swap.

The installer keeps a disabled default pool disabled. When `php<version>-fpm` is installed and `pool.d/www.conf` is absent before the change, an install or a rollback that brings it back is undone at once. An enabled default pool stays as it is, and a first install of `php<version>-fpm` keeps the pool it brings.

Then it swaps the build:

1. It installs the files with `--no-remove` and `--allow-downgrades`. A newer or an older build becomes the recipe build, and no other package is removed.
2. Package hooks cannot start or restart a service during the install.
3. It loads PCOV in the CLI on the `php.pcov` profiles only, and never in PHP-FPM.
4. It enables OpenTelemetry in PHP-FPM only on the `php.opentelemetry` profiles and disables it in the CLI on every profile.
5. It checks that `php<version> -m` prints the recipe's extensions for the selected profile.

After a change, it restarts PHP-FPM:

1. It validates each master with `php-fpm<version> -t`. A dedicated production master uses its own `--fpm-config`.
2. It restarts the master and checks that the service is active.
3. It checks that `php-fpm<version> -m` loads the required extensions and excludes PCOV.
4. It checks that OpenTelemetry loading matches the selected profile.
5. It sends a FastCGI request to each pool socket in the master's configuration and waits for the answer.

When the install or a check after it fails, the installer rolls back. It reinstalls the previous build with `--allow-downgrades`, restores the module links, and restarts the masters again. It ends with the failure's outcome and `restored: true`: `install_failed`, `modules_mismatch`, `fpm_invalid`, or `rolled_back` for a master that fails after its restart. When the rollback fails too, it ends with `rollback_failed`.

Each caller sets two options:

| Caller | Extra packages | PHP-FPM |
| --- | --- | --- |
| Recipe convergence | `refuse` on every host, including a Node with `ci-runner`; the container installer requests `purge` explicitly | `all`: the shared service and each active dedicated master; a failure rolls back |
| PHP step of an Instance | `keep`, so a deploy never removes a site's extension | `shared`: the shared service, left running when its pools fail `php-fpm -t` |
| Image build | `refuse` | `none` |
| `bin/php-build install` | `refuse`, or `purge` with `--purge-extras` | `shared` |

The outcomes are `planned`, `current`, `applied`, `platform_unsupported`, `conflicting_source`, `extra_packages`, `unowned_extensions`, `download_failed`, `rollback_unavailable`, `install_failed`, `modules_mismatch`, `fpm_invalid`, `rolled_back`, and `rollback_failed`. After a refusal or a failure, the previous build stays installed.

Some extensions have no package: setup-php, for example, copies `.ini` and `.so` files in directly. An installer run that refuses extras refuses these too, with `unowned_extensions`. An unowned `.ini` counts as an extension only when it has an `extension=` or `zend_extension=` line, and the extension's name comes from the library it loads. A settings-only `.ini`, such as Orbit's own `orbit-runtime.ini`, is never refused, moved, or purged.

Library names are mapped to their `php -m` names first: `opcache.so` is `zend opcache`. An `.ini` that loads a recipe extension from a file no package owns, such as `extension=/opt/x/redis.so`, is refused too, because only package-owned files are checked. The refusal names each `.ini` as `php<version>:<modules>`; an `.ini` that loads several libraries joins them with `+`, as in `php8.5:apcu+redis`. With `--purge-extras`, it removes their `conf.d` links and moves their files to `/var/cache/orbit/php-unowned-extensions/`. A rollback moves them back.

A package at its pinned version can still hold other files, for example a `.so` that setup-php overwrote. In apply mode, the installer runs `dpkg --verify` on every recipe package. It installs a package again from its pinned file when a file's checksum changed or a file is missing. A changed conffile stays, because it may hold an operator's settings. The result lists these packages under `repaired`.

#### The recipe artifact mirror

The `Recipe artifact mirror` workflow, `.github/workflows/php-build-mirror.yml`, keeps Orbit's copy of the pinned files. It runs on each push to `main` that changes the recipe, `bin/php-build`, the artifact URL helper, or the workflow, and on manual dispatch from `main`. Only its publishing job gets `contents: write`; the workflow defaults to a read-only token.

`bin/php-build mirror` downloads every external Debian artifact of every architecture and checks its recipe digest. The workflow lists published assets before downloading, skips those files, and uploads each missing file to the `php-build-mirror` release, named `<package>_<digest>.deb`. It retains the existing release URL. That release is a prerelease and never the latest release.

Gateway intents capture the ordered `ArtifactPin::candidateUrls` list. Tool Manager setup (including Git), role PHP packages and native Caddy try the mirror before upstream. The shared `node-artifact-fetch` workflow downloads through official SSH and classifies curl's structured exit code. Only transport failures permit the second candidate. Local I/O and size-limit failures stop. Native SHA-256 verification must succeed before the staged file is published. The retained agent Caddy installer still uses its frozen upstream-only contract. The corrupt-mirror proof requires a failed run, no upstream attempt, no published file and no staged file after cleanup.

New pins work through upstream before the mirror workflow publishes their assets after merge. Other artifact formats, including the agent and CLI release binaries, retain their existing URLs.

The native candidate contract rolls out in three releases because Swamp loads before the Gateway switches:

1. Expand the Tool Setup pin schema to accept optional `urls`, retaining required `url`. Consumers still read `url`.
2. After that schema release is live, the Gateway emits both `url` and the ordered `urls` from `ArtifactPin::candidateUrls()`. Consumers still read `url`.
3. After that producer release is live, native consumers require `urls` and the Gateway stops emitting `url` for these artifacts.

The final Tool Setup schema accepts an unused optional `url` during the Gateway switch. No consumer chooses a contract or reconstructs candidates. Re-admit work captured before the producer release; do not resume an older frozen intent against the new consumer.

Each dependent PR stays draft until its predecessor's release is verified live. Merge order alone does not satisfy this gate. The retained agent Caddy contract is outside this rollout.

Sury, Launchpad, and Zabbly can delete superseded builds. The mirror keeps rollbacks, new Nodes, images, and CI on the exact build after that.

#### Bump Vite+

Change both `vp` and `vp-cli` versions, URLs, and integrity pins together. Regenerate `apps/gateway/resources/machine-software/vite-plus-package-lock.json` with the recipe's Node/npm, using a private package whose only dependency is the exact `vite-plus` version and `npm install --package-lock-only --ignore-scripts`. Review the complete lock, including platform packages. Update `tools.vp.lock_sha256` to the file's SHA-256. This digest is part of the recipe identity. Both images and Nodes install the locked runtime with scripts disabled and keep the existing global tool store.

#### PHP packages of an intent

`PhpBuildInstaller::packageFiles()` turns the packages of the installer input into the files an intent carries: each with its mirror-first download candidates and its path in the role cache (`/var/cache/orbit/role-php`). The role add and the [Gateway bootstrap plan](/reference/node-provisioning#gateway-bootstrap-prerequisites) both use it, so they install the same pinned files.

#### Bump the PHP build

Sury keeps only its newest build in its pool, so keep the recipe on Sury's newest patch. One pull request changes every pin of a version together, with each URL, SHA-256 digest, and version. A partial change fails the loader.

`bin/recipe-artifacts bump` makes that change. It downloads Sury's signing key and refuses it unless it matches the pinned SHA-256 digest. It verifies the signed `InRelease` file of the recipe's Ubuntu release with `gpgv`, and checks each package index against it. For every architecture, it then moves each pin of every PHP set, `php-common` and `libgd3` included, to the newest version in the index, with its URL and digest. It stops without writing when Sury is between builds, so that two core pins of one version differ. `--dry-run` prints the changes and writes nothing.

The standalone recipe CLI uses `python3`, `gpg` and `gpgv`. Run `php artisan orbit:machine-recipe --validate` to check a bump by hand. Recipe pins change only through reviewed manual edits. After merge:

1. The [mirror](#the-recipe-artifact-mirror) workflow publishes the new files.
2. Each task VM host [refreshes its base snapshot](#refresh-the-base-snapshot).
3. The next [PHP step](/reference/php-runtime#php-build) of an Instance brings its versions to the recipe.
4. Hosted CI installs the new build in its next run. See [runner updates](/reference/implementation-loop#one-php-build-in-ci) for the pre-merge operator steps.

### Recipe identity

The recipe identity is `sha256:<digest>`, computed from the validated document's canonical JSON after removing internal-artifact filenames. Loading a recipe resolves its `agent` field from the Gateway delivery manifest before hashing. This field contains the delivered Linux SHA-256 digests keyed by platform; the source recipe uses `null` and carries no agent version or artifact pins. Changing either Linux digest changes the identity. macOS artifacts and delivery storage locations do not. Stored build recipes retain the resolved map, so resuming a build cannot silently substitute another agent. A build or Node convergence refuses a missing or mismatched target pin before applying software.

Internal source commits and digests remain in the identity; relocating identical internal bytes does not change it. Canonicalization recursively sorts object keys by byte order, preserves array order, emits no insignificant whitespace, leaves slashes and Unicode unescaped, and adds no final newline. Only JSON integers, strings, arrays, objects, booleans, and null are permitted; schema fields further restrict their types. Duplicate keys are rejected before hashing. The identity is derived, not stored as a self-referential field in the recipe.

Whitespace and object-key order do not change the identity. Changing a snapshot, version pin, external artifact URL, `artifact_mirror`, source commit, digest, role assignment, schema version, or list order does. The identity covers all profiles together; there is no independent version counter for each role and no Git commit SHA masquerading as the recipe version. Git history provides provenance, while the content hash names the exact software intent even when the same recipe appears in another commit.

A [refresh](#refresh-the-base-snapshot) records the same identity on the build row of the base snapshot. Schema version `1` and a short display hash are not substitutes.

### Show and validate the recipe

The Gateway command `php artisan orbit:machine-recipe` shows the repository recipe's identity, snapshot, tool version pins, and effective profile tool sets. `--role=gateway`, `--role=app-dev`, `--role=app-prod`, or `--role=operator` selects one role; service profiles also cover `vpn`, `router`, `ingress`, `metrics`, `database`, `websocket`, and `analytics`; `--role=base` selects the base image profile. `--path=<file>` selects a candidate recipe for review or a validation fixture. `--architecture=amd64` requires each displayed profile to support that architecture; any other value, such as `arm64`, fails with `Unknown architecture`. Without it, the output lists each profile's supported architectures, which is `amd64`.

Showing a recipe always performs structural validation and makes no claim about remote availability. Add `--json` for the complete recipe (including URLs, digests, internal filenames, and source commits), its identity, validation state, and selected effective profiles. JSON errors contain only an `error` message and exit nonzero; failed validation never emits a success state.

Add `--validate` to verify availability and integrity as well as structure. For example, from `apps/gateway`, run `php artisan orbit:machine-recipe --validate` before accepting a recipe change. Validation checks the whole recipe across every declared architecture even when `--role` limits the displayed set. It requires network access, `gpgv`, the OS-maintained `/usr/share/keyrings/ubuntu-archive-keyring.gpg`, and readable internal artifact files, but no database, SSH connection, image builder, or root privileges. Use `--artifact-directory=<directory>` to resolve internal filenames from a disposable or staged directory instead of the Gateway's sandbox-artifacts directory. It does not install packages, change apt sources, create Nodes, build images, or change which image a claim can use.

Online validation verifies that the exact snapshot exists for the release and architectures, and that every Ubuntu pin equals the newest version in its authenticated package indexes on all declared architectures. It also checks that every external or Gateway-local artifact is available and matches its recorded digest. Redirects or server fallback to another snapshot or a rolling archive must not turn an unavailable pin into success.

Use `php artisan orbit:machine-recipe --validate-ubuntu` to check only Ubuntu snapshot pins, without fetching artifacts. It reads `recipe.json` as written, with no agent pin, because Ubuntu pins do not depend on the release's agent. It therefore needs no agent delivery manifest, and its output shows the source recipe, not a release identity. CI runs this signed-index check when `recipe.json` changes or the event has no comparison base; a hand-edited snapshot date with stale pins fails Required checks. A handoff push compares with the branch's merge base with `origin/main`, not the previous handoff, so later handoffs keep checking recipe changes that have not merged.

CI restores and stores verified InRelease files and compressed Packages indexes with stock `actions/cache` only when the recipe needs validation, keyed by snapshot identifier and architecture set. `ORBIT_RECIPE_SNAPSHOT_CACHE` selects the metadata directory. Cache reads repeat signature, release, architecture, size, and SHA-256 checks; a cache hit makes no network request. Cached metadata never substitutes for a missing pin.

Snapshot metadata fetches retry HTTP 5xx and connection failures at the same pinned URL, with four attempts and waits of 10, 20, and 30 seconds. Each attempt allows 15 seconds to connect, stops if transfer speed stays below one byte per second for 15 seconds, and has a 120-second total cap. A slow transfer that keeps making progress can finish within that cap. Persistent HTTP failures or stalls normally exhaust the attempts in about two minutes; four transfers that each reach the total cap take at most nine minutes including backoff. HTTP 4xx and verification failures fail immediately.

Artifact downloads keep their separate policy: a 600-second total cap per attempt, up to three attempts with 500-millisecond waits for HTTP 500/502/503/504, and no retry for connection failures before trying the next artifact URL.

Node convergence uses the same four-attempt backoff for snapshot indexes and package downloads, with 15-second transport timeouts. A simple loop retries only explicit HTTP 5xx or connection-reset fetch failures. APT’s own retries stay disabled because they retry some HTTP 4xx and miss some HTTP 5xx. An unsigned-repository message caused by the same failed release fetch does not suppress the retry. Authentication, digest, package, and mixed failures stop immediately.

Git build watch retries upstream 5xx and connection failures with four attempts, a 15-second request timeout, and waits of 10, 20, and 30 seconds. Its `check` job is an advisory update signal, not a required merge check; persistent failures remain visible. Network failures are failures to verify, not evidence that a release is invalid or permission to choose another version. A successful structural-only show is not a successful availability check.

| Failure | Required diagnostic |
| --- | --- |
| Invalid JSON or schema | File and field path, with the expected shape or unsupported schema version |
| Unknown role or tool | The rejected name and the valid choices; unresolved references identify the profile and tool |
| Invalid or unavailable snapshot | The snapshot identifier, release, architecture, and failing metadata URL or verification reason |
| Unavailable package or version | Tool identifier, exact requested version, architecture, snapshot/source, and what could not be resolved |
| Missing artifact or digest mismatch | Tool identifier, architecture, URL, and the expected and actual digest when bytes were obtained |
| Network or signature failure | The failing source and reason; report that availability could not be verified rather than silently falling back |

The command exits zero only for a successful requested operation and nonzero for any failure. It never prints a validation-success result for a partially checked recipe. Tests must cover malformed recipes, unknown role and tool names, well-formed but unavailable snapshots and versions, and digest failures with controlled responses; a syntax-only date test or mocked unconditional success does not prove remote validation.

### Why it works this way

A closed, versioned JSON document is reviewable without executing code. A content hash names all role sets together without a manually maintained counter. Separate structural and online checks distinguish malformed intent from unavailable inputs. Explicit artifact hashes cover software that Ubuntu's snapshot cannot freeze. One catalog prevents misspelled tools or roles from silently changing an image. The [image build fence](#the-base-vm-and-its-snapshot) owns publication; the validator checks recipe inputs.

One recipe identifies the software in task VM images. A changed recipe rebuilds the whole base from stock on each host. Partial builds and incremental images were rejected because previous image history would become another input. A fixed nightly rebuild without a versioned recipe would not identify the intended software or connect it to production.

Trusted Gateway jobs build images. Agents have root inside task VMs, so letting them publish the shared result would allow one Project to affect another.

Every workspace clones and runs Project setup. Per-Project snapshots would keep setup coupled to image builds, invalidation, and retention. Package and CI caches speed setup without a saved workspace. Fresh VMs also avoid capacity held by idle pre-enrolled guests and their refill lifecycle, at the cost of boot time.

#### One PHP build

PHPStan writes `PHP_VERSION_ID` and the sorted `get_loaded_extensions()` into its result cache metadata, and Pint keys its cache on the full PHP version. [CI cache refs](/reference/implementation-loop#ci-cache-refs) only help where the PHP build matches exactly. One build also gives every machine the same behavior and the same security patches.

A pin for each environment was rejected. CI, Nodes, and images would each keep a copy of the version and the extension list, and the copies drift. [Feature delivery](/reference/implementation-loop#one-php-build-in-ci) explains why CI does not use `setup-php`. [PHP runtimes](/reference/php-runtime#why-it-works-this-way) explains the Sury packages, the recipe's files instead of the Sury apt source, the one installer, and PCOV.

The Gateway cannot hold the mirror: it serves no machine outside the fleet, and GitHub-hosted runners cannot reach it. A release in Orbit's repository serves Nodes, images, and every CI runner.

Purging extra PHP packages on every Node could break a client site that uses an extension an operator installed. Convergence refuses instead. Only the container installer uses the `ci-runner` profile to purge, because each container runs only CI jobs. A host with that role can share other workloads and always refuses extras.

The `ci-runner` role selects the CI host recipe and replaces the recorded-profile exception. Roleless operator Nodes do not gain workload or maintenance status from old profile settings.

#### Git from exact artifacts

Tracking a live PPA would make it a second owner of Git, and an exact APT version alone does not last, because Launchpad deletes superseded builds. The cost is that Orbit, not unattended-upgrades, delivers Git security fixes, so the daily `Git build watch` workflow signals each one for review.

## Task VMs

Swamp requests review parking through `tasks.park`. A parked row reports unknown power until the provider confirms stopped. A wake invalidates that confirmation. Gateway resource observation refreshes parked power in every group status and never starts or stops a VM.

A task VM is an Ubuntu 26.04 VM on an Incus host. It is a copy of the [base snapshot](#the-base-vm-and-its-snapshot) of its host, which each host refreshes to the same recipe and which holds the `app-dev` tool set. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the normal Instance and task paths, pinned to that Node. Task VMs run only on the Incus hosts Beast and Shark. When neither host has capacity, the group waits.

The host's image, instance-name, and host-wide resource inventories use the typed agent commands `incus.images`, `incus.instances`, and `incus.inventory`. Rust validates complete Incus output and returns fingerprints, aliases, and resource names. Instance-name reads first prove that the configured project exists; a missing project cannot count as an empty host. Host-wide inventory includes every project and storage pool so the Gateway can compare it with recorded ownership. These reads take no mutation lock. Invalid or incomplete output refuses with `task_vm.invalid_host_output`; a failed Incus call returns `task_vm.host_command_failed`.

`task-vms:prepare-host` sends the configured project, bridge, subnet, pool, and optional ZFS dataset to `incus.prepare-host`. The command validates existing storage, project network scope, and bridge address before changing them. It refuses to create a pool on a dataset that already exists. Rust owns the ACL, isolated profile NIC, project restrictions, stock image copy, and UFW forwarding rule. Repeated calls converge the same resources under the host and firewall locks. A setup failure returns `task_vm.setup_failed`; it does not claim to undo a partly completed setup.

`incus.volumes` reads the custom volumes in the configured project and pool. Swamp owns volume changes through keyed Operation children; the Gateway keeps volume ownership and inventory reads.

`incus.vm-state` returns one VM's running state, explicit `settled: true`, guest bridge address, and original image fingerprint. It refuses a VM unless Incus reports `Running` or `Stopped`.

`incus.vm` creates, starts, stops, or deletes that VM under one target lock. The agent owns exact-name matching, Incus output validation, clean shutdown followed by forced stop, and the observation after a failed mutation. Create intent supplies the Gateway public key, image claim, resource limits, and topology requirement. The agent renders the fixed cloud-init account and QEMU configuration, clones a required seed before launch, and refuses an existing VM with a different claimed image. Delete removes the VM before releasing its seed clone. The Gateway keeps placement and resource-size policy.

Image builders and smoke VMs use the same `incus.vm` boundary. Their `create_image` intent selects the source image, account key, resource limits, and builder account mode. Rust renders cloud-init, attaches an Incus cloud-init configuration disk, and initializes the VM with NIC isolation. Before its first start, Rust removes the source image’s NoCloud seed templates. The disk supplies the account configuration at power-on, so cloud-init cannot run with an empty datasource while the Incus agent copies seed files and requests a reboot. This applies to stock builders, smoke VMs, and task VMs.

A stopped VM receives the same metadata preparation before it starts. A repeat preserves a VM from the requested image and refuses a different image. `remove_instance` removes an exact VM without changing storage volumes; the Gateway's existing image and orphan sweep decides which names it may remove. An absent VM counts as removed.

Before enrollment, guest commands use the same command runner, delivery manifest, and mismatch handling as managed Nodes. Incus carries them through its host's pinned transport with `incus exec`. The Incus adapter refuses source Git credentials before contacting the host, including for a builder with no task VM record. The transport stages only the pinned agent candidate. A builder has no Node row: an installation refusal fails its build, and never records a failed pin on the Incus host or creates a Node record.

`incus.instance-images` reads the original image fingerprint of each instance.

The template builder verifies the guest account before it becomes a template. Cloud-init uses the configuration disk on its first boot.

`guest.cloud-init` returns readiness from the guest’s complete cloud-init result and reports bootstrap errors as typed refusals. `guest.fingerprint` reads and validates the ed25519 host fingerprint through the trusted guest transport. Image builders install command mode before their first readiness check. A guest transport that cannot yet return output remains pending within the existing boot deadline; malformed or refused agent results fail the build. Incus smoke checks also prove a host key and one completed boot before promotion.

### From claim to workspace

A task start (`tasks.provision`, [command](/cli/task/start)) takes the lowest free slot of a host and mints the identity of the slot. It copies the host's [base snapshot](#claims-copy-the-base-snapshot), writes the identity and the claim, and adds the hub peer. Then it converges the VM to the commit and starts the task branch. There is no provision, enrollment or bootstrap kind; the Node, its `app-dev` role and its `pi-server` Process are recorded at admission.

1. On the next claim, Orbit creates the [task workspace](/reference/tasks#execution-ownership) on the VM's Node. It is a normal Instance `task-<group id>`. A routed workspace gets the private Route `task-<id>.<project>.<dev-tld>`.
2. The claim starts the baseline check. Then the same claim projects the Route.


#### Start the baseline check before the Route

The baseline check needs the checkout, its `.env`, and the PHP packages of the Instance's PHP version. It does not need the Route: Pest's browser tests serve the application from their own server.

So a routed workspace on a task VM stops before its Route. After the source, workspace creation runs these steps, in about 1.5 seconds with the current base image:

1. It classifies the source and records the PHP version.
2. It writes `APP_URL`, and for a new `.env` the application key and name, as it does for every development Instance.
3. It installs the `app-dev` PHP packages of that version. On a task VM they are in the [base image](#the-base-vm-and-its-snapshot), so the step only checks them.

The workspace stays `source_resolved`, and its Route stays `pending`. The claim attaches it to the group and starts the baseline check. Then, in the same claim, it projects the Route as Instance creation does: the certificates, Caddy, PHP-FPM, the Router, and DNS, under the projection lock. The workspace becomes `active`.

The setup steps of the check can change files while the Route grants Caddy access to the checkout: `vp build` rewrites the build output, and Composer republishes assets. So the access walk ignores an entry that vanishes during the walk, and fails on any other error. It sets each directory's default ACL before its access ACLs, so a file that setup creates during or after the walk inherits the right entry: readable by Caddy in the Web root, denied elsewhere.

A Route that fails at the claim logs `The task workspace Route failed after the baseline check started; Orbit tries again before the implementer starts.` Work that uses the Route waits for it: before the first implementer or the final review starts, Orbit tries the Route again. Each failure there counts as a communication failure. After the fifth, the subtask asks for assistance with `The task workspace Route could not be finished, so no agent started.` and the error. So no agent, reviewer, preview, or pull request ever uses a workspace whose Route failed. Cancelling the group removes the workspace with its pending Route.

New claims [copy the base snapshot](#claims-copy-the-base-snapshot) of their host. The base includes both selectable PHP versions, now `8.5` and `8.4`, and Rust with Cargo, clippy, and rustfmt. The Instance's [PHP runtime check](/reference/php-runtime#development-runtime) verifies installed packages without repeating an installation.

Until the row is `ready`, the claim returns the group to `todo` with a reason, and tries again on the next tick:

| Reason | Cause |
| --- | --- |
| `Task VM: task VMs are not enabled on this Gateway.` | `task_vms.enabled` is false and the group has no task VM |
| `Task VM: no capacity.` | Every host is inactive or full. See [Place task VMs](#place-task-vms) |
| `Task VM: another placement is running.` | SQLite writer contention exhausted the placement transaction's retries. The next tick tries placement again |
| `Task VM: provisioning.` | The jobs are still running |
| `Task VM: task_vm.invalid_config: <message>` | A `task_vms` value is invalid |
| `Task VM: destroying.` | Cleanup has started |

These reasons are waits: the group asks for no assistance. A `failed` task VM is no wait. The claim returns the group to `todo` without a wait reason, and the group asks for assistance at once with `Task VM failed: tvm-<id>: <code>: <message> Cancel the group to destroy the VM.` The failure writes one assistance request in Activity when the group starts asking. Later claims skip groups with a failed VM and keep the request. The group gets no other VM: cancel it to destroy the VM.

The Node `tvm-<id>` has user `orbit`, role `app-dev`, the dev Cluster, and the reserved WireGuard address.

### Release a task VM

An ended group is `completed`, `failed`, or `cancelled`. The checkout lives on the VM's disk, so Orbit deletes no checkout in the guest. A task start's VM goes with its group through one path, the release `task_vm.slot_release` (chart `docs/cli/task/vm-release.mmd`). `task:cancel` and `task:complete` admit it only when origin already has the latest approved commit, under the shared [removal rule](/reference/tasks#cancel-a-stuck-task); `task:cancel` runs the release as the last step of its own run.

Explicit completion runs the Project's teardown steps in the guest while the VM is `ready`, copies the agent transcripts to the Gateway, then admits the release and waits for it. A parked VM is released as it is: the release deletes a stopped VM too, and nothing starts it. Release admission locks the VM row and refuses while a resume is open.

The release deletes the VM, its seed clone and its identity volume, and removes the hub peer. The Gateway then deletes the workspace Instance and the Node, unbinds the group from the workspace and ends its `removal_failure` request, clears the keys of the identity and records the slot `destroyed`. A release that fails leaves the slot `failed`; the host sweep, or the next cancel, releases it again. A refused teardown or release keeps the VM and the Instance, and the group asks for `removal_failure` assistance.

A VM of the old pool has no slot number. The release takes it the same way, by its name: it deletes the VM and its seed clone `<name>-topology-seed`, and removes its hub peer by the public key its Node records. A cancel carries this release, and the sweep releases such a VM again after a failed release. Rows the old pool left in `destroying` were recorded `destroyed`.

The release deletes by name, so it releases only a task VM name: `<host>-tvm-<n>` for slot n, under the host name it had when the slot was taken, or `tvm-<id>` for the old pool. Its seed clone is `<name>-seed` or `<name>-topology-seed`. Any other name, such as a base VM or a seed, refuses with `task_vm.release_refused`. A VM whose host is not a configured task VM host is refused with `task_vm.unknown_host`. A Node without the WireGuard public key that names its hub peer is refused with `task_vm.slot_refused`.

A `ready` VM that is absent from its Incus host keeps its row and its reviewed work. [Doctor](/cli/doctor) reports it as `node.task_vm_missing`. Cancel or complete its group to release it.

Keep a host's `settings.task_vms` until all its task VMs are `destroyed`, because the release needs the settings. Node removal refuses live task VMs and configured hosts. Clear the host setting after draining it. Removing a host also deletes its destroyed VM rows.

### Place task VMs

Placement is serialized by the Gateway's SQLite transaction. The claim checks for an existing VM, counts occupied slots, records a new VM, and queues its creation after commit. `AllocateTaskVmAction` selects the active host with the most free slots. Hosts with equal capacity use their configuration order.

Every VM that is neither `destroyed` nor `failed` takes the slots of its size, including parked VMs. When no Incus host has room, the group waits for capacity. Lowering `max_vms` removes no VM; the host receives no new VM until capacity is available.

#### Size of a task VM

At the claim, Orbit decides the size of the group's VM once. It takes the union of the `topology` fields of the group's subtasks that are not cancelled, and the Project's place in `task_vms.medium_projects` or `task_vms.large_projects`. The row stores the size in `size`, and it never changes while the VM exists.

| Size | When | VM | Slots |
| --- | --- | --- | --- |
| `normal` | No subtask declares a topology and the Project is in neither size list | The host's `cpus`, `memory`, and `disk` | 1 |
| `medium` | The topology has one test node, or no subtask declares a topology and the Project is in `task_vms.medium_projects` | 4 vCPU, 8 GiB of memory, and a 40 GiB disk | 2 |
| `large` | The topology has two test nodes, or no subtask declares a topology and the Project is in `task_vms.large_projects` | 6 vCPU, 12 GiB of memory, and a 60 GiB disk | 3 |

For groups without a declared topology, `large_projects` takes precedence over `medium_projects`: a Project in both lists gets a large VM. These lists do not change the size of a group with a declared topology.

A test node is `app-dev`, `app-prod`, or `app-prod-2`, whatever roles its entry adds. The [test Gateway and the operator](/reference/incus-topologies#declared-nodes) run in every topology and do not count. Each size defines how many test nodes it holds, one in a medium VM and two in a large VM, and the group gets the smallest size that holds its topology. The largest size sets the most test nodes a group may declare.

The sizes are caps, not reservations. Incus does not pin the vCPUs, and the guest takes host memory only when it first uses it. Medium and large VMs have free page reporting on their memory balloon, so the host gets back the memory that the guest frees, but not the guest's page cache. Plan for a VM that runs a topology to use all of its memory on the host.

A subtask whose topology needs a larger VM than its group got waits before its first turn, with the reason `Task VM: this subtask's topology needs a larger task VM than the group got, and Orbit does not resize it.` This happens when a subtask is added or changed after the claim. Orbit never runs it without its nodes, and never moves the group to another VM. To go on, cancel that subtask, or cancel the group and create it again with the topology declared. See [Orbit Project](#orbit-project).

### Image permissions and verification

Image installer services use `UMask=0022`, matching recipe execution on Nodes. Tool directories and package state must remain readable by the guest. Rust and Cargo must also report their pinned versions. Guest wrappers use the compiled agent contract to identify mutations: smoke and recipe verification reads receive only their declared inputs; mutations retain keyed effect receipts. Builder, smoke and seed boot observations stay pending until their own agent installation child succeeds. Only then does the Gateway probe cloud-init; real probe failures remain errors.

Verification runs Node, pnpm, Bun, and Vite+ launchers only as `orbit` and compares their versions with inspection. Other guest-facing binaries must report the same version as root when run as `orbit`. Launcher caches remain owned by `orbit`.

Existing base snapshots and VMs retain their permissions. After a fix, refresh the host with `task-vms:refresh-image <host>`, then recreate affected VMs.

### Clone and set up every workspace

Every new task workspace starts with an empty checkout on the VM's root disk at `<apps directory>/<project slug>/workspace`. A step on Node 209 clones the Project repository with full history and sends it to the VM as a Git bundle, so the agent reads code and history offline. The clone credential is minted for that step when it runs and never reaches the VM ([GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)). The workspace checks out its task branch, then the baseline check runs all [Project setup steps](/reference/instance-setup), every time. Each claim runs Project setup.

The `@orbit/ci-caches` setup step imports the selected [CI cache ref](/reference/instance-setup#import-ci-main-caches). Packages download into private caches; a missing or invalid CI ref leaves checks cold. Neither miss queues a workspace image build. The fixed checkout path preserves cache paths across claims without sharing a writable checkout.

A task VM has no default checkout and no dependency trees in its recipe image, so `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` are empty. The Project's `seed-dependencies` step therefore skips its reflink copy by design.

For private dependencies, the workspace step writes the Composer and npm configuration of the task account before the Project setup steps run. The configuration holds a GitHub App installation token with only `contents: read`, which reads the private packages in the repositories that the installation covers. The token is minted for these steps and expires after one hour.

The check stops each setup step's process group when its shell exits, times out, or is cancelled. Setup steps must finish their work before returning. Long-lived services belong in [Processes](/reference/processes-and-schedules), not setup background jobs. See the [baseline check](/reference/tasks#baseline-check) for output cleanup and timeout behavior.

The configuration is two files with mode 0600: `~/.config/composer/auth.json` and `~/.npmrc`. Composer gets the token as `http-basic` credentials for `github.com` (user `x-access-token`), not as `github-oauth`. Composer refuses an OAuth token with a character outside letters, digits, dots and underscores, and the installation token has more.

The token reads every repository the App installation covers, because a private package can live in any of them. A setup command is repository code and can read the same repositories while the files exist. The Gateway revokes the token itself if it cannot store one of the three vault copies.

A later step removes both files after the last setup step, on success and on failure. A failed removal fails the provision. The same step revokes the token, so a setup command that echoes it leaves a token that is already revoked. The setup output is redacted for GitHub tokens, dots and dashes included, before it is kept. A cancelled or timed-out run keeps the files until the token expires or `task:cancel` removes the VM. The Project check and deliverable commands run after that and find no token.

The provision result names what became of the token in `dependency_token`. `revoked` means GitHub answered 204. `none` means GitHub answered 401: no token was minted, as for a public repository that the App does not cover, or it had already expired or been revoked. `unrevoked` carries a `reason` (GitHub answered another status, the request did not complete, or the revoke step could not run), and the token may stay valid for up to an hour.

An `unrevoked` token does not fail the provision: the files are already gone from the VM, and failing would not shorten the hour. The result says why instead. A provision that runs no setup step writes, removes and revokes nothing, and reports `none`.

If the App cannot supply a token, both files hold an empty configuration and setup continues without private access.

An interrupted claim resumes only its own checkout after the existing source identity checks.

### Git on a task VM

No GitHub token reaches a task VM. The agent in a task VM runs as `orbit` with passwordless sudo, so it could read a token from any process in the VM. So the Gateway runs every Git command that talks to GitHub itself, in its own mirror of the Project. It moves Git objects to and from the VM over the Node's SSH connection, as a pack on standard input or standard output.

#### The mirror

Each Project whose task workspaces or CI-cache setup use the mirror has one bare repository on the Gateway: `storage/app/task-vm-git/<project id>.git`, in the [shared Gateway storage](/reference/gateway-recovery#release-layout). The Gateway fetches into it from the Project repository URL with the Project's [repository access](/reference/github-app#how-orbit-reads-a-repository). The token is in the environment of one `git` process on the Gateway, as for any other read. The required source fetch asks only for the branches that the operation names, with `--no-tags`, into `refs/heads/<branch>`.

A separate optional fetch refreshes `refs/orbit/ci-cache/*` with its own timeout and prunes only that cache namespace. A cache-fetch failure clears the mirror's old cache refs and leaves the required source fetch intact.

The mirror always uses HTTPS, because the token travels in an HTTPS header. A `git@github.com:` or `ssh://git@github.com/` URL becomes `https://github.com/<owner>/<name>.git`. Any other SSH URL fails with `Task VM Git reaches a repository over HTTPS only`.

A lock lets one operation at a time change the mirror of a Project. The lock covers only work on the Gateway and GitHub: a fetch, the packing of a send, and the move of a verified pack. It never covers a transfer to or from a VM. It lasts 40 minutes, longer than a fetch can run, and another operation waits for it at most 300 seconds. Git in the mirror never collects garbage on its own (`gc.auto=0`). After each publish, the publish job runs `git gc --auto` outside the lock.

#### Send branches to the VM

1. The Gateway fetches the operation's branches into the mirror. Only `task-<id>` may be missing on GitHub.
2. It calls `task-vm.read-git-refs` through the agent runner as the checkout account. The Gateway keeps IDs that the mirror holds as commits.
3. It packs the objects of the branches that are not in the history of those commits.
4. It calls `task-vm.import-git-pack` through the agent runner and workspace executor. The command sets `refs/remotes/origin/<branch>` to the mirror's commit. A missing `task-<id>` loses its ref.
5. When the default branch was sent, the VM points `refs/remotes/origin/HEAD` at it, as a clone does. The [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) setup step reads the default branch there.
6. In a separate optional transfer, it sends only the cache ref for the selected code SHA. Failure of this transfer does not undo source delivery.

The imported pack follows the JSON envelope as bounded binary input, without a token, staging call, or script. The command validates the pack and approved ref names, then updates the refs in one Git transaction.

The ref command returns at most 1,000 distinct ref object IDs, the worktree's recorded cache baseline, its branch, and its `HEAD`.

[New task workspace selection](/reference/implementation-loop#ci-cache-refs) separates the starting code commit from the cache baseline. A main checkout uses its selected main commit. A task branch keeps its recorded main baseline in the worktree-private `refs/worktree/orbit/ci-baseline`. Another branch with no recorded baseline whose `HEAD` is a main commit, such as a fresh agent branch, uses that `HEAD` commit, not a newer main commit. [CI cache import](/reference/instance-setup#import-ci-main-caches) validates the ref and payload before using any cache files.

| Operation | Branches |
| --- | --- |
| Workspace creation | The default branch and `task-<id>` |
| [Fetch before a turn](/reference/tasks#fetch-before-a-turn) | The default branch, `task-<id>`, and a pull request base that is not the default branch |
| Base branch fetch | The pull request base |

A new workspace starts from `git init`, with the Project repository URL as `origin`, and gets its branches this way. The log messages about a fresh clone mean this new repository. The VM's refs only shrink the pack. A VM that names commits it does not have gets a pack it cannot complete, and its own Git operation fails.

#### Publish from the VM

Swamp publishes the [checked task candidate](/reference/tasks#publish-an-external-group) from Node 209, which receives the commit from the task VM as a bundle. Handoff CI uses the same native Git transport for `task-{group}-handoff`. The publish step asks the Gateway for a repository installation token when it starts, and the Gateway writes it to the vault. The Gateway projects the result; it runs no push job or mirror import. The VM never receives the token.

The native action uses an empty temporary bare Git repository with the workspace object store as an alternate. Credentials reach only Git in that trusted directory. The action ignores repository hooks and user/system Git configuration, permits HTTPS only and refuses redirects. It pushes the exact admitted commit without force and reads the remote tip before repeating a mutation. No repository command receives the credential. Native locking, cancellation and replay follow the [Tasks contract](/reference/tasks#publish-an-external-group).

#### What the Gateway trusts

From the VM, the Gateway takes only commit IDs and pack data. A commit ID must be 40 or 64 lowercase hex characters. Git in the mirror never reads a file from the VM. It runs with these settings: `core.hooksPath=/dev/null`, `core.fsmonitor=false`, an empty `credential.helper`, `http.followRedirects=false`, `protocol.allow=never`, and `protocol.https.allow=always`. A pack from the VM goes through `git index-pack --strict`, which runs the object checks that `transfer.fsckObjects` runs for a fetch. Fetches from GitHub skip them, because an old commit in a real repository can fail them.

The remaining Gateway source adapters refuse to send a command that carries a source Git credential to a task VM. Native Swamp publication consumes its repository token through the shared private reader on the admitted worker. The separate Composer setup token described above is a narrow exception for dependency downloads.

A source command fails with `task_vm.git_credential_refused` before SSH starts. An `incus` command on a task VM host refuses one too. So an Instance or Project operation that would fetch on a task VM with a token fails there and passes no token. Examples are `instance:destroy` of a task VM workspace and `instance:clone` from one. `project:update` with a new repository URL sends no token: it reads the proposed repository on the Gateway and only sets `origin` on the VM. A task VM workspace goes with its group; see [Release a task VM](#release-a-task-vm).

The guard knows a credential by the mark that `GitReadScript` puts on its input. A test fails when other code builds the credential header. It also fails when a new file sends remote commands with GitHub access in reach, so each such path gets a review.

The checkout keeps the Project repository URL as `origin`. After setup ends, the agent can run Git there, but its environment holds no setup credential. So it cannot fetch from or push to a private repository.

### Converge a running task VM

The common converge brings a task VM to the pins of the release. A [base snapshot refresh](#refresh-the-base-snapshot) runs it on the base VM, and every task start runs it on the new VM, in one Ansible run with the host part (`swamp/ansible/task-vm-start.yml`). It has no kind or command of its own. Swamp runs the phases of the task VM role playbook (`swamp/ansible/task-vm-role.yml`) from Node 209 over SSH, so a base and a started VM end in the same state. These are the `software`, `tools` and `check` phases.

The Gateway puts these in the intent:

- The `recipe.json` of the release and the Vite+ lock.
- The VM's SSH target with its recorded host key.
- The Pi server digest and its place on the Gateway host.

No task file names a version.

The `software` phase installs Ubuntu packages and file packages at their pins. It also installs `python3-venv` and `jq` from the same snapshot, which the shape gate needs. They come from the pinned Ubuntu snapshot, through their own apt configuration, with the artifact mirror first. The VM's apt sources stay as they are. Node, pnpm, Bun, Vite+, Rust, the Swamp binary and the Pi server follow. A tool that already shows its pinned version is left alone, so a VM at its pins reports no change.

The `tools` phase installs the shape gate linters. The nested fleet is Incus system containers that take the checkout as a disk device, so the VM serves nothing to them.

- The intent names the nested subnet and the interface `nested` (`TaskVmNestedFleet`), so the VM leads the nested network of that subnet.
- UFW then allows DHCP, DNS and the package cache from the nested bridge, and routes it out.
- The linters build into `/opt/orbit-shape-gate`, named by the digest of the pins in `swamp/ansible/shape-gate-requirements.txt` and `swamp/ansible/requirements.txt`.
- `bin/swamp-shape-gate` finds the folder by that name. The handoff check then runs offline.

The `check` phase is the one end check. It asserts every package at its pin, every runtime and tool at its version, and the package cache active.

The `check` phase also asserts that `orbit` has UID and GID 1000.

A failed phase leaves finished work in place. A rerun skips each phase whose digest matches.

### Park and resume

Swamp requests review parking through the keyed `tasks.park` operation. The Gateway checks that the published group has no open work, assistance or workspace owner and records an immutable park intent. One native Swamp root stops and observes the admitted VM on its exact Incus host. The result projection records `waiting_for_review` only after stopped power is confirmed.

It does not run automatic idle parking or retry a failed park. Swamp owns retry policy and execution-slot accounting. Parking has one dispatch path: the native `tasks.park` root replaces its PHP stop executor and the unused internal `task_vm.park` parent and `task_vm.confirm_stopped` child. The same root owns soft-stop, hard-stop and observation; it starts no child Operations.

The row enters `parked` with `stop_confirmed_at` only after the native root confirms settled, stopped power. Admission and cancellation leave a running VM `ready`. A failed stop clears the proof and retains the error. A resource observation job refreshes proof every minute for parked VMs in every group status, including assistance and ended groups. Running, missing, unsettled or unreadable power clears the proof. Observation never starts or stops a VM.

A requested workspace operation admits `task_vm.resume`. It clears stop proof and starts one native run. On an Incus host the run starts the VM, and a VM that already runs counts as started. Incus's own agent wait follows, bounded to 120 seconds. A `ready` VM that a workspace operation found stopped also gets 209's authorized key, its TCP 22 rule and `sshd` back, through `incus exec` on the host.

The run then reads `orbit-agent` and the Pi capabilities over the guest's recorded host key pin, and a native `assert` checks both. A guest without a recorded host key is refused at admission. The run prints no result: a succeeded run records `ready`, and a failed run projects `task_vm.resume_failed`, which fails a parked VM with its error evidence.

Parking retains the disk, workspace and sessions. It releases CPU and memory. It retains the provider's allocated VM count; Swamp's maintenance execution slots are separate.

### Sweep task VM hosts

The Gateway scheduler queues `SweepTaskVmHosts` every thirty minutes. It runs while task VMs are enabled.

For each active configured Incus host, the job releases the task VMs that a failed start or a failed release left, old-pool VMs included: a row with no group whose release failed, or whose Operation ended without its projection. Before each release it locks the row and checks again; a refresh of the host that opened after the selection leaves the row alone, because its group-less VM is still starting. The release is the run of `task_vm.slot_release` ([release chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/vm-release.mmd)). A task start or a refresh proof that still uses the VM keeps it.

An Incus host has no sweep of its own: no `task_vm.sweep_host` Operation runs for it, and the job reads no instance or volume of the host. A Node named `tvm-<number>` that no task VM row owns gets one log warning per run.

A VM that dies on an Incus host stays in its row until its group ends. [Doctor](/cli/doctor) reports it as `node.task_vm_missing`; the row is not failed by a sweep. Cancel or complete the group of a missing VM to release it, as for [a release](#release-a-task-vm).

The sweep workflow has a 60-second worker queue timeout. Its run budget is 600 seconds. A provider failure never supplies a missing-VM observation and projects `task_vm.host_command_failed`.

[Doctor](/cli/doctor) reports the VM findings in the `node` family of the host, without changes. The sweep deletes orphaned volumes. Doctor's codes are `node.task_vm_orphaned` for an orphaned VM and `node.task_vm_missing` for a missing one. Doctor reports a missing VM on its first miss. A host whose project does not exist or cannot be listed reports `node.task_vm_inspection_failed`. Doctor checks a host only while it has `settings.task_vms` and is reachable over SSH. The fleet rollout ignores these codes.

Doctor does not inspect the Node of a `parked` task VM, because the VM is stopped on purpose. Its `node` family reports one informational `node.task_vm_parked`, and the other families check nothing. So no problem is filed for a parked VM.

### Report strays

Doctor reports what Orbit does not own on a task VM host, and the records that should be gone. It never changes any of it: the operator decides. It reports one finding per stray in the host's `node` family. Builders and volumes that the image builds left behind are strays now, and the operator removes them.

A **stray instance** is an Incus instance in any project of the host that is none of these:

- a task VM `tvm-<number>` in the host's project, which the [sweep](#sweep-task-vm-hosts) checks against its row;
- a retained base image builder `tvm-image-<build id>` or smoke VM in the host's project (see [the sweep rules](#sweep-task-vm-hosts));
- an entry of the host's `operator_owned` list.

A **stray volume** is an Incus custom volume in any project and pool of the host that is none of these:

- a task VM's clone of the topology seed, `tvm-<number>-topology-seed`, in the host's project and pool;
- the [topology seed](#the-base-vm-and-its-snapshot) `orbit-topology-seed-<12 hex>` of an image that the host's project keeps, in its project and pool;
- a seed under construction of such an image: `-new`;
- an entry of the host's `operator_owned` list.

The audit lists every project with `incus query /1.0/instances?all-projects=true`, and the custom volumes of every storage pool with `incus query /1.0/storage-pools/<pool>/volumes/custom?all-projects=true`. Doctor reports every pool. Images, networks, and profiles are not part of it. A host whose list fails reports `node.task_vm_inspection_failed`.

A **stray record** is a `ready` or `parked` task VM row on the host with no group, or whose group has ended, for more than one hour. The hour counts from the later change of the row and its group. Normally the [release](#release-a-task-vm) of the group removes such a VM within minutes, so a stray record means that release is stuck.

A **stray Node** is a Node named `tvm-<number>` that has no task VM row that is not `destroyed`, 15 minutes after the Node was created. Enrollment creates the Node and links it to its row in the same job, so 15 minutes covers a slow enrollment. Doctor reports it in the Node's own `node` family, also while the Node is unreachable. The sweep job logs it while task VMs are enabled.

| Code | Kind | Meaning |
| --- | --- | --- |
| `node.task_vm_stray_instance` | drift | A stray instance, observed as `<project>/<name>` |
| `node.task_vm_stray_volume` | drift | A stray volume, observed as `<project>/<pool>/<name>` |
| `node.task_vm_stray_record` | drift | A stray record, observed as the task VM name and its state |
| `node.task_vm_stray_node` | drift | A stray Node |

To clear a finding, delete the stray after you check it, or add an operator-owned guest to `operator_owned`. For a stray record, cancel or complete its group, or destroy the VM. For a stray Node, remove the Node with `orbit node:remove`.

### States

The `TaskVmState` enum has six cases.

| State | Meaning |
| --- | --- |
| `provisioning` | Orbit is creating, enrolling, or preparing the VM |
| `ready` | Pi runs, and Orbit can create the workspace |
| `parked` | Orbit stopped the VM, or is starting it again. The disk stays. See [Park and resume](#park-and-resume) |
| `failed` | A job failed. The row keeps `error_code` and `error_message`. The group shows the error as its reason and asks for assistance |
| `destroying` | Cleanup has started |
| `destroyed` | The VM and the Node are gone. The row stays for audit |

Orbit derives progress inside `provisioning` from facts: the VM exists, the row has a Node, the Node is active, and the Pi Process exists. A group has at most one task VM that is not `destroyed`. `failed` is final for the row. Cancel the group to destroy the VM.

### Jobs

The Gateway jobs `SweepTaskVmHosts` and `RefreshTaskVmImages` validate and record intent. Swamp executes their effects. Each admission job has one attempt and a one-minute timeout.

Provisioning, enrollment, runtime preparation, resume, destruction, parking and internal removal keep their existing synchronous implementations and tests. Their replacement Operations remain inert until their parents declare the required `node.add`, `node.remove` and Process children and those owning admissions are registered. Merely registering a Node or Process kind does not activate an incomplete parent. Admission refuses with `operation.kind_unavailable` before it records intent or changes the VM.

Runtime admission records the fixed Pi Process definition and its ID without installing or starting it. Swamp requests the owning `process.create` child with only `{process_id}`, then waits for its proven completion before checking runtime readiness. An existing Process with another definition refuses admission. Runtime admission stays guarded until the owning Node enrollment kind is installed; retained runtime jobs remain synchronous until their cutover.

Enrollment preparation validates the booted VM and records its reserved Node ID with the exact internal `ProvisionNodeData` input. Swamp requests the direct `node.add` child from that stored descriptor; it cannot select a Node or expand the input. An already active VM Node needs only the recorded preparation and runtime proofs. Destruction proves provider deletion and Route removal, clears workspace records, then requests the exact `node.remove` child before final VM cleanup. The recorded Node identity remains available after deletion for receipt verification. These parent plans do not switch retained callers; activation waits for the owning Gateway and Swamp releases.

Before the later lifecycle cutover, destruction must also gain a cancel guard after the proven provider-delete boundary so cancellation cannot abandon record cleanup.

Every registered effect fixes its Node, command and input. The Gateway resolves declared secret references after authorization; tokens, private bootstrap keys and cloud-init credentials never reach Swamp or receipts. Reads remain Services-only Gateway APIs. Successful completion requires proven command or child receipts. Error codes use lowercase letters, digits, dots, underscores and hyphens.

A parent yields its worker lease while its stamped child Operations run. Native effects carry the shared target receipt metadata, so recovery reads the original sanitized result before replay. Gateway projections accept only exact owning child proof; they never admit another Operation and wait for it.

Older templates stay while pinned by allocations; pruning keeps the newest two verified templates.

Host sweeps use independent Operations, so one failed host does not block another host. The Gateway reads ownership and reports strays. Swamp deletes only the admitted owned orphans. Active and recent failed builders remain protected.

Admission captures live VM names and the highest allocated VM and build IDs before native inventory. New allocations remain outside that cleanup scope. Native cleanup removes VM instances before selecting their orphan topology volumes, and verifies VM absence before deleting a volume. Cleanup and resume compete for the same build fence: cleanup blocks resume, and a successful resume prevents a stale sweep from deleting the builder or smoke VM. Two distinct sweep Operations must miss a VM before its row fails; a replay of one observation cannot fail it. Doctor retains its read-only path.

### Incus provider

Swamp runs task VM effects through recorded Operations. Each effect fixes its host, guest identity and command input before execution. The Gateway admits the effect and requires a matching successful command receipt before recording completion. A retry uses the same Operation and effect key. A lost reply does not authorize a second VM or a different target.

`IncusTaskVmProvider` exposes observations only: the power of a VM. A task start copies the base snapshot with the Ansible phases of `task-vm-host.yml`; no provider class creates, starts or deletes a VM.

A [medium VM](#size-of-a-task-vm) gets `limits.cpu=4`, `limits.memory=8GiB`, and a 40 GiB root disk instead, and a large VM `limits.cpu=6`, `limits.memory=12GiB`, and a 60 GiB root disk. Before each base VM start, the refresh converges `raw.qemu.conf`. Its `[boot-opts]` keys `menu = "on"` and `splash-time = "0"` tell OVMF to skip its 3 s boot menu wait. The snapshot and every task copy inherit that config. This applies to existing bases too; setting the key only at creation leaves an existing base with the wait. GRUB already has a zero timeout in the stock image.

A medium or large VM holds test Nodes, so it boots with a clone of the [topology seed](#the-base-vm-and-its-snapshot) of its `image_fingerprint`:

1. When the volume `tvm-<id>-topology-seed` is missing, create copies the seed `orbit-topology-seed-<12 hex>` to that name. On ZFS the copy is a clone. It uses only what the VM writes.
2. A retried create finds the clone and copies nothing.
3. The launch adds the clone as the disk device `topology-seed` in the `devices` of the instance YAML on stdin, beside its `config`.

When the host has no seed for the VM's base image, create fails with `task_vm.topology_seed_missing`, and nothing launches. A normal VM gets no clone. The [claim gate](#claims-copy-the-base-snapshot) needs a recorded base snapshot, which carries its seed, so a claimed VM always finds one.

When the launch fails and the project has no `orbit-task-base` alias, create fails with `task_vm.base_image_missing`. [Refresh the host](#refresh-the-base-snapshot) with `task-vms:refresh-image <host>`. Orbit never falls back to the stock image: a VM from it has no `openssh-server`, so it cannot enroll.

Create, start, stop, and delete are idempotent by name. A running VM counts as created and started, a stopped or absent VM counts as stopped, and an absent VM counts as deleted. Stop waits up to 120 seconds for the guest to shut down, and then stops the VM with force.

Create starts a VM that exists but is stopped, and fails with the `incus start` error when it cannot. A launch that reports an error counts only when the VM runs afterwards. Otherwise create fails with the launch error. A delete that reports an error counts when the VM is gone. The claim checks `task_vms.enabled` before it allocates a VM. The jobs of an existing row use the provider whatever `enabled` says, so Orbit still destroys task VMs after you turn them off.

### Incus LTS packages and upgrades

Incus uses Zabbly's signed `lts-7.0` repository for Ubuntu 26.04. Each artifact records its exact Debian `version`, because architecture build timestamps differ. The tool `version` names their shared 7.0.x point release. The recipe pins `incus`, `incus-base`, and `incus-client` for amd64. Zabbly bundles QEMU, qemu-img, virtiofsd, firmware, and the agent. Hosts remove the obsolete separate Ubuntu `incus-agent` package. Rebuilt images start from stock; their stock agent unit runs `/run/incus_agent/incus-agent`, supplied by the outer host. The installer does not replace that unit.

`bin/recipe-artifacts bump` authenticates Sury and Zabbly indexes and updates their complete package sets together. It verifies the pinned signing key digests, `InRelease` signatures, package index hashes, and Incus Debian file digests. Only changed Incus artifacts are downloaded; merge verification reads signed indexes without downloading Debian files. The existing [artifact mirror](#the-recipe-artifact-mirror) retains the same content-addressed Debian files used by Git and Sury. Managed machines have no rolling Zabbly APT source. Review the [7.0 release notes](https://discuss.linuxcontainers.org/t/incus-7-0-lts-has-been-released/26641) and [7.0.1 fixes](https://discuss.linuxcontainers.org/t/incus-7-0-1-lts-has-been-released/26942) for new requirements. The 7.0 line is supported until June 2031.

The bump tests own fixed Incus versions and fake indexes, with distinct builds per architecture. Artifact and snapshot updates therefore change no expected test result. Tests that decode or send the recipe compare with the document under test.

The `ci-runner` role selects the complete Incus package set. Configured task VM hosts also add that set to their role-selected recipe tools. Other roles do not select Incus merely because it is installed; inventory those hosts separately before planning an upgrade.

Incus 7.0 requires Linux 6.12, cgroup v2, QEMU 8.2, nftables 1.0, dnsmasq 2.90, and ZFS 2.1 when using ZFS. Ubuntu 26.04 uses kernel 7.0 and cgroup v2. Incus 6.0.5 already uses nftables; 7.0 removes the alternative xtables backend. Orbit's bridge and ACL setup continues to use nftables. The harness's separate iptables rules use Ubuntu's nft backend; its isolation hook precedes either filter chain. Orbit uses neither storage buckets nor OVN.

Orbit uses complete CLI command names and explicit archive destinations, compatible with 7.0's stricter argument parsing. Its regular image archives do not use the changed file-copy behavior. Existing Ubuntu VMs retain their NVRAM filenames. On hosts only, the installer adds firmware aliases in Zabbly's bundled firmware directory. Incus 7.0.1 searches `INCUS_EDK2_PATH` then `/usr/share/OVMF`, while its AppArmor profile permits only `INCUS_EDK2_PATH` when set ([firmware selection](https://github.com/lxc/incus/blob/v7.0.1/internal/server/device/edk2/driver_edk2.go), [AppArmor](https://github.com/lxc/incus/blob/v7.0.1/internal/server/apparmor/instance.go)). Remove the aliases after an upstream release fixes selection and confinement and a VM created on Ubuntu Incus boots without them. Rebuilt images and new VMs do not need these aliases.

1. Drain Shark with the admission procedure below. Inventory every project with `incus list --all-projects`.
2. Finish live task VM rows through normal lifecycle operations. Save and disable instance autostart with the drain script below, then stop all remaining instances. Take the stopped-state backup below.
4. Verify client and server 7.0.1. Compare project, VM, ZFS, custom volume, and seed inventories with the backup.
5. Boot an old-image guest and a new disposable guest. Check agent exec, stop/start, bridge isolation, and ACL egress.
6. Run `php artisan task-vms:refresh-image shark`. Require the proof and the promotion of the snapshot before the topology gate.
7. Run one complete topology task group explicitly on Shark. Require seed adoption, Node copies, enrollment, checks, teardown, and clone removal.

The Shark topology group is the **go/no-go point**. The full host 7.0.1 → task VM 7.0.1 → topology Node chain remains unverified until it passes. Failure stops rollout and invokes the rehearsed rollback below before normal admission resumes.

The drain includes every `orbit-ci` container on the host, task VMs, builders, and parked task VMs. Inventory names at rollout time; do not rely on a fixed runner list. Preserve parked guest data and configurations before finishing their rows; retain an offline representative old-image guest for compatibility checks. Do not delete unrelated workloads. Beast's drain also includes every operator-owned VM in every project, including any remaining `adr0223-memory-20261019` VMs. Never clear Beast's task VM settings for this upgrade.

The existing admission control is global: set `ORBIT_TASK_VMS_ENABLED=false` (`task_vms.enabled`) on the Gateway and reload its configuration and workers. Keep it false through convergence and rollback; there is no per-host maintenance switch. New claims are blocked while existing rows can finish and be destroyed. Wait for all rows on this host to end, including parked rows, and for image builds to finish. Stop CI dispatch before shutting down runner containers.

Save and disable instance autostart across every project before stopping guests; daemon restarts during package installation must leave them stopped. Keep these per-instance overrides through upgrade verification or rollback verification. Do not edit profiles: Doctor compares the runner profile exactly. Do not start a manual build, instance, or resume during this window.

Save this script as `/root/incus-upgrade-autostart.py` on the maintenance host. Run `python3 /root/incus-upgrade-autostart.py hold /root/rollback/autostart.json` as root while Incus 6 is still running, before stopping guests or taking the backup. It records each affected instance's original local value, including an absent value inherited from a profile, before any mutation. A failed hold leaves the manifest for inspection; do not continue until every recorded instance has `boot.autostart=false`. Keep the manifest outside the Incus storage backup and retain it through rollback.

```python
# incus-upgrade-autostart
import json
import os
from pathlib import Path
import subprocess
import sys

action, filename, *selection = sys.argv[1:]
manifest = Path(filename)
if action == "hold":
    if selection:
        raise SystemExit("hold does not accept an instance selection")
    instances = json.loads(subprocess.check_output(
        ["incus", "list", "--all-projects", "--format=json"], text=True))
    saved = [
        {"project": item["project"], "name": item["name"],
         "value": item["config"].get("boot.autostart")}
        for item in instances
    ]
    manifest.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with manifest.open("x") as stream:
        json.dump(saved, stream)
        stream.flush()
        os.fsync(stream.fileno())
    for item in saved:
        subprocess.check_call(["incus", "--project", item["project"], "config", "set",
                               item["name"], "boot.autostart=false"])
elif action == "restore":
    saved = json.loads(manifest.read_text())
    if selection:
        saved = [item for item in saved if f'{item["project"]}/{item["name"]}' in selection]
        if len(saved) != len(set(selection)):
            raise SystemExit("selection is missing from the manifest")
    instances = json.loads(subprocess.check_output(
        ["incus", "list", "--all-projects", "--format=json"], text=True))
    local = {(item["project"], item["name"]): item["config"] for item in instances}
    for item in saved:
        config = local.get((item["project"], item["name"]))
        if config is None:
            continue
        command = ["incus", "--project", item["project"], "config"]
        if item["value"] is None:
            if "boot.autostart" not in config:
                continue
            command += ["unset", item["name"], "boot.autostart"]
        else:
            command += ["set", item["name"], "boot.autostart=" + item["value"]]
        subprocess.check_call(command)
else:
    raise SystemExit("expected hold or restore")
```

Restore skips recorded instances absent from the current inventory. For an inherited value, it unsets only an existing local override, so a full restore can follow a single-runner restore on Incus 7.

If an instance is created after `hold`, such as `pid-sandbox`, add it to the saved JSON array before changing its autostart. Read its local `config` from the YAML returned by `incus --project PROJECT config show NAME`; do not use `--expanded`. Append one object with its `project`, `name`, and original `value`: use `null` when local `config` has no `boot.autostart`, or its exact string value otherwise. For an inherited value in the default project, the object is `{"project": "default", "name": "pid-sandbox", "value": null}`.

Preserve every existing entry, save the manifest durably, and check that the new project/name pair occurs exactly once. Then run `incus --project PROJECT config set NAME boot.autostart=false` and stop the instance before any daemon restart. Do not rerun `hold`: it refuses to overwrite the original values. The later restore includes the added instance.

After host verification, keep CI dispatch paused and manually start one inventoried runner with `incus --project orbit-ci start NAME`. Verify it before restoring its value with `python3 /root/incus-upgrade-autostart.py restore /root/rollback/autostart.json orbit-ci/NAME`. After the host's topology and runner gates pass, restore the remaining values with `python3 /root/incus-upgrade-autostart.py restore /root/rollback/autostart.json`, then resume CI dispatch and admission. Restoring autostart does not start stopped instances; start the remaining guests deliberately. Use the same one-runner verification and restore sequence after rollback. The stopped database backup already contains the disabled overrides, so restoring it keeps guests stopped when Incus 6 starts.

The installer repeats the active-instance preflight immediately before package installation, so an instance started since the first preflight refuses the upgrade. Inventory with `incus project list`, `incus list --all-projects --format=json`, `incus project show orbit-tasks`, `incus storage show POOL`, `incus storage volume list POOL --project orbit-tasks`, and `zfs list -t all -r DATASET`. Compare the before and after records.

For an offline backup, first stop every instance and confirm no QEMU process uses its storage. Run as root, setting `dataset` to the host's Incus dataset. The archive excludes mounted storage; the recursive ZFS snapshot preserves it from the same stopped state.

```bash
set -euo pipefail
backup=/root/rollback
dataset=i7pool/incus
install -d -m 0700 "$backup/debs"
# Install dpkg-repack before the maintenance window if the old debs are unavailable.
for package in incus incus-base incus-client incus-agent; do
  if ! dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -qx 'install ok installed'; then
    if [ "$package" = incus-agent ]; then continue; fi
    echo "Required rollback package is not installed: $package" >&2; exit 1
  fi
  version=$(dpkg-query -W -f='${Version}' "$package")
  (cd "$backup/debs" && apt-get download "$package=$version") ||
    (cd "$backup/debs" && dpkg-repack "$package")
done
units=(incus-startup.service incus-user.socket incus.socket incus-user.service incus.service)
systemctl stop "${units[@]}"
systemctl mask "${units[@]}"
if pgrep -x incusd || pgrep -f '(^|/)qemu-system-(x86_64|aarch64)( |$)'; then
  echo "Incus or QEMU is still running" >&2; exit 1
fi
paths=(var/lib/incus)
for path in etc/default/incus etc/incus; do
  if [ -e "/$path" ]; then paths+=("$path"); fi
done
for unit in "${units[@]}"; do
  path="etc/systemd/system/$unit.d"
  if [ -d "/$path" ]; then paths+=("$path"); fi
done
tar --one-file-system --acls --xattrs -cpf "$backup/incus6.tar" -C / "${paths[@]}"
zfs snapshot -r "$dataset@pre7"
```

Unmask and start the daemon and sockets before explicit convergence, with all guests still stopped.

Rollback must keep the migrated database inaccessible to Incus 6. Pause admissions, builders, CI dispatch, and resumes; stop all guests. Check which later writes the storage rollback would lose. The rollback sequence follows an earlier rehearsal as root in a disposable Ubuntu 26.04 VM. The backup block above was added later and has not been rehearsed verbatim. Set `backup` and `dataset` to the stopped-state backup and the host's Incus ZFS dataset. Never use these example paths on a live host.

```bash
set -euo pipefail
backup=/root/rollback
dataset=i7pool/incus
units=(incus-startup.service incus-user.socket incus.socket incus-user.service incus.service)
systemctl stop "${units[@]}"
systemctl mask "${units[@]}"
zfs list -H -t snapshot -o name -r "$dataset" > "$backup/rollback-snapshots.all"
sed -n '/@pre7$/p' "$backup/rollback-snapshots.all" > "$backup/rollback-snapshots"
grep -Fx "$dataset@pre7" "$backup/rollback-snapshots"
if pgrep -x incusd; then echo "Incus is still running" >&2; exit 1; fi

# Remove only the aliases this installer created, with their expected targets.
for pair in \
  OVMF_CODE_4M.ms.fd:OVMF_CODE.4MB.fd \
  OVMF_CODE_4M.secboot.fd:OVMF_CODE.4MB.fd \
  OVMF_CODE_4M.fd:OVMF_CODE.4MB.fd \
  OVMF_VARS_4M.ms.fd:OVMF_VARS.4MB.ms.fd \
  OVMF_VARS_4M.fd:OVMF_VARS.4MB.fd \
  OVMF_VARS_4M.secboot.fd:OVMF_VARS.4MB.ms.fd; do
  link=/opt/incus/share/qemu/${pair%%:*}
  if [ -L "$link" ]; then
    test "$(readlink "$link")" = "${pair#*:}"
    rm -- "$link"
  fi
done
rm -f /etc/apt/preferences.d/orbit-incus /etc/apt/orbit-incus.json
DEBIAN_FRONTEND=noninteractive apt-get install --yes --allow-downgrades \
  --no-install-recommends "$backup"/debs/*.deb

# Package hooks and either socket must remain unable to start Incus 6.
if systemctl start "${units[@]}"; then exit 1; fi
for unit in "${units[@]}"; do
  test "$(systemctl is-enabled "$unit" || true)" = masked
done
if pgrep -x incusd; then echo "Incus is still running" >&2; exit 1; fi
if timeout 10 incus query /1.0; then exit 1; fi
if pgrep -x incusd; then echo "Incus is still running" >&2; exit 1; fi

# Replace the migrated database; do not overlay it with the old files.
rm -rf /var/lib/incus/database
tar -xpf "$backup/incus6.tar" -C /
while IFS= read -r snapshot; do
  zfs rollback -r "$snapshot"
done < "$backup/rollback-snapshots"

# An overlay restore leaves Incus 7's compiled AppArmor policy behind.
rm -rf /var/lib/incus/security/apparmor/cache
install -d -m 0700 /var/lib/incus/security/apparmor/cache
systemctl daemon-reload
systemctl unmask "${units[@]}"
systemctl start incus.socket incus.service incus-user.socket
incus version
```

The retained files must include `incus`, `incus-base`, and `incus-client` at `6.0.5-8`, plus `incus-agent` at that version if it was installed. `--allow-downgrades` crosses Zabbly's `1:` epoch. This release creates no agent override, udev rule, or copied helper. Compare these paths with the backup and restore any pre-existing unit overrides before unmasking. Restore the recorded unit activation state after verification.

The [earlier rehearsal](https://github.com/nckrtl/orbit/pull/1358#issuecomment-6261395054) upgraded to 7.0.1, then downgraded to 6.0.5 with all five units masked. Package hooks, explicit starts, and an API query could not start the old daemon; the migrated database files stayed byte-identical. Restoring the database and recursive ZFS snapshot recovered the project, VM, and custom volume. The VM booted and read both original data markers; writes made after the upgrade were absent. Clearing the AppArmor cache is required: an overlay restore alone left a compiled 7.0 policy that denied the old QEMU binary. Repeat these inventory, boot, agent, and data checks on the real host before resuming admission.

Restore the prior Gateway release with its loader and recipe, and select its retained image and seed before resuming admission. The new loader cannot decode the old Ubuntu Incus recipe. Never adopt a 7.0 image or seed with a 6.0 daemon. Prefer a forward fix if restoring storage would discard work.

### Prepare an Incus host

Run `task-vms:prepare-host {node}` once for each host. It calls `incus.prepare-host` through the pinned agent runner. The command returns readiness after convergence.

First it checks the ZFS storage pool that the host's `pool` names. It creates a missing pool from the host's `zfs_dataset`, such as `fast/orbit-tasks`. That dataset must not exist yet, and Incus creates it. The script refuses an existing dataset and calls no Incus command for it, because Incus would unmount that dataset and destroy it with the pool. Without `zfs_dataset`, a missing pool fails. An existing pool must use the `zfs` driver. With `zfs_dataset` set, the pool's source must be that dataset. Then it sets up these parts:

- The Incus project, such as `orbit-tasks`, with `features.networks=false`, and the stock image alias `ubuntu-26.04-vm` from `images:ubuntu/26.04/cloud`. Only the creation of the base VM of a host launches it.
- The bridge, such as `orbittask0`, with IPv4 NAT and no IPv6.
- The ACL `<bridge>-egress`, which the bridge gets at creation.
- ACL egress drops private, link-local, CGNAT, and multicast ranges, and allows the rest.
- ACL ingress allows TCP 22 from the bridge address and rejects the rest.
- The project's `default` profile: `eth0` on the bridge with `security.port_isolation=true`, and the root disk on the host's pool.
- The project restrictions `restricted=true`, `restricted.networks.access=<bridge>`, and `restricted.virtual-machines.lowlevel=allow`. The first refresh adds `restricted.snapshots=allow`.
- One host rule: `ufw route allow in on orbittask+ comment 'orbit-task-vms'`.

Task VMs need nothing that the restrictions forbid, except low-level config: medium and large VMs set `raw.qemu.conf` for free page reporting. Only the Gateway sets instance config in the project.

The command takes these values from the host Node's typed `settings.task_vms`. See [Configure task VMs](#configure-task-vms). The script checks the form of each argument again before it passes it to Incus as root, and changes nothing when one is invalid. The bridge and the ACL live in the Incus project `default`, and the script names that project in every network and ACL command.

Orbit reserves bridge names that start with `orbittask`. Every such bridge must carry its egress ACL, because the ufw rule accepts forwarded traffic from all of them. Only `incus.prepare-host` creates these bridges, and it attaches the ACL at creation. Never create an `orbittask` bridge in another way.

The restrictions forbid privileged and nested containers, host devices, backups, and every network except the task bridge. Snapshots are allowed because the host's base VM snapshot is the base of every task start ([refresh chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/vm-image-refresh.mmd)). Only a client of the host's Incus API can take a snapshot; a task VM reaches only its guest socket, which cannot. Low-level VM config is allowed for memory reporting.

The dropped egress ranges are `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `224.0.0.0/4`, and `240.0.0.0/4`. Port isolation blocks traffic between VMs on the same bridge. Both are needed. The ufw rule lets bridge traffic pass the host's forward policy, and replies use the existing rule for established connections.

### The base VM and its snapshot

Each task VM host keeps one stopped base VM, `<host>-base`, with its seed volume `<host>-base-seed`. The VM never gets an identity. A ZFS snapshot of the VM and of its seed is the base of every task start: a start copies it ([refresh chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/vm-image-refresh.mmd), [provision chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/start.mmd)). There is no Incus image and no publish.

Only the base snapshot refresh makes a base. The recipe image build is gone, with the kind `task_vm.image_build`, `task-vms:build-image`, `task-vms:build-recipe`, the per-minute recipe reconcile and the image reaper ([old paths](/reference/old-paths)). The Gateway records a host's base as the snapshot that its latest refresh proved. A promoted build that holds an image, not a snapshot, is never a base, and it never replaces the snapshot. A start on a host without a recorded snapshot is refused with `task_vm.image_unavailable`.

A new host gets its base VM in the first phase of its first refresh, `base-start`, once. The phase creates an empty seed volume `<host>-base-seed`, runs `incus init` from the stock Ubuntu VM image that `task-vms:prepare-host` copied, and boots the VM. The first boot makes the `orbit` account with the key of the Swamp control Node and installs the SSH server.

The converge that follows builds everything else, because no digest is recorded yet. The seed holds no nested fleet, so the fleet phase [builds the fleet](#build-the-nested-fleet-in-the-converge). The Pi server is fetched for it. The refresh then stops the VM, snapshots it and proves the snapshot like any other.

The machine recipe still describes the software of the base VM. `RecipeImageBuilds::currentRecipe()` reads the `recipe.json` and the Vite+ lock of the release the Gateway serves, and the converge intents of a refresh and of a task start carry them. The recipe identity does not name the Orbit agent, and the base holds the pinned observer build that the converge installs. The base is identified by the recipe identity that its refresh converged to, recorded on the build row. Image freshness counts a path when it belongs to a carried folder or matches any carried lock-file pattern.

The converge of a refresh installs the recipe packages through the playbook. It writes the same snapshot configuration to `/var/tmp/orbit-image-apt` and installs with the `apt` module with `APT_CONFIG` pointing at it, and `allow_downgrade` because the pins are exact. The index read goes over plain http through the `apt-cacher-ng` of the VM (`localhost:3142`, no request pipelining, signatures still checked), which the first software task installs; the packages stay a direct download over https. The cache stays in the base VM, and the nested Nodes of a task VM read the snapshot indexes from it. The `check` phase asserts every package at its pin.

The converge copies the pinned Pi build from the Gateway Node to 209 with official SSH and checks its SHA-256 there. The `software` phase copies it to `~orbit/.local/bin/pi-server` on the guest when the digest differs from the pin, and checks the digest again. The refresh fetches it only when the recipe of the base is older than the release's.

#### The topology seed

The seed volume `<host>-base-seed` of the base VM holds the pool `topology-seed` with the nested fleet of the running release. The tools phase of the converge makes the pool once, on the blank seed volume. The Incus database of the base VM keeps the pool, and a task VM copies that database with the VM. The phase refuses a seed disk with a file system that the Incus of the VM does not know, because Orbit imports no seed from another VM.

The snapshot of the seed is taken with the snapshot of the VM, and a task start copies both. A medium or large VM mounts its copy as the disk `topology-seed`, so a topology task has its Nodes at once and does not build Orbit again. A task VM that already runs keeps its own clone of the older seed snapshot.

#### Log task VM egress

Orbit records which outside hosts task VMs connect to. The log only records. It blocks nothing, and the [egress ACL](#prepare-an-incus-host) of the bridge stays as it is. Each task VM host sets it up on its own bridge, and the [base snapshot refresh](#refresh-the-base-snapshot) runs the setup as its first step, `Log egress`.

The Ansible part `egress-log` of `swamp/ansible/task-vm-host.yml` takes the bridge name from `host.network` of the refresh intent. It sets up four things:

- The nftables table `inet orbit_egress` in `/etc/orbit/egress-log.nft`. The oneshot unit `orbit-egress-log.service` loads it at boot. A changed file reloads the rules.
- The rsyslog rule `/etc/rsyslog.d/19-orbit-egress.conf`. It writes the kernel lines of the table to `/var/log/orbit/task-egress.log` and keeps them out of syslog and `kern.log`.
- The `raw.dnsmasq` setting of the bridge, which makes its dnsmasq write the DNS queries to `/var/log/orbit/dnsmasq-<bridge>.log`.
- The rotation file `/etc/logrotate.d/orbit-egress` for both logs: daily, seven files, and at most 200 MB.

The table has one base chain on the `forward` hook, after the firewall, with the policy `accept`. Its three rules match a new connection from the bridge to an outside address, other than the ranges the egress ACL drops. The first rule returns when the set `seen` already holds the connection. The set holds the VM address, the outside address, the protocol and the port for ten minutes, so a repeat within that time adds no line.

The second rule returns for a source address above 50 new connections a second, with a burst of 200. The limit is per source address, in the set `flood`. A VM that scans cannot flood the kernel log, rsyslog and the disk, and it loses only its own log lines. The `counter` of the rule counts them. The rule never loses traffic, because `return` ends the chain and the policy accepts. The third rule logs the connection with `log prefix "orbit-egress " flags ether` and then adds it to the set.

Only new connections are logged (`ct state new`). A long-lived connection gives one line when it starts and no line after. The log comes before the add, so a full set repeats lines and loses no connection. The table never drops, rejects or changes a packet. The host has no `conntrack` tool and no connection table in `/proc`, so the log does not read the connection tracker.

A connection line is the raw kernel message with the time, the host name and the tag in front. The `MAC=` field holds the destination MAC, the source MAC and the type, so the source MAC names the VM.

```text
2026-11-07T20:31:02.123456+01:00 shark kernel: [12345.678901] orbit-egress IN=orbittask0 OUT=enp5s0 MAC=aa:aa:aa:aa:aa:aa:bb:bb:bb:bb:bb:bb:08:00 SRC=10.252.7.5 DST=93.184.216.34 LEN=60 PROTO=TCP SPT=41234 DPT=443
```

The DNS log uses the `log-queries=extra` format of dnsmasq: the serial number of the query, the client address and port, the record type, the name and the answer. It is our own file. The file `dnsmasq.<bridge>.log` in `/var/log/incus` holds the stderr of dnsmasq, and Incus truncates it at every start. dnsmasq appends to our file, so a restart of the bridge's dnsmasq loses nothing.

The agent reads both logs as other users, without a capability, so the part makes them world-readable. The folder `/var/log/orbit` has mode 0755. The part creates both log files before they are used, because rsyslog and dnsmasq keep the mode of a file that exists. The connection log has mode 0644.

The DNS log is `root:root` with mode 0664. dnsmasq starts as root and changes a file of group root without the group write bit to mode 0660 and owner `incus`, which would take away the world-read bit. With mode 0664 only the owner changes. The part sets no owner on the DNS log, so a rerun does not change it back. The dnsmasq leases and host files are already readable and stay untouched.

The `raw.dnsmasq` setting is the only way to switch on query logging, and it has a cost. While it is set, Incus starts the bridge's dnsmasq without its AppArmor profile. dnsmasq still runs as the unprivileged user `incus`. Incus shows the warning `AppArmorDisabledDueToRawDnsmasq` for the bridge, so `incus warning list` will show it. Unsetting `raw.dnsmasq` reverses the change.

The command `incus network set` runs the whole setup of the bridge again: the firewall rules of the network, then a kill and a start of dnsmasq. The first run of the part therefore reconfigures each bridge once, and running VMs lose DNS for about a second. Run it when no provision is running. A rerun finds the lines and sets nothing. The part never restarts Incus.

The value is merged. The part keeps every line of the operator and adds `log-queries=extra` and `log-facility=<file>` only when a line is missing. When the operator already logs to another file, the part leaves the setting alone and warns.

Only a safety check fails the part, and with it the refresh. An observation check records a warning with its code, and the refresh goes on, because a log-only failure must not block the base refresh. A failure of the host itself still fails the part: Incus down, SSH lost, or `nft` missing. Each Incus and systemd task has a timeout, so a hung task fails or warns and does not hold the refresh.

Each setup item is a block of its own with its own rescue: the connection log, the log files, the rsyslog rule, the log rotation and the DNS log. A failure in one block cannot skip another. The connection log comes first.

A rescued failure keeps the part green. The part prints each warning in Ansible's log before the safety checks, with the error text of the failed tool.

The end checks, such as `egress.config_missing`, only print `WARNING` lines in that log. An operation does not carry the log of a step, so a rescued failure is visible only in the Swamp run log of the refresh, which Swamp keeps for about 30 minutes, and as an `egress` fact with no rows. The agent reads an absent or empty log file as empty, so a missing rsyslog rule gives a fact that is present and has no rows, not a missing fact.

To check a host, run `ls /etc/rsyslog.d/19-orbit-egress.conf /etc/logrotate.d/orbit-egress` and `incus network get <network> raw.dnsmasq --project default`.

AppArmor confines `rsyslogd` to `/etc/rsyslog.conf` and `/etc/rsyslog.d/**`, even for `-N1`. The rsyslog rule is therefore not checked with `validate:` on a temporary file. When the content differs, the part stages the rule as `/etc/rsyslog.d/19-orbit-egress.conf.new`, a name that rsyslog does not include. It runs `rsyslogd -N1 -f` on that path and then copies the file to its name. It removes the staged file in every case, also when the rule is unchanged and a crash left the file. A rule that fails the check never replaces the old rule.

| Check | Result |
|---|---|
| The rules load (`nft -c` before the file is replaced) | Fails the part |
| The bridge still has `ipv6.address=none` (`egress.bridge_dual_stack`) | Fails the part |
| The bridge keeps its ACL, and its first rule drops the private ranges (`egress.acl_changed`) | Fails the part |
| The table, its set, the dedupe return and the accepting base chain exist, with no verdict that drops or rewrites (`egress.rules_missing`) | Warns |
| The `raw.dnsmasq` value holds both lines (`egress.dnsmasq_unset`, also for a foreign log file) | Warns |
| rsyslog and `orbit-egress-log.service` are active (`egress.service_inactive`) | Warns |
| Others can read both logs and enter the folder, and the DNS log has mode 0664 (`egress.log_unreadable`) | Warns |
| An action of the log setup failed (`egress.setup_failed`) | Warns |
| The rsyslog rule or the log rotation file is missing (`egress.config_missing`) | Warns |

The `Log egress` step runs before the base VM is touched. A failed safety check fails the refresh and starts no rollback. To run only this part, start the workflow `task-vm-host-phase` with `tags: egress-log` and the intent of a refresh.

#### Refresh the base snapshot

Each task VM host refreshes its own base. Nothing is copied between hosts. An operator runs one with `php artisan task-vms:refresh-image <host>` on the Gateway, or every host with `task-vms:refresh-images`, which takes `--commit <sha>` for a release other than the one the Gateway runs.

Two triggers admit every Linux host: every release, from its step "Admit VM refreshes" after the Swamp load, which runs `task-vms:refresh-images --commit <sha>` from the new release, and a daily schedule at 03:20.

A host with a refresh open keeps it and queues the newest commit behind it: the run that ends admits that commit. A host that refuses is skipped until the next trigger. `task-vms:image-builds` (also `task-vms:list-image-builds`) lists recent refresh records.

`task-vms:refresh-image <host> --from-stock` rebuilds the base. At admission it withdraws the recorded base, so a task start on that host is refused until the run promotes the new one. The run deletes the base VM and its seed, with their snapshots, then builds the base from the stock image as on a new host. After a failure, the next refresh builds it as on a new host too. After every refresh that passed, a `host-clean` phase removes what no current path makes:

- in the task VM project, every image except the stock one;
- in the same project, the volumes and instances named `orbit-topology-seed-*` or `orbit-baseline-*`;
- the projects of past experiments that `TaskVmHostLeftovers::RetiredProjects` names (`orbit-baselines`, `orbit-measure-nr`), with what they hold;
- the images of `orbit-proto`.

A failure there fails no refresh.

The Gateway admits the `task_vm.image_refresh` kind with the whole intent:

- the SSH target of the host with its recorded host keys, the project and the pool;
- the recorded base snapshot, if any, and the name of the new one;
- the stock image, the size of the base VM and the cloud-init config for a base VM that does not exist yet;
- the converge intent of a task start, and a free slot for the proof.

It refuses a host that is not an active, configured task VM host, a host with no free slot, and a Gateway whose release has no commit. No step of the run calls the Gateway.

The workflow `operation-task-vm-image-refresh` runs from Node 209, one `command/shell` step per phase of the Ansible playbooks:

| Step | What it does |
|---|---|
| `Log egress` | Phase `egress-log` of `task-vm-host.yml`, in a job of its own that runs first: sets up the [egress log](#log-task-vm-egress) of the host bridge. |
| `Start the base VM` | Phase `base-start` of `task-vm-host.yml`: makes the base VM of a new host, keeps `restricted.snapshots=allow`, starts the VM, waits for its first boot, and gives it the proof key. |
| `Converge` | The converge of a task start (`task-vm-start` runs it with its host part), phases `software`, `tools`, `boot`, `checkout`, `fleet`, `check`. It wipes no identity. It builds a missing nested fleet. |
| `Stop and snapshot` | Phase `base-snapshot`: stops the base VM and snapshots it and its seed volume under the run's name. |
| `Prove the candidate` | `task-vm-candidate-proof`: one task start from the new snapshot in the free slot, then its release. |
| `Prune old snapshots` | Phase `base-prune`: keeps the new snapshot and the recorded one, which becomes `-previous`, and removes the others. |

A failed run runs `Roll the base VM back` (phase `base-discard`): the base VM stops, the run's snapshot goes, and the VM rolls back to the recorded snapshot. A host keeps the base it had. The Gateway records the new snapshot as the host's latest promoted build only when the run passed, with the recipe identity of the release. A running task VM keeps its own clone of the older snapshot.

The converge of a refresh is bounded at 5,400 s, and the converge of a task start at 3,300 s, because a new host builds the whole base. It waits for the nested Gateway only while it builds the fleet.

The proof task start needs one free slot of the host. The refresh VM of the old design and its group in the proof Project are not part of this run.

##### Build the nested fleet in the converge

The `fleet` phase of the common converge (`swamp/ansible/tasks/task-vm-fleet.yml`) reads the containers of the VM first. A fleet is complete when its three containers exist and the operator stamp is recorded. The operator join writes that stamp as its last task, after the build, and every promoted base has it.

The phase builds the fleet (`task-vm-fleet-build.yml`) only when it is not complete. That is a base VM that never held a fleet, or one whose build stopped half way, also after all three containers existed. A converge on a complete fleet, and every task start, skip the build and create, delete or restart no container. No other kind builds a fleet.

The values come from the converge intent of the Gateway, `TaskVmConvergeInput`, which takes them from `TaskVmNestedFleet`:

- the names of the containers and of the network;
- the address, MAC, memory and TLD of each container;
- the operator end and the subnet that a proof admits;
- the mount units of the state;
- the part of the `gateway-bootstrap` input that the Gateway knows.

That part names the package cache of the VM, the address of the nested Gateway, the operator and the agent delivery. The nested network `oe-nested` and the pool `topology-seed` come from the tools phase, which runs on a new base VM because no digest is recorded. The build runs one Ansible task per step, each with its own `timeout`. First it makes the containers:

1. It removes the operator stamp and every container of a half-made fleet, so a build that stops leaves the fleet incomplete and the next one builds it whole.
2. It makes a missing operator SSH key on the VM. It forgets an earlier Gateway host key.
3. It creates the three system containers one at a time from `images:ubuntu/resolute/cloud`.
4. Each container has nesting, a memory limit, a start with the VM, and the address and MAC of the layout.
5. Cloud-init makes the `orbit` account with the operator key. The build turns cloud-init off after the first boot.

Then it makes the nested Gateway and its Nodes:

1. It attaches the proof Swamp Club key of the base VM to the Gateway container.
2. It runs `gateway-bootstrap` once with a local `swamp workflow run`. The run adds the Swamp pin, the pinned host key and the release.
3. Through its own Orbit, the nested Gateway converges its Node and its `gateway` and `websocket` roles. It then adds app-dev and app-prod.
4. Each of those is an Operation of the nested Gateway. `task-vm-nested-wait.yml` waits for its end.
5. Each container admits the proof subnet. The Gateway moves its state off its own checkout.
6. The checkout of the VM becomes the disk device `checkout` of the Gateway and app-dev, and the state is mounted over it.
7. It waits until Doctor of the nested Gateway is healthy.

The bootstrap input names the operator address of the VM, 10.45.0.10, so the nested Gateway records the `operator` Node that the operator join gives its key. Cloud-init disables the `wg` and `wg-quick` AppArmor profiles at boot, because the profile of Ubuntu 26.04 kills `wg-quick` in a nested container. The containers live in the pool `topology-seed` of the seed disk. The nested Gateway makes its own root CA for 10 years and signs its leaves for 397 days, and its Swamp tokens last 30 days. The fleet is private to the task VM, and the daily refresh replaces the seed long before any of these runs out.

The rest of the phase then runs as on any converge: the operator join, the renewal of the Swamp tokens and the code of the commit, each recorded as done. The `base` snapshots of the containers are taken last. The earlier standalone fleet build took 1,840 s (Operation 11816), so a new host's first refresh takes about 30 minutes more than the build of the outer VM.

##### Not built

- The converge of the nested Nodes through the nested Orbit. Stage 2 builds it.

##### Apply and check the nested fleet

The child `task-nested-apply` applies the checkout to the nested fleet, and the restore of the `base` snapshot reuses it. The containers start with the checkout already attached. At boot, the bind mount units of the containers put `.env`, `storage` and `bootstrap/cache` from each guest's own disk over the checkout.

The child checks each of the three units on the Gateway and on app-dev and starts none. In a container no udev confirms the device behind the checkout's mount, so a start job would wait 90 s for it and fail. The child then runs the migrations of the checked-out code on the nested Gateway. It restarts the queue worker, the agent-view subscriber and the Swamp worker, and checks, one unit at a time, that they and the nested Swamp serve are active.

The behavior name of `Check the services` carries the index of the unit, not its name. Swamp opens an SSH control socket under `/tmp/swamp-ssh/<behavior name>`, and a path over 108 bytes cannot be a Unix socket.

The child `task-nested-ready` checks readiness from the VM, over the operator's WireGuard address and the API of the nested Gateway. It first waits up to 120 seconds, with one Ansible task, until Doctor of the nested Gateway is healthy, because the agents read online only after their next heartbeats. All three Node agents must be online, the Gateway must know the operator with its key, and Doctor must be healthy. The child then takes one plain snapshot `base` of the three nested containers.

The child `proof-fleet-restore` puts the fleet back to `base` for the proof. It restores the three nested containers with a limit of 45 seconds and no retry, which is a reboot, starts them, fails unless all three are `RUNNING`, and calls `task-nested-ready` and then `task-nested-apply`.

The batch workflow calls it before every batch but the first. The case workflow calls it after a case that did not pass and is not the last of its batch, so the next case starts from a clean fleet. A restore that fails stops the rest of the batch.

After the apply step, the VM gets a CLI profile. The steps `Make the CLI home`, `Pin the nested CA` and `Add the CLI profile` read the root CA of the nested Gateway with `incus exec … cat` into `~/.orbit/nested-ca.pem` and run `gateway:add https://10.45.0.1 --name=nested --use --ca=…` with the candidate CLI of the checkout. The proof runner runs that same CLI (`php apps/cli/orbit`) and finds the nested Gateway through the profile. The apply child checks the Swamp services only when the VM has the proof Club key (input `club`), because the nested Swamp serve cannot start without it.

The VM then joins as operator. It makes its own WireGuard key and writes the public key into the nested Gateway's `operator` record. It puts the key on the hub and brings up `wg-nested` as `10.45.0.10`. For a task that needs the proof Swamp Club key, the VM makes the folders `/home/orbit/.config/swamp` in the nested Gateway, owned by `orbit` with mode 0700, pushes the key into them and restarts its Swamp.

### Claims copy the base snapshot

A new Incus start copies the host's recorded base snapshot and its seed snapshot, which are ZFS clones, starts the copy and writes the identity of the claim. The VM records the `recipe_identity` of the snapshot at allocation. A later refresh cannot change the snapshot a VM was copied from. A host without a base snapshot cannot serve a new claim.

An existing group keeps its VM across recipe changes, failures, parking, and resume.

#### Image fallback

There is no fallback image. A claim copies the recorded base snapshot of its host, or waits. Another host with a base snapshot can serve it.

### Add a task VM host

A second host needs Incus, ZFS, and ufw, and its managed user needs passwordless `sudo`. Then:

1. Set the host Node's [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) with `node:update --task-vms` or the Node update API. Use a unique `cidr` and a new `zfs_dataset`, such as `data/orbit-tasks`.
2. Run `task-vms:prepare-host {node}`. It creates the pool from the new dataset and sets up the host.
3. Run `task-vms:refresh-image {node}`. The first refresh makes the base VM and its first snapshot ([the base VM and its snapshot](#the-base-vm-and-its-snapshot)).

To remove a host, lower its capacity to stop new placements, wait until all its task VMs are destroyed, then clear `settings.task_vms`. Its Incus project, images, topology seeds, bridge, ACL, and pool remain for manual removal.

### Limit fleet traffic on the hub

This filter relies on the [Caddy guard](#refuse-task-vms-in-caddy). Reverb and the private Swamp Route share TCP 443 on the `websocket` Node with every other private Caddy site on that Node, such as `executor.orbit`, so an L4 rule cannot tell the sites apart. Run `task-vms:prepare-hub` only after you configure task VMs and rebuild Caddy on every Node that serves sites.

Run `task-vms:prepare-hub` once, and again when a captured value changes. The command records a `task_vm.hub_converge` Operation and prints `Operation #<id>`; submission does not mean the filter is ready. Its native workflow runs on 209 and reaches the VPN Node through official SSH with the recorded host pins. No Swamp binary or worker is installed on the VPN Node.

The workflow checks the complete nft ruleset with the hub's own `nft`. One step then writes the filter and its systemd unit, enables and restarts the unit, and lists the live chain. After the filter loads, UFW allows TCP 22 and 443 (the Route's TLS probe) from 209, and TCP 80 and 443 from each Cluster Router, one rule at a time.

A native assert is the end check: every expected control and Router rule must be a line of the live chain. Only then does the Gateway record the hub ready, with the sources from its intent. A failed step leaves the Operation failed; a rerun writes and loads the same file again.

The unit loads the table after `nftables.service` and before `wg-quick@orbit` starts. The tunnel pulls the unit in but does not depend on it, so a failed load never stops the fleet VPN. The nft transaction replaces only `inet orbit_task_vms`; unrelated firewall tables remain unchanged.

The command takes every value from Gateway records and settings:

- the reserved range from `task_vms.wireguard_range`;
- the active209 control Node's recorded WireGuard address, for SSH on TCP22 only;
- the VPN DNS address: the `vpn.dns_server` setting, or else the WireGuard address of the `vpn` Node;
- the Node with the `gateway` role, and the Pi port `orbit.pi.port` (`ORBIT_PI_PORT`, default `3774`);
- Reverb at `reverb.orbit`, which is TCP 443 on the Node with the `websocket` role; the Swamp Route uses that same Node and port;
- the host and port of the model router guard (`TaskVmModelRoute::origin()`, port 8318);
- every active Node with a recorded Router-role assignment in the dev Cluster, including a provisioning replacement until its assignment is withdrawn.

The table filters only the reserved range. Its first rule passes every packet that has no address in the range, so traffic between other Nodes does not change. The range must be inside the WireGuard subnet, because every Node routes only the subnet through the tunnel. `TaskVmSettings` checks that. The command refuses a range that holds the address of a Node that is not the Node of a live task VM. It also refuses a missing dev Cluster, a model route that is not ready, and a guard origin whose host is not an IPv4 address outside the range.

| Direction | Allowed |
| --- | --- |
| From task VMs | The Gateway API on TCP 443, the model router guard on TCP 8318, Reverb and the configured Swamp site on TCP 443, and DNS on port 53 of the VPN DNS address |
| To task VMs | The Gateway on TCP 22 and the Pi port,209 on TCP22 only, and the dev Cluster’s Router-role Nodes on TCP 80/443 only |

The 209 SSH and TLS probe rules and the Cluster Router web rules come from the same canonical definitions shown by `firewall:list --managed` on the VPN Node. A Router in another Cluster receives no rule for this range. Router web access grants neither SSH, Pi nor Vite-port access. Adding a UFW allow alone cannot override the earlier nft filter. The native hub convergence updates both managed surfaces. `firewall:list --live` reads the last completed native UFW inspection; an unreachable result does not prove current rules.

The table accepts established traffic and drops all other traffic to or from the range. The WireGuard address allocator skips the range for other Nodes. Only `AllocateTaskVmAction` assigns addresses in it. A task VM Node has no access grants to other Nodes.

### Refuse task VMs in Caddy

TCP 443 on the Gateway Node and on the `websocket` Node is one Caddy listener for every private site on that Node, so the hub table cannot tell `reverb.orbit` from `executor.orbit` or `analytics.orbit`. Caddy closes that gap. On every Node, every site except `gateway.orbit`, `reverb.orbit`, and the optional Swamp site named by `task_vms.swamp_host` aborts a client in `task_vms.wireguard_range`. [Caddy configuration](/reference/caddy-configuration#listener-addresses) shows the guard.

Set `ORBIT_TASK_VMS_SWAMP_HOST=swamp.orbit` for the managed private Swamp Route on the `swamp` Node. The setting adds no hub rule, so a task VM reaches the site only when the hub admits TCP 443 on that Node, as it does on the Gateway and `websocket` Nodes. The exception matches exactly one hostname on port 443 and never a public Ingress site or a block with extra hostnames. The other client-range guard still applies. It admits no sibling site, subdomain, or alternate port. An unset or empty value admits no Swamp site.

Caddy renders the guard only after you configure task VMs: you enable them, or you set `dev_cluster_id`, `swamp_host`, or a host. Until then, every Caddyfile stays as it was. The guard reads only that condition, `task_vms.wireguard_range`, and `task_vms.swamp_host`. Another invalid task VM value never changes a Caddyfile or blocks a build.

The next Gateway release rebuilds only the Gateway Node's Caddyfile. After you set these values, run `php artisan orbit:caddy-build NODE` for every other Node that serves sites, before the first task VM enrolls. Until then, Doctor reports `role.caddy_build_drift` on each of those Nodes. Changing or clearing `swamp_host` also reports this drift on the Node that serves the hostname until its next build. Unset these values only after every task VM is destroyed, because the next build of each Node then drops the guard.

While task VMs are configured and the range or Swamp hostname is invalid, every Node Caddy build refuses at stage `render` and names the problem. Live Caddyfiles stay unchanged. After [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change), run the Node Caddy build to apply a corrected range. Gateway releases do not converge Caddy sites.

### Placement invariant

A task VM workspace is a normal Instance on a normal Node, so generic Instance operations need no sandbox guard. One rule protects the VM: an Instance on a task VM Node must be the workspace its group [owns](/reference/tasks#workspace-ownership), in the group's Project. The rule runs whenever an Instance is saved, so it covers create, clone, transfer, and register. Any other Instance fails with HTTP 409 `task_vm.foreign_instance`. Orbit also refuses an access grant from a task VM Node with HTTP 409 `task_vm.access_refused`. The fleet rollout skips task VM Nodes with the reason `sandbox`.

### Configure task VMs

Global keys live in the Gateway's `config/task_vms.php`. Set them in the Gateway's environment. Per-host configuration lives in the operator-owned [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) on each host Node, not in the environment and not in its roles.

| Key | Variable | Meaning |
| --- | --- | --- |
| `task_vms.enabled` | `ORBIT_TASK_VMS_ENABLED` | Allows new task VMs. Default `false` |
| `task_vms.dev_cluster_id` | `ORBIT_TASK_VMS_DEV_CLUSTER_ID` | The ID of the Cluster that task VM Nodes join |
| `task_vms.wireguard_range` | `ORBIT_TASK_VMS_WIREGUARD_RANGE` | The reserved WireGuard range. Default `10.44.0.128/25`, the upper half of the default VPN subnet `10.44.0.0/24` |
| `task_vms.swamp_host` | `ORBIT_TASK_VMS_SWAMP_HOST` | One DNS hostname for the private Swamp Route, such as `swamp.orbit`, served on TCP 443 by the `swamp` Node. No scheme, port, path, wildcard, or list. Unset or empty admits no Swamp site |
| `task_vms.pi.artifact_path` | `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` | The absolute path of the pinned Pi executable on the Gateway |
| `task_vms.pi.artifact_sha256` | `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256` | Its lowercase SHA-256 digest. Set both Pi artifact values or neither |
| `task_vms.pi.models` | `ORBIT_TASK_VMS_PI_MODELS` | A JSON list of the models Pi offers. Default `[]` |
| `task_vms.medium_projects` | `ORBIT_TASK_VMS_MEDIUM_PROJECTS` | A JSON list of Project IDs whose groups get a [medium VM](#size-of-a-task-vm) when no subtask declares a topology. Default `[]` |
| `task_vms.large_projects` | `ORBIT_TASK_VMS_LARGE_PROJECTS` | A JSON list of Project IDs whose groups get a [large VM](#size-of-a-task-vm) when no subtask declares a topology. Takes precedence over `medium_projects`. Default `[]` |
| `task_vms.topology_paths` | `ORBIT_TASK_VMS_TOPOLOGY_PATHS` | A JSON object from Project ID to a list of path patterns that need [real machines](#paths-that-need-real-machines). Default `{}` |

Task VM code reaches the model router only through the core interface `App\Domain\TaskVms\TaskVmModelRoute`: `assertReady()`, `origin()`, and `key()`. The [ai-router extension](/reference/ai-router) binds it. `origin()` is the [task VM guard](/reference/ai-router#task-vm-guard) on the router's Node, such as `http://10.44.0.3:8318`, and `key()` is the one fixed placeholder key that every task VM holds. The Gateway keeps the key as a secret setting and changes it only on an incident, with no scheduled refresh. A task start writes the origin and the key into the Pi models file of the VM, inside the claim. No start and no release calls the model router, and no per-VM key exists.

The `tasks` extension depends on `ai-router`, and enabling `tasks` calls `assertReady()`, which refuses with `ai_router.disabled` (409) until the ai-router is set up and has its Node. `ai-router:destroy` refuses only while `tasks` is enabled. See [Teardown](/reference/ai-router#teardown).

The [Node settings field table](/reference/node-settings#task-vm-fields) owns the host configuration. The containing Node supplies host identity. `operator_owned` keeps listed resources out of the [stray report](#report-strays).

Placement selects active Nodes with this setting and capacity reads their slot budget. Host preparation uses its pool, network, and image alias. The `app-dev` role is not a host marker.

To replace `ORBIT_TASK_VMS_INCUS_HOSTS`, [configure and verify Node settings](/reference/node-settings#migrate-the-environment-host-list) before removing the variable from `gateway.env`. The Gateway does not read the environment list.

`TaskVmSettings` validates the configuration once, when it is first used. It checks every value that is set, also while task VMs are off, so you can prepare hosts and the hub before you enable task VMs. An unset or empty value stays absent, and malformed JSON is an error. Enabling task VMs also requires `dev_cluster_id` and at least one host.

The reserved range must always be a private network. As soon as task VMs are enabled, or the Cluster, the origin, the Swamp hostname, or a host is set, the range must also be a smaller network inside the Gateway's VPN subnet, and no host's `cidr` may overlap that subnet. While none of them is set, Orbit does not compare the range with the subnet, so a fleet on another subnet keeps working. Invalid configuration fails with `task_vm.invalid_config` (HTTP 500).

### Errors

`IncusTaskVmProvider` reads the host's output: the instance state and its one IPv4 address inside the bridge range. Invalid output fails with `task_vm.invalid_host_output`, and a failed guest command with `task_vm.host_command_failed`. Every task VM error code starts with `task_vm.`. The setup commands print the code and message, and for `task_vm.setup_failed` the last lines of the script's error output. After that check, Orbit trusts its own records.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.invalid_config` | 500 | A `task_vms` value is invalid. See [Configure task VMs](#configure-task-vms) |
| `task_vm.unknown_host` | 409 | The Node is not active or has no valid `settings.task_vms` |
| `task_vm.invalid_gateway_key` | 500 | The Gateway's SSH public key is not one OpenSSH public key line |
| `task_vm.workspace_mismatch` | 409 | A group's workspace is not on its ready task VM |
| `task_vm.foreign_instance` | 409 | An Instance on a task VM Node is not its group's workspace |
| `task_vm.access_refused` | 409 | An access grant names a task VM Node as its consumer |
| `task_vm.range_in_use` | 409 | A Node that is not the Node of a live task VM holds an address in the reserved range |
| `task_vm.fleet_unavailable` | 409 | The fleet has not exactly one active `vpn`, `gateway`, or `websocket` Node, the dev Cluster has no active router, or one of these Nodes has no WireGuard address |
| `task_vm.setup_unavailable` | 409 | The setup script is missing, or the target Node has no WireGuard address |
| `task_vm.setup_failed` | 409 | The setup script failed or did not print `{"ok":true}` |
| `task_vm.invalid_host_output` | 502 | Incus or a task VM guest returned output that fails these checks |
| `task_vm.host_command_failed` | 502 | An `incus` command on the host, or a guest command in a task VM, failed |
| `task_vm.job_failed` | 500 | A task VM job failed with an error that has no code of its own |
| `task_vm.base_image_missing` | 409 | The host's project has no `orbit-task-base` image. Run `task-vms:refresh-image <host>` |
| `task_vm.topology_seed_missing` | 409 | The host has no topology seed for the base image of a medium or large VM. Run `task-vms:refresh-image <host>` |
| `task_vm.topology_seed_failed` | 502 | The topology seed build was refused. The error includes its bounded diagnostic |
| `task_vm.image_refresh_unavailable` | 409 | A refresh cannot start: the Gateway release has no commit |
| `task_vm.image_refresh_failed` | 502 | A refresh run failed. The Operation names the failed step |
The build row records the step and error |
| `task_vm.parked` | 409 | The group's task VM is `parked`. Orbit starts it, and the work runs once it is `ready` |
| `task_vm.resume_timeout` | 504 | A `parked` VM did not become ready before its fixed 9-minute resume deadline |
| `task_vm.vm_missing` | 502 | The sweep found a `ready` or `parked` task VM gone from its host |
| `task_vm.git_credential_refused` | 409 | A command that carries a GitHub credential was about to reach a task VM or an `incus` command. See [Git on a task VM](#git-on-a-task-vm) |

### Limits

Incus accepts DNS before the ACL, so a task VM can query port 53 on any host address and read instance names from other bridges. This risk is accepted. Transfers over WireGuard may pass through the VPN hub.

Parking keeps the VM's slot and disk, but its private Route does not answer and Doctor skips its Node. Set [preview](#request-a-preview) to keep a VM running during review.

The [Git mirror](#the-mirror) needs repository-sized Gateway storage. A fresh workspace receives the full history of its selected branches, without tags. Fetch, pack, and push operations serialize per Project, with a 300-second lock wait. Operations that would send a credential to a VM require its group to end first.

The hub filter fails open to keep the fleet VPN up. If a later `nftables.service` restart removes the table, run `task-vms:prepare-hub` again; its unit can still show active while the table is absent.

### Why task VMs work this way

The VM edge is the security boundary. Agents have root inside it, so an in-VM check is fast feedback, not proof against tampering. [CI on the pull request](/reference/tasks#review-and-merge) is the merge gate. Public egress is open on every port; the bridge ACL and hub filter limit private traffic.

Task VMs are untrusted WireGuard peers. On the shared HTTPS listeners, Caddy admits only the Gateway API, Reverb, and the single configured Swamp site. Admitting Swamp grants network reachability to that whole site; Swamp still authenticates each worker with its short-lived per-group credentials. The exception grants no access to the other sites on Services.

Limiting egress to HTTP(S) and DNS breaks clock sync, package sources, and key installs. It adds no protection beyond the VM edge.

Host output is validated once, and Gateway records are trusted after that, so six states and no marker timestamps describe the whole lifecycle. `claimed_at` times the claim. Parking reads the settle times the group already records.

The agent in a task VM has root through sudo, so a token anywhere in the VM, even in another user's process, is the agent's token. The Gateway already holds the tokens and already reaches every task VM over SSH. So Git that talks to GitHub runs on the Gateway, and only objects cross the VM edge.

Packs on the standard input and output of the existing SSH commands need no new transport, keep the check that a command reaches only its group's workspace. The Gateway checks what comes back the way a fetch checks a remote: only the approved commit ID names what it pushes, and `index-pack --strict` checks every object.

Most groups wait for review far longer than they work, so parking frees the host's CPU and memory for other groups. A stop keeps the disk, so a resume continues the same workspace in seconds instead of a new claim. Placement by free slots spreads VMs across hosts and keeps every host inside its budget. The sweep catches what a crash between two steps leaves, and it acts only on names and the project that Orbit owns.

A topology declaration before the claim gives placement a known workload and the reviewer a declared set of Nodes to check. An agent's request during a turn would supply neither in advance. Resizing or moving a running group would change its environment during work. A fresh group makes that choice explicit. One size for every Orbit VM would waste capacity: most groups need no topology, but a large VM takes three slots. Measurements on beast put the test Gateway, an `app-dev` Node, and the operator at about 2.7 GiB together. Four test machines filled a large VM's 12 GiB under IO.

These alternatives were also rejected:

| Alternative | Why not |
| --- | --- |
| A fallback to the stock image | A VM from it cannot enroll, so the failure would come later and less clearly |
| A snapshot before each park | ZFS keeps the stopped disk, and a resume continues it. A snapshot adds a copy that nothing restores |
| A parked VM frees its slot | A resume could then find its host full, and the group's work would wait behind new groups |
| Placement in host order only | The first host fills before the second takes any VM |
| A token in the VM for each fetch and push | The agent can read any process in the VM. A read token exposes every private branch, and the write token lets the agent push any commit |
| WireGuard in cloud-init | It bypasses normal enrollment. The Node record, peer, and roles would come from separate paths |
| A hub table for each VM | One static filter on the reserved range enforces the same limits without per-VM rules on the live hub |
| The Incus REST API from the Gateway | It needs certificate and host setup. The Gateway already has root SSH, and host-local builds need no image transfer between hosts |
| Subscription sign-ins copied into VMs | They expose the subscriptions. Rotating OAuth refresh tokens fail when several copies refresh |
| A task VM outside the fleet for every Project | Project work needs real Routes, deployment, and previews. Enrollment with no access grants and the hub filter support those features within the VM boundary |
| A finished task VM as the next image | Agents and Project install scripts can change any file. Cleanup cannot prove that disk clean |

## Orbit Project

A group of the `orbit` Project runs on a task VM, like the group of any other Project. Its workspace is the Instance `task-<group id>` without a Route, and every claim clones from the Gateway mirror and installs the dependencies of all five Composer projects and the web app. Its task check is root `composer check`, which runs `bin/review-check` on the VM as `orbit`.

Configure the Project like this:

- List it in `task_vms.medium_projects`, so its groups get a [medium VM](#size-of-a-task-vm) with 4 vCPU. Its check runs the test suites in parallel.
- Add the setup step [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) after the install steps.

Without the `@orbit/ci-caches` step, Pint and PHPStan start cold in every task VM workspace. With it, a fresh workspace gets the Pint and PHPStan caches that main CI published for its baseline.

The setup and teardown steps run on the VM as `orbit`. The `seed-dependencies` step does nothing there, because a task VM workspace has no `ORBIT_SEED_PATH`: the install steps build fresh dependency trees using private caches.

### Declare a topology

A task group cannot declare a topology. The `topology` field of a subtask stays in the API and the CLI, but the Gateway refuses any nonempty list with `422 tasks.orchestration_unsupported`. This holds when a request creates a group, creates a subtask, or updates a subtask. The Gateway checks the format first: an entry names one node, `app-dev`, `app-prod`, or `app-prod-2`, at most once, and may add the role `ingress` after a `+`. A malformed list, or one with more than two nodes, is refused with `tasks.invalid_topology`.

No task VM prepares a topology, and the Gateway reads no topology evidence. A group saved before this rule keeps its declaration. Only VM sizing (`TaskVmSize`) and the check evidence (`TaskCheckEvidence`) still read it. The columns `task_vms.topology`, `topology_state`, `topology_run`, `topology_started_at`, and `topology_error` stay in the schema, and the Gateway neither reads nor writes them. A contributor can still acquire a topology by hand with `bin/e2e-topology`, as [Incus topologies](/reference/incus-topologies) describes.

### Paths that need real machines

`task_vms.topology_paths` is the safety net. It maps a Project ID to path patterns in the syntax of [file deliverables](/reference/tasks#deliverables). For Orbit, list its Node, agent, guest, deployment, and routing code, for example:

```json
{"46": ["apps/agent/**", "apps/e2e/resources/guest/**", "apps/gateway/app/{Domain,Infrastructure,Actions}/{Nodes,Deployments,Routes,Caddy,WireGuard}/**"]}
```

Orbit checks this when the handoff check of a group passes. It refuses the handoff when the group's own changes touch a matching path, since no subtask can declare a topology. It compares the candidate tree with the merge base of HEAD and the current `origin/main`, including uncommitted files. Changes inherited from main do not count; the group's own changes, including merge resolutions, still count. An unreadable merge base or diff fails the handoff. See the [handoff rubric](/reference/tasks#execution-ownership).

The reminder starts with `This subtask changes paths that need real machines`. The implementer reverts those changes, or ends its turn blocked so the operator can take that work outside a task group.

## VM power

Task group responses report `vm_power` separately from task status. For a [task VM](#task-vms), it is `running` while the VM is `ready`, `stopped` while it is [`parked`](#park-and-resume), and `destroyed` once it is `destroyed`. In the other states it is `null`, and the group's wait reason names the state. The CLI shows this value as **VM power** when it is not `null`.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. A task VM with `preview` is never [parked](#park-and-resume). Setting `preview` does not start a VM that is already parked. The next work for the group starts it.

Use `orbit task:create --preview` or `orbit task:update <group> --preview`. Use `--no-preview` on update to release the preview. The two update flags are mutually exclusive. The API accepts JSON booleans; omitted updates preserve the current intent.

The former lifecycle and standalone Incus admissions for Task VMs return `operation.kind_unavailable` (409) before reading a host or recording an Operation. Standalone enrollment uses `StartTaskVmEnrollmentOperationAction`; provision parents compose the same native lower steps directly. The old enrollment job and runtime transport/contract registrations are removed. Private material for the model router requires the admitted top-level router Node to equal the private binding owner and the configured issuer owner.
