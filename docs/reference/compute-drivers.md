---
title: "Compute drivers"
description: "Task VMs for every Project and their UpCloud overflow."
covers:
  - apps/gateway/{app/{Domain/TaskVms,Infrastructure/TaskVms,Jobs/TaskVms,Actions/TaskVms,Domain/Compute}/**,app/Console/Commands/{BuildTaskVmFleetCommand,ConvergeTaskVmCommand,RefreshTaskVmImageCommand,RefreshTaskVmImagesCommand,SaveTaskVmReadyCopiesCommand}.php}
  - apps/gateway/app/{Models/TaskVm.php,Models/TaskVmImageBuild.php,Console/Commands/*ImageBuildsCommand.php,Console/Commands/PrepareTaskVmHostCommand.php,Console/Commands/PrepareTaskVmHubCommand.php,Console/Commands/BuildTaskVmImageCommand.php,Console/Commands/BuildTaskVmRecipeCommand.php,Console/Commands/BuildUpCloudTaskVmImageCommand.php,Console/Commands/ResolvesTaskVmHostNode.php}
  - apps/gateway/{app/Infrastructure/MachineSoftware/**,app/Console/Commands/MachineRecipeCommand.php,resources/machine-software/**,resources/remote/task-vms/**}
  - .github/workflows/{php-build-mirror,git-build-watch}.yml
  - {bin/{php-build,git-build-watch}}
  - apps/gateway/{app/Models/PackageCache*.php,app/Console/Commands/PackageCacheUsageCommand.php,config/package_cache.php,config/task_vms.php,resources/task-vms/**,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - swamp/{workflows/{workflow-operation-task-vm-image-build,workflow-task-vm-image-ansible,workflow-task-vm-image-smoke,workflow-task-vm-image-promote,workflow-task-vm-image-reaper,workflow-operation-task-vm-sweep,workflow-operation-task-vm-image-refresh,workflow-task-vm-image-clean,workflow-operation-task-vm-ready-copies,workflow-operation-task-vm-fleet-build,workflow-task-nested-fleet,workflow-task-nested-apply,workflow-task-nested-ready,workflow-task-nested-converge,workflow-proof-fleet-restore,workflow-operation-task-vm-upcloud-image-build,workflow-task-vm-upcloud-image-server,workflow-task-vm-upcloud-image-ansible,workflow-task-vm-upcloud-image-smoke}.yaml,extensions/behaviors/operations/{task-vms/{upcloud_image_api,upcloud_image_api_test,image_reaper}.ts,task-vm-sweep/{manifest.yaml,metadata.ts,policy.ts}}}
---

# Compute drivers

Every task group runs in its own [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster. This holds for every Project, `orbit` included. See [Orbit Project](#orbit-project).

Task VMs are off until you [configure them](#configure-task-vms). Until then no task group can start.

Task VM enrollment and Node convergence share the public hub peer renderer. Enrollment writes only its guest peer; converging the VPN hub publishes the complete recorded list.

## Machine software recipe

The Node.js artifact pin is `24.22.1`, including the [October 21, 2026 security fixes](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases). Nodes and task images install it through the shared recipe installer. Vite+ project pins and retained releases select their own runtimes and must be updated separately.

The repository's machine software recipe is [`apps/gateway/resources/machine-software/recipe.json`](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/resources/machine-software/recipe.json). It declares one Ubuntu snapshot, tool version pins, the [PHP build](#php-build), and the tool sets for `gateway`, `app-dev`, `app-prod`, `operator`, and the task VM base image. Project dependencies, workspace setup, credentials, Node identity, and service configuration are not recipe inputs.

The recipe pins both the Vite+ runtime (`vp`) and CLI (`vp-cli`) to `1.4.0`. Project lockfiles stay independent: [deployment admission](/reference/tools#project-environments-in-the-vite-store) accepts a manager at least as new as the Project's locked Vite+ release, including Projects on `1.2.x`. A recipe update does not update those lockfiles.

The loader and validator define the recipe contract. [Recipe image builds](#build-the-base-image) use that document for a host-local base image. Claims use the [current recipe](#claims-use-the-current-recipe), or for a day the host's [last promoted image](#image-fallback), and topologies [copy their Nodes from its topology seed](#prepare-the-topology).

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

The base also includes the existing image builder's 26 explicit Ubuntu packages: `openssh-server`; workspace tools `rsync`, `bind9-dnsutils`, `gcc`, and `libc6-dev`; and all 21 libraries in `TaskVmCloudInit::ChromiumLibraries` for Playwright's headless Chromium. These are base-only tools, not common or role inputs. Each pin is the newest version in that authenticated snapshot, identical on both architectures.

The base also holds the nested Incus packages, so a task VM [runs a topology](#prepare-the-topology) without an install:

- The exact Incus 7.0 LTS package set, whose recovery API adopts the [topology seed](#build-the-base-image).
- `dnsmasq-base`, for the nested bridge.
- `btrfs-progs`, for the pool of the topology seed.
- `nftables`, for the isolation table.

Zabbly bundles QEMU, qemu-img, virtiofsd, and firmware with Incus. No separate Ubuntu packages provide them. The recipe installs packages without recommended packages. The `base` profile is amd64 only, because Pi is. The topology Nodes start from the same base and carry these packages unused.

The base also holds `fcgiwrap` for a topology's `gateway` VM. It serves the topology's Git repositories over HTTP, with Git's own `git-http-backend`. The base does not hold the `dnsmasq` service: no topology VM holds the `vpn` role, and the package's own unit fails at boot on any other Node, because systemd-resolved holds port 53. A `vpn` Node gets `dnsmasq` from its role. `dnsmasq-base` stays, for the nested Incus bridges.

After the packages, the image build adds `orbit` to `incus-admin`, because cloud-init adds groups only to a user it creates. Software verification checks the membership.

Builder cloud-init declares the `orbit` account with UID 1000 and its own primary group. It runs no account-creation script. The `check` phase of the image build requires both UID and GID 1000. A taken ID or a pre-existing account with different IDs fails the build; Orbit does not renumber accounts or accept another group. Image smoke checks require the same IDs again.

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
2. Each task VM host [builds a new base image](#build-the-base-image).
3. The next [PHP step](/reference/php-runtime#php-build) of an Instance brings its versions to the recipe.
4. Hosted CI installs the new build in its next run. See [runner updates](/reference/implementation-loop#one-php-build-in-ci) for the pre-merge operator steps.

### Recipe identity

The recipe identity is `sha256:<digest>`, computed from the validated document's canonical JSON after removing internal-artifact filenames. Loading a recipe resolves its `agent` field from the Gateway delivery manifest before hashing. This field contains the delivered Linux SHA-256 digests keyed by platform; the source recipe uses `null` and carries no agent version or artifact pins. Changing either Linux digest changes the identity. macOS artifacts and delivery storage locations do not. Stored build recipes retain the resolved map, so resuming a build cannot silently substitute another agent. A build or Node convergence refuses a missing or mismatched target pin before applying software.

Internal source commits and digests remain in the identity; relocating identical internal bytes does not change it. Canonicalization recursively sorts object keys by byte order, preserves array order, emits no insignificant whitespace, leaves slashes and Unicode unescaped, and adds no final newline. Only JSON integers, strings, arrays, objects, booleans, and null are permitted; schema fields further restrict their types. Duplicate keys are rejected before hashing. The identity is derived, not stored as a self-referential field in the recipe.

Whitespace and object-key order do not change the identity. Changing a snapshot, version pin, external artifact URL, `artifact_mirror`, source commit, digest, role assignment, schema version, or list order does. The identity covers all profiles together; there is no independent version counter for each role and no Git commit SHA masquerading as the recipe version. Git history provides provenance, while the content hash names the exact software intent even when the same recipe appears in another commit.

[Base image builds](#build-the-base-image) record the same identity in the image and build row. Schema version `1` and a short display hash are not substitutes.

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

A closed, versioned JSON document is reviewable without executing code. A content hash names all role sets together without a manually maintained counter. Separate structural and online checks distinguish malformed intent from unavailable inputs. Explicit artifact hashes cover software that Ubuntu's snapshot cannot freeze. One catalog prevents misspelled tools or roles from silently changing an image. The [image build fence](#build-the-base-image) owns publication; the validator checks recipe inputs.

One recipe identifies the software in task VM images. A changed recipe rebuilds the whole base from stock on each host. Partial builds and incremental images were rejected because previous image history would become another input. A fixed nightly rebuild without a versioned recipe would not identify the intended software or connect it to production.

Trusted Gateway jobs build images and fill package caches. Agents have root inside task VMs, so letting them publish either shared result would allow one Project to affect another. The cache inventory records which Projects use each package for incident investigation.

Every workspace clones and runs Project setup. Per-Project snapshots would keep setup coupled to image builds, invalidation, and retention. Package and CI caches speed setup without a saved workspace. Fresh VMs also avoid capacity held by idle pre-enrolled guests and their refill lifecycle, at the cost of boot time.

#### One PHP build

PHPStan writes `PHP_VERSION_ID` and the sorted `get_loaded_extensions()` into its result cache metadata, and Pint keys its cache on the full PHP version. [CI cache refs](/reference/implementation-loop#ci-cache-refs) only help where the PHP build matches exactly. One build also gives every machine the same behavior and the same security patches.

A pin for each environment was rejected. CI, Nodes, and images would each keep a copy of the version and the extension list, and the copies drift. [Feature delivery](/reference/implementation-loop#one-php-build-in-ci) explains why CI does not use `setup-php`. [PHP runtimes](/reference/php-runtime#why-it-works-this-way) explains the Sury packages, the recipe's files instead of the Sury apt source, the one installer, and PCOV.

The Gateway's package cache cannot hold the mirror. That cache lives on each task VM host and serves no other machine, and GitHub-hosted runners cannot reach the Gateway. A release in Orbit's repository serves Nodes, images, and every CI runner.

Purging extra PHP packages on every Node could break a client site that uses an extension an operator installed. Convergence refuses instead. Only the container installer uses the `ci-runner` profile to purge, because each container runs only CI jobs. A host with that role can share other workloads and always refuses extras.

The `ci-runner` role selects the CI host recipe and replaces the recorded-profile exception. Roleless operator Nodes do not gain workload or maintenance status from old profile settings.

#### Git from exact artifacts

Tracking a live PPA would make it a second owner of Git, and an exact APT version alone does not last, because Launchpad deletes superseded builds. The cost is that Orbit, not unattended-upgrades, delivers Git security fixes, so the daily `Git build watch` workflow signals each one for review.

## Task VMs

Incus task VM provision and resume wait for `Running` after their single start request. The host reads only the admitted project and VM once per second, under a literal 120-second timeout over official SSH from 209. A failed read, missing or foreign VM, or unsupported terminal state fails without another start. This restores the legacy stopped-state grace before enrollment.

After a new Incus VM starts, provision waits for its first IPv4 lease with the native `incus wait <vm> ipv4 --timeout 120 --interval 1`, then reads the VM once. A VM without a lease at the timeout fails the provider step; nothing is started again.

Task VM enrollment runs bootstrap as SSH argv behind the Incus or guest prefix; no bootstrap script ships.

The bootstrap steps are the child workflow `task-vm-bootstrap`. The enrollment calls it, and so does the image refresh on its check VM.

The bootstrap creates the managed user, installs its authorized key and sudoers file from stdin, and checks the observer download with `sha256sum`. Verification then runs as that user: `sudo -n true` and the observer checksum. The observer identity is read with `sha256sum`, and an assert compares it with the recorded `observer_digest`. The sudoers step removes an earlier copy first, because `install` fails over an existing file; a retry on the same guest works. Task provision uses a 30-second default queue allowance; explicit step limits still apply.

Swamp requests review parking through `tasks.park`. A parked row reports unknown power until the provider confirms stopped. A wake invalidates that confirmation. Gateway resource observation refreshes parked power in every group status and never starts or stops a VM.

A `task_vm.destroy` Operation cannot be cancelled after its exact `provider-delete` child has succeeded with a proven deletion step. The Gateway returns `operation.cancel_refused` before changing cancellation flags or descendants. Destruction must finish or resume to remove the VM’s Node and workspace records. Before provider deletion succeeds, the Operation remains cancellable.

A task VM is an Ubuntu 26.04 VM on an Incus host. It boots from the [base image](#build-the-base-image), which each host builds from the same recipe and which holds the `app-dev` tool set. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the normal Instance and task paths, pinned to that Node. Incus provides local capacity; [UpCloud](#upcloud-overflow) provides optional overflow.

The host's image, instance-name, and host-wide resource inventories use the typed agent commands `incus.images`, `incus.instances`, and `incus.inventory`. Rust validates complete Incus output and returns fingerprints, aliases, and resource names. Instance-name reads first prove that the configured project exists; a missing project cannot count as an empty host. Host-wide inventory includes every project and storage pool so the Gateway can compare it with recorded ownership. These reads take no mutation lock. Invalid or incomplete output refuses with `task_vm.invalid_host_output`; a failed Incus call returns `task_vm.host_command_failed`.

`task-vms:prepare-host` sends the configured project, bridge, subnet, pool, and optional ZFS dataset to `incus.prepare-host`. The command validates existing storage, project network scope, and bridge address before changing them. It refuses to create a pool on a dataset that already exists. Rust owns the ACL, isolated profile NIC, project restrictions, stock image copy, and UFW forwarding rule. Repeated calls converge the same resources under the host and firewall locks. A setup failure returns `task_vm.setup_failed`; it does not claim to undo a partly completed setup.

`incus.volumes` reads the custom volumes in the configured project and pool. Swamp owns volume changes through keyed Operation children; the Gateway keeps volume ownership and inventory reads.

`incus.vm-state` returns one VM's running state, explicit `settled: true`, guest bridge address, and original image fingerprint. It refuses a VM unless Incus reports `Running` or `Stopped`.

`incus.vm` creates, starts, stops, or deletes that VM under one target lock. The agent owns exact-name matching, Incus output validation, clean shutdown followed by forced stop, and the observation after a failed mutation. Create intent supplies the Gateway public key, image claim, resource limits, and topology requirement. The agent renders the fixed cloud-init account and QEMU configuration, clones a required seed before launch, and refuses an existing VM with a different claimed image. Delete removes the VM before releasing its seed clone. The Gateway keeps placement and resource-size policy.

Image builders and smoke VMs use the same `incus.vm` boundary. Their `create_image` intent selects the source image, account key, resource limits, and builder account mode. Rust renders cloud-init, attaches an Incus cloud-init configuration disk, and initializes the VM with NIC isolation. Before its first start, Rust removes the source image’s NoCloud seed templates. The disk supplies the account configuration at power-on, so cloud-init cannot run with an empty datasource while the Incus agent copies seed files and requests a reboot. This applies to stock builders, smoke VMs, and task VMs.

A stopped VM receives the same metadata preparation before it starts. A repeat preserves a VM from the requested image and refuses a different image. `remove_instance` removes an exact VM without changing storage volumes; the Gateway's existing image and orphan sweep decides which names it may remove. An absent VM counts as removed.

Before enrollment, guest commands use the same command runner, delivery manifest, and mismatch handling as managed Nodes. Incus carries them through its host's pinned transport with `incus exec`; UpCloud carries them through the guest's pinned SSH connection. The Incus adapter refuses source Git credentials before contacting the host, including for a builder with no task VM record. The transport stages only the pinned agent candidate. A builder has no Node row: an installation refusal fails its build, and never records a failed pin on the Incus host or creates a Node record.

`incus.instance-images` reads the original image fingerprint of each instance.

The image builder verifies the guest account before publication. Cloud-init uses the configuration disk on its first boot; publication needs no guest service ordering drop-in.

The step that creates the guest account on a VM (`bootstrap-account`) and the step that deletes an unused task VM image (`delete` in the image reaper) cannot run twice. They declare `writes: true`, so a lost dispatch fails the step instead of running `useradd` or `incus delete` again ([Operations](/reference/operations)).

`guest.cloud-init` returns readiness from the guest’s complete cloud-init result and reports bootstrap errors as typed refusals. `guest.fingerprint` reads and validates the ed25519 host fingerprint through the trusted guest transport. Image builders install command mode before their first readiness check. A guest transport that cannot yet return output remains pending within the existing boot deadline; malformed or refused agent results fail the build. Incus smoke checks also prove a host key and one completed boot before promotion.

Before an image builder can install command mode, its system bus must be available. Incus can reach a guest earlier in boot. The native supervisor returns `agent.supervisor_not_ready` for an absent bus socket; image boot polling waits within its deadline. This refusal records no failed agent pin. Other installation refusals still fail the build.

Before an UpCloud template is published, the `clean` phase of the task VM role (`swamp/ansible/tasks/task-vm-clean.yml`) refuses a server that was enrolled, which has an agent secret or a WireGuard configuration. It masks the first-boot services, stops Caddy, Docker and containerd and removes their identities, then allows only public SSH in the firewall. It empties the journal, logs, temporary files and shell histories, cleans the cloud-init state and the machine id, and removes the SSH host keys and the authorized keys. Incus state stays on the image.

An Incus build cleans its VM with the `task-vm-image-clean` workflow, which removes the nested guests and network, stops the services, deletes the agent, WireGuard and Pi identities, and resets the firewall. An enrolled Node cannot become a base image.


### From claim to workspace

A claim records placement and one native provision intent. The [native root](/reference/task-vm-native-provision) owns provider creation, enrollment and runtime preparation.

1. Placement records the VM's [size](#size-of-a-task-vm) and immutable recipe image. Ordinary VM admission records `task_vm.provision` in the same transaction. An owning Task parent uses `capture` and composes the native steps.
2. Swamp creates the provider VM from that image on the admitted host. Incus cloud-init and provider commands run directly from native system steps.
3. The host prepares the guest and records its public SSH identity and WireGuard key through the root's running projection.
4. Native steps publish the network from Gateway-rendered files with no private key, and install the exact `orbit-node-N` worker. They verify that Node identity before runtime preparation.
5. The shared router behavior issues the model key under its native lock. The guest prepares Pi through the Process lower. Confirmed native success marks the VM ready.
6. On the next claim, Orbit creates the [task workspace](/reference/tasks#execution-ownership) on the VM's Node. It is a normal Instance `task-<group id>`. A routed workspace gets the private Route `task-<id>.<project>.<dev-tld>`.
7. The claim starts the baseline check. Then the same claim projects the Route.

#### Start the baseline check before the Route

The baseline check needs the checkout, its `.env`, and the PHP packages of the Instance's PHP version. It does not need the Route: Pest's browser tests serve the application from their own server.

So a routed workspace on a task VM stops before its Route. After the source, workspace creation runs these steps, in about 1.5 seconds with the current base image:

1. It classifies the source and records the PHP version.
2. It writes `APP_URL`, and for a new `.env` the application key and name, as it does for every development Instance.
3. It installs the `app-dev` PHP packages of that version. On a task VM they are in the [base image](#build-the-base-image), so the step only checks them.

The workspace stays `source_resolved`, and its Route stays `pending`. The claim attaches it to the group and starts the baseline check. Then, in the same claim, it projects the Route as Instance creation does: the certificates, Caddy, PHP-FPM, the Router, and DNS, under the projection lock. The workspace becomes `active`.

The setup steps of the check can change files while the Route grants Caddy access to the checkout: `vp build` rewrites the build output, and Composer republishes assets. So the access walk ignores an entry that vanishes during the walk, and fails on any other error. It sets each directory's default ACL before its access ACLs, so a file that setup creates during or after the walk inherits the right entry: readable by Caddy in the Web root, denied elsewhere.

A Route that fails at the claim logs `The task workspace Route failed after the baseline check started; Orbit tries again before the implementer starts.` Work that uses the Route waits for it: before the first implementer or the final review starts, Orbit tries the Route again. Each failure there counts as a communication failure. After the fifth, the subtask asks for assistance with `The task workspace Route could not be finished, so no agent started.` and the error. So no agent, reviewer, preview, or pull request ever uses a workspace whose Route failed. Cancelling the group removes the workspace with its pending Route.

`AllocateTaskVmAction` [places](#place-task-vms) the VM on a host. The row gets the name `tvm-<row id>`, the next free WireGuard address in the reserved range, and a random Pi token. It also records `claimed_at`, the moment of the claim.

Cloud-init creates the user `orbit` with passwordless sudo and the Gateway's SSH key. The base image already put `orbit` in `incus-admin`, so a [nested topology](#prepare-the-topology) needs no new login. Cloud-init does nothing else, because the base image already holds Incus, `openssh-server`, Chromium's system libraries, the workspace tools, the software of the `app-dev` role, the pinned Rust toolchain, the Node agent, and Pi. Enrollment trusts the recipe image for machine software. The Project's setup steps install the rest, for example its Playwright browsers.

Provisioning and enrollment retain their synchronous jobs until the owning Node and Process child composition is installed. The Gateway also exposes bounded read views for the prepared Swamp workflows: power, address, image identity, cloud-init and runtime readiness. Enrollment allows ten minutes for boot, measured from launch.

Enrollment continues when cloud-init reports `done` with no errors and the host reports the guest's bridge address. It reads the guest values only then, before any code but cloud-init has run in the VM. A guest reboot shows the VM stopped for about a second, so a stopped VM gets a second reading 5 seconds later before enrollment fails.

Provisioning links the new Node to the row when it creates the Node record, before any convergence. So the Node is a task VM Node from the start: the fleet rollout skips it, and it gets no Orbit CLI. It never hosts a [Metrics exporter](/reference/metrics#exporter-selection), because the [hub filter](#limit-fleet-traffic-on-the-hub) keeps the Metrics Node out. So its enrollment skips the fleet Metrics reconcile, which converges exporters on every Node and took about 80 seconds on the live fleet.

Each step of the jobs logs one line `Task VM step done.` in the Gateway log, with the VM name, the step, and its seconds. The steps are `create`, which counts from the claim, `boot`, `node-converge`, `apt-tool-manager`, `roles`, `router-lan-ingress`, `dns-selection`, `activate`, `agent`, `enrolled`, `model-key`, `pi-current` or `pi-upload`, `pi-files`, and `pi-server`. The last line, `claim-to-ready`, has the seconds from the claim to `ready`. The workspace creation then logs `workspace-package-cache`, `workspace-source`, `workspace-prepare` for a routed workspace, and `workspace`, which has the seconds of the whole creation. The baseline check starts after `workspace`. The Route then logs `workspace-route`, with the seconds of its projection, which runs while the check runs.

Pi preparation runs on 209 with pinned official SSH, and no script reaches the guest. Swamp copies the pinned Pi build from the Gateway to 209 and checks its SHA-256 there; a different build stops before the guest. One command on the guest checks the digest again and installs it as `/home/orbit/.local/bin/pi-server` (0755).

Each file then arrives on stdin of one `install` command: the Orbit root, `models.json` and the token (0600, owned by the managed account), and the `pi-server` unit. The Gateway renders the whole `models.json` with the VM's model key as one vault value, `task-vm-pi-models-<id>`, because Swamp passes a secret only whole. One command reloads systemd and enables and restarts the unit; `systemctl is-active` and one `curl` to Pi's capabilities, with the bearer read on the guest, are the end checks.

The Gateway makes the guest's agent secret when it admits the enrollment, as it does for `node:add`, and keeps only the SHA-256 of the 64 hex characters. The vault copy `agent-secret-node-<id>` arrives on stdin of one `install` command that writes `/etc/orbit/agent/secret` (0600, root) with no trailing newline. The `observer-identity` step hashes the file and the run stops with `agent.secret_mismatch` unless it equals the Gateway's value. The agent sends the secret and the Gateway compares its hash, so the file and the record must describe the same bytes.

The guest firewall denies incoming traffic by default. The SSH preparation step allows TCP 22 from 209, and TCP 3774, Pi's port, from 209 (`orbit:task-vm-pi-209`) and from the Gateway (`orbit:task-vm-pi-gateway`), on the `orbit` interface, and TCP 443 for a routed workspace site (`orbit:task-vm-site`), before it enables ufw. The hub filter lets the Cluster Routers and 209 (`orbit:task-vm-control-web`, for the Route's TLS probe) reach that port. Resume writes the same rules again. Without them every turn fails at `session_create` with curl exit 28.

The official cfgmgmt behaviors are not used here: they trust the worker's shared `known_hosts`, and task VMs reuse addresses. The Gateway retains the model-key record and creates the normal `pi-server` Process. Resume checks `agent.status` before requesting Pi capabilities.

#### Skip what the image holds

Task VM enrollment trusts the [base image](#build-the-base-image) that the same Gateway code built. It does not run the `app-dev` software programs: `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, and `composer-manager`. It does not read or compare program fingerprints. Every other Node runs every program at enrollment.

Enrollment still renders and applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi.

New claims [use the deployed recipe](#claims-use-the-current-recipe), or a recent [fallback image](#image-fallback). The base includes both selectable PHP versions, now `8.5` and `8.4`, and Rust with Cargo, clippy, and rustfmt. The Instance's [PHP runtime check](/reference/php-runtime#development-runtime) verifies installed packages without repeating an installation.

Enrollment uses the host as [SSH jump host](/reference/node-provisioning#enroll-through-a-jump-host) until WireGuard is up.

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

### Destroy a task VM

An ended group is `completed`, `failed`, or `cancelled`. Orbit removes its workspace with the VM through the same cleanup path for all three statuses. The checkout lives on the VM's disk, so Orbit deletes no checkout in the guest. Before removal, the cancel run pushes the latest approved, unpublished commit from the running VM to origin under the shared [removal rule](/reference/tasks#cancel-a-stuck-task). A failed push keeps the workspace and VM. A parked VM cannot push, so cancel refuses until the work is published. A merged task is never pushed during cleanup.

A parked VM starts before preservation is attempted. Pushes, fetches, checks, and agent turns need the VM `ready`, so they finish before the VM is deleted. Workspace removal on a task VM runs these steps:

1. While the VM is `ready`, it runs the Project's teardown steps in the guest. A removal that an earlier attempt started has run them already.
2. It sets the row to `destroying`, revokes the group's model key, and deletes the VM and its volumes.
3. It removes the workspace records offline, as for [a VM that died](#a-vm-that-dies-before-its-group-ends).
4. It queues `DestroyTaskVm`, which removes the Node.

A step that fails keeps the Instance and records the error on the row. The group asks for assistance with the removal reason, and the next removal attempt continues. A group's topology runs in its VM, so it goes with the VM, and removal runs no topology release.

Explicit completion, cancellation and workspace removal queue `DestroyTaskVm`. Swamp owns retries and cleanup scheduling. `DestroyTaskVm` then runs these steps:

1. It sets the row to `destroying` and revokes the group's model key.
2. It deletes the VM with `incus delete --force`, then the VM's [clone of the topology seed](#incus-provider). Each delete is one pinned `@swamp/ssh` `exec` argument list on the host.
3. It removes the Node offline and with force. This sheds its `app-dev` role and removes its Process rows, WireGuard peer, and private DNS records.
4. It reads the project's instances and the pool's custom volumes again. A native `assert` checks that neither lists the VM or its seed clone.
5. It sets the row to `destroyed`.

Incus keeps custom volumes after VM deletion, so the seed clone has its own delete. A VM or a volume that is gone counts as deleted. On UpCloud, the run reads the server, stops it, and deletes it with its storage instead of steps 2 and 4.

Orbit deleted the VM itself, so the Node removal does not probe the Node's address first. It also runs no Metrics pass, because a task VM Node never hosts a Metrics exporter.

The run prints no result. On success, the Gateway builds the cleanup receipt from the intent it captured at admission. A failed run projects `task_vm.destroy_failed`. Tasks cancel and a failed provision run the same `task-vm-destroy` child workflow.

The removal of other Instances moves a checkout within one filesystem only. [Instance removal](/reference/instance-removal) refuses a checkout on a mounted volume with `cross-device:`.

Deletion clears the row's Pi token after the VM is gone. The Node goes before the row becomes `destroyed`, which also clears the token. A row that is not `destroyed` keeps its Node a task VM Node, so Orbit never sends the Gateway's own Pi token to it.

When cleanup fails, is interrupted or exceeds its budget, the row stays `destroying` with the error, and its guest Node becomes `failed` with `failed_step` `remove:operation` and the same `error_code`. The task VM sweep admits a `destroying` row without a group again; that admission claims the Node as `removing` again. When admission refuses one row, the sweep logs its code and still admits the other rows and sweeps the hosts. A `failed` guest Node is an ordinary member again, as for any failed Node removal: the hub keeps its peer until a later cleanup removes it. A failed Tasks cancel stays that way until the group is cancelled again.

A [parked](#park-and-resume) VM is deleted while it is stopped. Only its workspace removal starts it first.

Keep a host's `settings.task_vms` until all its task VMs are `destroyed`, because destroy needs the settings. Node removal refuses live task VMs and configured hosts. Clear the host setting after draining it. Removing a host also deletes its destroyed VM rows.

#### A VM that dies before its group ends

Workspace removal deletes a dead VM like a live one. Only the push of approved work and the Project's teardown steps need SSH to the VM. The run reads the VM's power first and skips teardown on a stopped VM. When a guest step fails, removal fails and the group asks for `removal_failure` assistance. An ownership refusal has the same kind. Runtime decisions use the open request and kind, not its reason text.

When the group has ended, or is `settling` or `waiting_for_review`, each tick then also queues `DestroyTaskVm` for that task VM. A group in another state, for example one whose cancel failed while the VM ran, keeps its workspace and its assistance request. Cancel it again: cancel then removes the workspace with the VM and queues `DestroyTaskVm`.

For a `ready` VM, the job reads the host to check whether it runs. While it runs, the job leaves the workspace to normal removal. When a reviewed, unpublished head needs preservation and the VM is still stopped 5 seconds after a stopped reading, the shared wake path parks the `ready` row and queues its start. Removal waits for the Node agent and Pi before it retries the push. A failed start stays `failed`; it never enters the wake path again.

A `ready` VM that is absent keeps its reviewed work until the host sweep records `failed` after two consecutive misses. The destroy job records this wait as `task_vm.awaiting_host_sweep` on the VM and owns its `removal_failure` assistance. Workspace removal waits under that reason before lease admission, so it makes no SSH snapshot or push attempt. Repeated ticks and removal sweeps leave the reason and Activity unchanged.

A later observation that finds the VM clears the wait so removal can retry. The Incus sweep runs only while task VMs are enabled and the host Node is active. If either is off, the reason names the blocker: task VMs are disabled, or the named host is inactive. A recorded wait needs no provider or host read while the sweep is paused. Cleanup waits until the sweep can run again. The destroy job does not make its own missing verdict.

A `failed` VM, whether missing or unable to start, cannot preserve its work. Orbit records the lost reviewed SHA and original VM failure once in the group's Activity and `removal_failure` assistance reason, then deletes the VM and removes the workspace offline. A failed deletion keeps that reason on retry; its delete error stays on the VM row. Removal never retries a lost head's push after destruction starts. A stopped VM without work to preserve is removed the same way:

- It records the normal removal journal, with the source steps marked done, because the checkout is gone with the VM.
- It clears the Route through the normal removal projector. The projector skips the VM's Node for a task VM that is `destroying`. The router withdraws the site.
- It deletes the Instance's Process and Schedule records and the Instance row.

Then it removes the Node and marks the row `destroyed`, as above. It clears the group's removal reason unless that reason records reviewed work that was lost. A removal journal that a normal removal left open is finished the same way. Unreviewed work is lost with the VM. A reviewed, unpublished head keeps a `ready` or `parked` VM while preservation runs. A `failed` VM is removed, and its assistance about the lost work stays visible.

### Place task VMs

Placement is serialized by the Gateway's SQLite transaction. The claim checks for an existing VM, counts occupied slots, records a new VM, and queues its creation after commit. `AllocateTaskVmAction` selects the active host with the most free slots. Hosts with equal capacity use their configuration order.

Every VM that is neither `destroyed` nor `failed` takes the slots of its size, including parked VMs. When no Incus host has room, eligible groups can use the [UpCloud overflow](#upcloud-overflow). Otherwise the group waits for capacity. Lowering `max_vms` removes no VM; the host receives no new VM until capacity is available.

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

### Shared package cache

Task workspace cache preparation belongs inside the native Task parent. The Gateway refuses the unported synchronous cache path with `operation.kind_unavailable`; it does not call an agent action or create a child Operation.

Each configured task VM host has one Gateway-owned package cache at `/var/lib/orbit/package-cache`. The Gateway reads hosts through `TaskVmSettings`; the cache does not select hosts by role or read the environment host list itself. Task VMs receive an Incus directory disk at `/opt/orbit/package-cache` with `readonly=true`. The restricted Incus project permits only this directory as a host disk source; host preparation and the first cache attach set that narrow exception. This host-enforced mount prevents a root agent in the guest from publishing bytes to another Project.

Swamp fills the host cache from Node 209 over official SSH with the host's recorded host key. Each step runs the pinned agent's `package-cache.host` as one argv, `timeout 1530 sudo -n -- orbit-agent command package-cache.host`. The agent validates archive identities, downloads approved HTTPS URLs with checked redirects, checks lockfile integrity, enforces the byte budget and atomically records verified files and the inventory. No script or TypeScript runs on the host, and the host needs no Swamp worker. Guest mounting and seeding require their native Task parent steps.

Image installer services use `UMask=0022`, matching recipe execution on Nodes. Tool directories and package state must remain readable by the guest. Rust and Cargo must also report their pinned versions. Guest wrappers use the compiled agent contract to identify mutations: smoke and recipe verification reads receive only their declared inputs; mutations retain keyed effect receipts. Builder, smoke and seed boot observations stay pending until their own agent installation child succeeds. Only then does the Gateway probe cloud-init; real probe failures remain errors.

Verification runs Node, pnpm, Bun, and Vite+ launchers only as `orbit` and compares their versions with inspection. Other guest-facing binaries must report the same version as root when run as `orbit`. Launcher caches remain owned by `orbit`. The cache-parent probe reads `/opt/orbit` as that user without writing to it.

Cache failures carry a typed code, operation, path, and bounded, sanitized reason. Seed failures identify the artifact key, package, and version and retain the underlying I/O cause, including archive extraction errors. Checking the mount marker preserves access errors instead of treating them as a missing mount.

Existing images and VMs retain their permissions. After an image-builder fix, use `task-vms:build-recipe --rebuild` to replace the images, then recreate affected VMs. Attaching the cache again does not repair an old VM.

When the default branch moves in the Gateway Git mirror, Orbit compares the blob IDs of `composer.lock`, `package-lock.json`, `bun.lock`, and `pnpm-lock.yaml`, at the root and below `apps/` and `packages/`. A lock contributes only when the same directory contains a regular `composer.json` for Composer or `package.json` for JavaScript. This excludes standalone example and test fixture locks.

An unchanged commit reuses its snapshot. Changed or removed lock blobs replace the Project's desired inventory, invalidate its recorded host inventory, and queue a fill on each configured host. A Project stays pending until every configured host has verified all its selected archives. Pending fills coalesce by host; workers drop duplicates that overlap a running fill. A fill clears pending only for the inventory it read. A lock change during a fill stays pending for the next mirror fetch, including at an unchanged commit.

Cache initialization, read-only mounting and guest seeding compose as steps of their owning native Task workflow. The Gateway refuses `task_vm.prepare_package_cache`; workspace setup does not admit a separate cache Operation.

The `builds` queue records one `task_vm.fill_package_cache` intent with the observed lockfile inventory. The intent holds at most 2 batches of 4,096 archives, so the requests and the replies fit the run's one terminal read; a larger inventory is refused with `task_vm.package_cache_invalid_result`.

At the run start the Gateway mints a GitHub installation token with metadata read access when a Composer archive needs one. The vault holds only the request's `github_token` member, as `task-vm-cache-fill-github-<node>`: the token, or null. Serve redacts every vault value it reads with a pattern built from that value, so vault values stay small. The Gateway accepts a token of up to 1,024 letters, digits, `_`, `-` and `.`, which covers current installation tokens of several hundred characters.

The workflow inputs hold each batch's public agent request up to that member, the batch numbers, the integrity conflicts, the host's SSH target and the vault key name. The step's stdin is the request, the vault member and the closing braces. The token never enters argv, environment variables, files, workflow inputs or run history.

One native Swamp run, `operation-task-vm-cache-fill`, runs every step on a `role: swamp` worker. Its `Fill the package cache` step sends the batches to the host one at a time, because the agent holds one lock on the cache. Its `Check every archive` step is a native assert: it passes only when every batch reply is `ok`, no archive was refused and admission found no integrity conflict. The run has a 2,100-second budget, so an unclaimed or stuck run fails instead of hanging.

The Gateway reads the run once at its end. It records the verified archives from every batch reply the run kept, even when the end check fails. A changed lockfile snapshot refuses stale inventory projection. A reply that names an archive outside its batch is refused.

Each artifact failure is recorded in the Gateway log with its cache key and host ID. The fill continues with the remaining artifacts and records successful archives in the host inventory before failing the Operation. Each admission job makes one attempt; the next mirror fetch retries pending Projects. A queue outage leaves source fetching usable.

Composer consumes native archive cache files. npm consumes its integrity-addressed content cache; Bun consumes extracted cache directories, including registry archives whose root directory uses the package name, as DefinitelyTyped archives do. Seed extraction sets archive directory entries to mode `0755` so archive modes cannot prevent the guest from extracting or using their contents. Regular files use mode `0644`, or `0755` when any archive execute bit is set. An unreadable file mode uses `0644`. Directory permission changes refuse symlinks.

The JavaScript adapter reads npm lock versions 2 and 3, Bun text locks, and pnpm package inventories. pnpm locks contribute to the shared JavaScript inventory, but pnpm's own store is not seeded. Bundled, Git, local, and private packages remain normal downloads.

Before workspace setup, Orbit copies the Project's missing shared cache files into the workspace's private manager caches. Task VM setup sets `COMPOSER_CACHE_DIR` to `$HOME/.cache/composer`, the same directory that receives the shared Composer archives, so a login shell uses the seeded cache. Metadata, locks, temporary files, and downloads on a miss stay private. The shared mount is always read-only; private copies use no hard links. A package added only on a task branch, an unsupported archive, or a fill not yet complete falls back to the manager's normal registry download.

Run `php artisan task-vms:package-cache-usage <package> <version>` on the Gateway to list the Projects that use that package and version, with the verified archive hashes and host IDs. Usage covers cacheable public artifacts in current default-branch locks, including development packages. Removing a dependency immediately removes its current Project usage from the query; the next fill replaces its host inventory with the archives it verified, while its bytes remain cached. An artifact failure does not prevent other Projects from gaining verified inventory rows.

The command returns one JSON object with `projects` (current Project IDs and names) and `cached` (verified Project, host, manager, and SHA-256 rows). An unknown package or version returns empty lists. A permanently bad artifact keeps its Project pending until its lock changes. Each failed fill raises an alert through the scheduled [problem collection](/reference/gateway-queue#scheduled-work).

Private registries and credentialed URLs are out of scope. Trusted fills accept only public GitHub commit archives for Composer and the public npm registry for JavaScript. GitHub redirects may use its repository-ID archive endpoint or codeload, but must retain the locked commit. The Gateway validates JavaScript checksum encoding and digest length when reading locks. Workspaces can use their own private registry credentials with normal downloads. The fill never reads a Project's registry credentials.

GitHub cache misses use a fresh GitHub App installation token with only `metadata: read` from a covered Project. The token travels through protected stdin and stays out of cache files, command arguments, and logs. The downloader sends it only to the allowed GitHub API and codeload archive hosts; npm receives no token.

Lockfile checksums are verified when supplied; npm integrity is required. Composer archives without a checksum use HTTPS from the allowed commit archive service and record a SHA-256 digest on first fill. This is a transport trust limit, not a lockfile-supplied content guarantee. Each reuse checks the recorded digest. Suspect bytes move into a quarantine directory and are downloaded again; they are not seeded into new workspaces.

Fills do not evict artifacts, including packages no current Project uses. The cache defaults to a 20 GiB byte budget per host and 512 MiB per archive. The operator can change the cache budget through `package_cache.max_bytes`. A full cache rejects new artifacts and leaves the fill pending; workspaces still download misses privately. Quarantine bytes count toward the budget. Retention and cleanup are operator work while fills are stopped; no automatic deletion can disrupt a running workspace. CI cache refs and previous image retention are separate policies.

### Clone and set up every workspace

Every new task workspace starts with an empty checkout on the VM's root disk at `<apps directory>/<project slug>/workspace`. A step on Node 209 clones the Project repository with full history and sends it to the VM as a Git bundle, so the agent reads code and history offline. The clone credential is minted for that step when it runs and never reaches the VM ([GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)). The workspace checks out its task branch, then the baseline check runs all [Project setup steps](/reference/instance-setup), every time. Each claim runs Project setup.

After source resolution has observed the default-branch lockfiles, the Gateway attaches the [read-only package cache](#shared-package-cache) and seeds private manager caches. The `@orbit/ci-caches` setup step imports the selected [CI cache ref](/reference/instance-setup#import-ci-main-caches). Missing packages download into private caches; a missing or invalid CI ref leaves checks cold. Neither miss queues a workspace image build. The fixed checkout path preserves cache paths across claims without sharing a writable checkout.

A task VM has no default checkout and no dependency trees in its recipe image, so `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` are empty. The Project's `seed-dependencies` step therefore skips its reflink copy by design. Fresh task VMs reuse verified Composer archives to build `vendor/`, including `apps/cli/vendor`, without downloading those packages again.

For private dependencies, the workspace step writes the Composer and npm configuration of the task account before the Project setup steps run. The configuration holds a GitHub App installation token with only `contents: read`, which reads the private packages in the repositories that the installation covers. The token is minted for these steps and expires after one hour.

The check stops each setup step's process group when its shell exits, times out, or is cancelled. Setup steps must finish their work before returning. Long-lived services belong in [Processes](/reference/processes-and-schedules), not setup background jobs. See the [baseline check](/reference/tasks#baseline-check) for output cleanup and timeout behavior.

The configuration is two files with mode 0600: `~/.config/composer/auth.json` and `~/.npmrc`. Composer gets the token as `http-basic` credentials for `github.com` (user `x-access-token`), not as `github-oauth`. Composer refuses an OAuth token with a character outside letters, digits, dots and underscores, and the installation token has more.

The token reads every repository the App installation covers, because a private package can live in any of them. A setup command is repository code and can read the same repositories while the files exist. The Gateway revokes the token itself if it cannot store one of the three vault copies.

A later step removes both files after the last setup step, on success and on failure. A failed removal fails the provision. The same step revokes the token, so a setup command that echoes it leaves a token that is already revoked. The setup output is redacted for GitHub tokens, dots and dashes included, before it is kept. A cancelled or timed-out run keeps the files until the token expires or `tasks:cancel` removes the VM. The Project check and deliverable commands run after that and find no token.

The provision result names what became of the token in `dependency_token`. `revoked` means GitHub answered 204. `none` means GitHub answered 401: no token was minted, as for a public repository that the App does not cover, or it had already expired or been revoked. `unrevoked` carries a `reason` (GitHub answered another status, the request did not complete, or the revoke step could not run), and the token may stay valid for up to an hour.

An `unrevoked` token does not fail the provision: the files are already gone from the VM, and failing would not shorten the hour. The result says why instead. A provision that runs no setup step writes, removes and revokes nothing, and reports `none`.

If the App cannot supply a token, both files hold an empty configuration and setup continues without private access. Shared fills retain existing verified GitHub archives and skip uncached GitHub artifacts without authentication, while other packages still fill.

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

A source command fails with `task_vm.git_credential_refused` before SSH starts. An `incus` command on a task VM host refuses one too. So an Instance or Project operation that would fetch on a task VM with a token fails there and passes no token. Examples are `instance:destroy` of a task VM workspace and `instance:clone` from one. `project:update` with a new repository URL sends no token: it reads the proposed repository on the Gateway and only sets `origin` on the VM. A task VM workspace goes with its group; see [Destroy a task VM](#destroy-a-task-vm).

The guard knows a credential by the mark that `GitReadScript` puts on its input. A test fails when other code builds the credential header. It also fails when a new file sends remote commands with GitHub access in reach, so each such path gets a review.

The checkout keeps the Project repository URL as `origin`. After setup ends, the agent can run Git there, but its environment holds no setup credential. So it cannot fetch from or push to a private repository.

### Converge a running task VM

`php artisan task-vms:converge VM` brings a ready task VM to the pins of the release. It records the `task_vm.converge` Operation on the VM's guest Node. Swamp runs the three phases of the task VM role playbook (`swamp/ansible/task-vm-role.yml`) from Node 209 over SSH: `software`, `tools` and `check`. The [image build](#build-the-base-image) runs the same playbook on its builder, so an image and a converged VM end in the same state.

The Gateway puts these in the Operation:

- The `recipe.json` of the release and the Vite+ lock.
- The VM's SSH target with its recorded host key.
- The Pi server digest and its place on the Gateway host.

No task file names a version.

The `software` phase installs Ubuntu packages and file packages at their pins. It also installs `python3-venv` and `jq` from the same snapshot, which the shape gate needs. They come from the pinned Ubuntu snapshot, through their own apt configuration, with the artifact mirror first. The VM's apt sources stay as they are. Node, pnpm, Bun, Vite+, Rust, the Swamp binary and the Pi server follow. A tool that already shows its pinned version is left alone, so a VM at its pins reports no change.

The `tools` phase installs the shape gate linters. The nested fleet is Incus system containers that take the checkout as a disk device, so the VM serves nothing to them.

- With `--nested-subnet` and `--nested-interface` (default `nested`), the VM leads the nested network of that subnet.
- UFW then allows DHCP, DNS and the package cache from the nested bridge, and routes it out.
- Without those options Incus keeps the network it has.
- The linters build into `/opt/orbit-shape-gate`, named by the digest of the pins in `swamp/ansible/shape-gate-requirements.txt` and `swamp/ansible/requirements.txt`.
- `bin/swamp-shape-gate` finds the folder by that name. The handoff check then runs offline.

The `check` phase is the one end check. It asserts every package at its pin, every runtime and tool at its version, and the package cache active.

The `check` phase also asserts that `orbit` has UID and GID 1000. The `template` phase asserts that ufw is active, denies incoming connections by default and has the recovery rule; the smoke of an UpCloud template runs it after `check`.

The `clean` phase follows an image build and nothing else; it does nothing unless `intent.image` is set. The UpCloud template build runs it. The Incus build cleans through its own child workflow.

The phase refuses a server that was enrolled. It masks the services of a first boot and turns ufw on with only the recovery SSH rule open (`orbit:public-ssh-recovery`). It empties the journal, the logs, the temporary files (not the ones of Ansible itself) and the shell histories.

It ends with `cloud-init clean`, the removal of the SSH host keys and the emptying of the authorized keys. The Ansible session stays open through them. The state of Incus stays in the image.

The VM must be `ready` with an active guest Node (`task_vm.converge_refused`). No other Operation may hold it (`operation.target_busy`). The Operation has a provisional budget of 3,300 seconds. A failed phase leaves finished work in place. Run the command again and each phase skips what matches.

### Park and resume

Swamp requests review parking through the keyed `tasks.park` operation. The Gateway checks that the published group has no open work, assistance or workspace owner and records an immutable park intent. One native Swamp root stops and observes the admitted VM on its exact Incus host or Gateway Node for UpCloud. The result projection records `waiting_for_review` only after stopped power is confirmed.

It does not run automatic idle parking or retry a failed park. Swamp owns retry policy and execution-slot accounting. Parking has one dispatch path: the native `tasks.park` root replaces its PHP stop executor and the unused internal `task_vm.park` parent and `task_vm.confirm_stopped` child. The same root owns soft-stop, hard-stop and observation; it starts no child Operations.

The row enters `parked` with `stop_confirmed_at` only after the native root confirms settled, stopped power. Admission and cancellation leave a running VM `ready`. A failed stop clears the proof and retains the error. A resource observation job refreshes proof every minute for parked VMs in every group status, including assistance and ended groups. Running, missing, unsettled or unreadable power clears the proof. Observation never starts or stops a VM.

A requested workspace operation admits `task_vm.resume`. It clears stop proof and starts one native run. On an Incus host the run starts the VM, and a VM that already runs counts as started. Incus's own agent wait follows, bounded to 120 seconds. On UpCloud the provider-power child starts the server. A `ready` VM that a workspace operation found stopped also gets 209's authorized key, its TCP 22 rule and `sshd` back, through `incus exec` on the host.

The run then reads `orbit-agent` and the Pi capabilities over the guest's recorded host key pin, and a native `assert` checks both. A guest without a recorded host key is refused at admission. The run prints no result: a succeeded run records `ready`, and a failed run projects `task_vm.resume_failed`, which fails a parked VM with its error evidence.

Parking retains the disk, workspace and sessions. It releases CPU and memory. It retains the provider's allocated VM count; Swamp's maintenance execution slots are separate.

### Sweep task VM hosts

The Gateway scheduler queues `SweepTaskVmHosts` every thirty minutes. It runs while task VMs are enabled or the [UpCloud](#sweep-upcloud) token file is set. While task VMs are enabled, the sweep admits an Operation for each active configured host. `task_vm.sweep_host` runs on Node209 and reaches each Incus host through official pinned SSH. The Gateway supplies complete public ownership intent and vault descriptors before dispatch. Swamp reads provider inventory, selects only the admitted cleanup scope and returns its final observation; executing steps do not read Gateway state.

One workflow serves both providers. A `guard` on each provider's first step skips the other provider, and its later steps skip with it. The run reads the inventory, plans in TypeScript on Node209, removes the plan with parallel `forEach` steps, reads the inventory again and ends with a native `assert` that no planned name is still listed.

No step ships a script: each Incus command is one official pinned `@swamp/ssh` `exec` argument list with `sudo` and a literal `timeout`, and each UpCloud request is one inline `curl` on Node209 that takes the token through `--variable`, never argv.

The workflow has no format or result step: the Gateway reads the plan step's names once the provider's end check succeeded.

A provider or SSH failure never supplies a missing-VM observation and projects `task_vm.host_command_failed`. Cancellation remains terminal; an interrupted run projects failed. The generic Operation lock serializes admissions per host and releases on terminal projection.

The sweep workflow has a 60-second worker queue timeout. Its run budget is 600 seconds, allowing for the 180-second Incus deletion timeout.

The native run reads the images of the host's Incus project with `incus query /1.0/images?project=<project>`, which fails for a project that does not exist. Incus lists the instances of a missing project as empty, so this read makes a missing project fail the host, and the job changes nothing there. The run then compares the instance names with the task VM rows:

- An **orphaned VM** is an instance named `tvm-<number>` in the project that has no task VM row on that host which is not `destroyed`. The job deletes it with `incus delete --force`.
- A **missing VM** is a `ready` or `parked` task VM row on that host whose instance is gone.
- An **orphaned volume** is a custom volume `tvm-<number>-topology-seed` in the project's pool whose VM is an orphan that is absent or deleted in the same run. The job deletes it after the orphaned VMs.

The sweep fails a missing VM only when two distinct host Operations in a row miss it. Replaying one native root or its projected result never counts as a second miss. The first miss is kept for an hour, and a sweep that finds the VM forgets it. On the second miss, the job sets the row to `failed` with `task_vm.vm_missing`. The group asks for assistance with the reason `Task VM failed:`.

Cancel or complete the group of a missing VM to destroy its Node, as for [a VM that dies before its group ends](#a-vm-that-dies-before-its-group-ends).

The job changes only the host's configured Incus project, such as `orbit-tasks`. It reads the other projects only to [report strays](#report-strays).

A successful Incus sweep releases a failed build's cleanup claim only after its final observation contains none of that build's builder, attempt or smoke names. Failed, cancelled or incomplete sweeps keep the claim. The failed build can then start a fresh attempt through normal resume or recipe retry.

It retains a base image builder `tvm-image-<build id>` and its smoke VM while the build row on that host is `running`, whatever its age, or is an unclaimed `failed` build updated within the last 24 hours. Admission records retirement of expired nonrunning builds before dispatch, using the same conditional update as resume. The native run deletes only those admitted builders. Captured maximum VM and build IDs exclude resources allocated after admission. The sweep preserves live VMs, running builds and names that the operator owns. An absent build row permits cleanup only within those captured ID limits.

The sweep never changes another project, an instance outside the task VM and base image builder names. Rows in `provisioning`, `failed`, or `destroying` are never missing: their own jobs check the VM.

[Doctor](/cli/doctor) reports the VM findings in the `node` family of the host, without changes. The sweep deletes orphaned volumes. Doctor's codes are `node.task_vm_orphaned` for an orphaned VM and `node.task_vm_missing` for a missing one. Doctor reports a missing VM on its first miss. A host whose project does not exist or cannot be listed reports `node.task_vm_inspection_failed`. Doctor checks a host only while it has `settings.task_vms` and is reachable over SSH. The fleet rollout ignores these codes.

Doctor does not inspect the Node of a `parked` task VM, because the VM is stopped on purpose. Its `node` family reports one informational `node.task_vm_parked`, and the other families check nothing. So no problem is filed for a parked VM.

### Report strays

The sweep and Doctor also report what Orbit does not own on a task VM host, and the records that should be gone. Doctor never changes any of it. The sweep deletes base image builders and their smoke VMs only when they are eligible for cleanup, as described above; recent unclaimed failed builds are retained for resume. For every other stray, neither tool changes it: the operator decides. The native sweep logs one warning per stray on every run, and Doctor reports one finding per stray in the host's `node` family.

A **stray instance** is an Incus instance in any project of the host that is none of these:

- a task VM `tvm-<number>` in the host's project, which the [sweep](#sweep-task-vm-hosts) checks against its row;
- a retained base image builder `tvm-image-<build id>` or smoke VM in the host's project (see [the sweep rules](#sweep-task-vm-hosts));
- an entry of the host's `operator_owned` list.

A **stray volume** is an Incus custom volume in any project and pool of the host that is none of these:

- a task VM's clone of the topology seed, `tvm-<number>-topology-seed`, in the host's project and pool;
- the [topology seed](#build-the-base-image) `orbit-topology-seed-<12 hex>` of an image that the host's project keeps, in its project and pool;
- a seed under construction of such an image: `-new`;
- an entry of the host's `operator_owned` list.

The report lists every project with `incus query /1.0/instances?all-projects=true`, and the custom volumes of every storage pool with `incus query /1.0/storage-pools/<pool>/volumes/custom?all-projects=true`. The sweep reads only the custom volumes of the host's configured pool, so it logs stray volumes of that pool; Doctor reports every pool. It reads the images of the host's project only to recognize their topology seeds. Images, networks, and profiles are not part of it. A host whose list fails reports `node.task_vm_inspection_failed`, and the sweep fails that host.

A **stray record** is a `ready` or `parked` task VM row on the host with no group, or whose group has ended, for more than one hour. The hour counts from the later change of the row and its group. Normally the [destroy of ended VMs](#destroy-a-task-vm) removes such a VM within minutes, so a stray record means that destroy is stuck.

A **stray Node** is a Node named `tvm-<number>` that has no task VM row that is not `destroyed`, 15 minutes after the Node was created. Enrollment creates the Node and links it to its row in the same job, so 15 minutes covers a slow enrollment. Doctor reports it in the Node's own `node` family, also while the Node is unreachable. The sweep logs it while task VMs are enabled.

| Code | Kind | Meaning |
| --- | --- | --- |
| `node.task_vm_stray_instance` | drift | A stray instance, observed as `<project>/<name>` |
| `node.task_vm_stray_volume` | drift | A stray volume, observed as `<project>/<pool>/<name>` |
| `node.task_vm_stray_record` | drift | A stray record, observed as the task VM name and its state |
| `node.task_vm_stray_node` | drift | A stray Node |

To clear a finding, delete the stray after you check it, or add an operator-owned guest to `operator_owned`. For a stray record, cancel or complete its group, or destroy the VM. For a stray Node, remove the Node with `orbit node:remove`.

### Reap image builders

The `task-vm-image-reaper` schedule runs every thirty minutes on Node209. It deletes a base image builder or smoke VM that a killed build leaves behind. `ImageReaper` publishes the schedule's inputs: each active configured host with a pinned SSH host key, its project, pool and `operator_owned` list, its pinned SSH target, and the build ids of its `task_vm.image_build` Operations that are `queued`, `running` or `waiting`. It publishes again when such an Operation starts or ends, and after each run. Steps never read the Gateway.

For each host, one official `@swamp/ssh` `exec` lists the project's instances. A TypeScript step on Node209 picks each VM that matches all of these:

- its name is `tvm-image-<build id>`, with an optional `-attempt-<n>` and an optional `-builder` or `-smoke`;
- it is a `virtual-machine` in state `Running` or `Stopped`;
- it has one root disk, on the host's pool;
- it is older than two hours;
- no entry of `operator_owned` names it or reserves the whole project;
- its build id is not one of the host's published active builds.

One `exec` per picked VM runs `incus delete --force`. One `exec` per host then lists the pool's storage volumes, and a last TypeScript step checks that no root volume or snapshot of a deleted VM remains. A host whose list fails, a failed delete, or a remaining volume fails the run; the other hosts and VMs continue. The run's own history records the deleted and failed names. The Gateway keeps no deletion record.

### States

The `TaskVmState` enum has six cases.

| State | Meaning |
| --- | --- |
| `provisioning` | Orbit is creating, enrolling, or preparing the VM |
| `ready` | Pi runs, and Orbit can create the workspace |
| `parked` | Orbit stopped the VM, or is starting it again. The disk stays. See [Park and resume](#park-and-resume) |
| `failed` | A job failed. The row keeps `error_code` and `error_message`. The group shows the error as its reason and asks for assistance |
| `destroying` | Cleanup has started |
| `destroyed` | The VM, the Node, and the model key are gone. The row stays for audit |

Orbit derives progress inside `provisioning` from facts: the VM exists, the row has a Node, the Node is active, and the Pi Process exists. A group has at most one task VM that is not `destroyed`. `failed` is final for the row. Cancel the group to destroy the VM.

### Jobs

The Gateway jobs `FillTaskVmPackageCache`, `SweepTaskVmHosts`, `RefreshTaskVmBaseImages` and `RefreshUpCloudTaskVmBaseImage` validate and record intent. Swamp executes their effects. Each admission job has one attempt and a one-minute timeout.

Provisioning, enrollment, runtime preparation, resume, destruction, parking and internal removal keep their existing synchronous implementations and tests. Their replacement Operations remain inert until their parents declare the required `node.add`, `node.remove` and Process children and those owning admissions are registered. Merely registering a Node or Process kind does not activate an incomplete parent. Admission refuses with `operation.kind_unavailable` before it records intent or changes the VM.

Runtime admission records the fixed Pi Process definition and its ID without installing or starting it. Swamp requests the owning `process.create` child with only `{process_id}`, then waits for its proven completion before checking runtime readiness. An existing Process with another definition refuses admission. Runtime admission stays guarded until the owning Node enrollment kind is installed; retained runtime jobs remain synchronous until their cutover.

Enrollment preparation validates the booted VM and records its reserved Node ID with the exact internal `ProvisionNodeData` input. Swamp requests the direct `node.add` child from that stored descriptor; it cannot select a Node or expand the input. An already active VM Node needs only the recorded preparation and runtime proofs. Destruction proves provider deletion and Route removal, clears workspace records, then requests the exact `node.remove` child before final VM cleanup. The recorded Node identity remains available after deletion for receipt verification. These parent plans do not switch retained callers; activation waits for the owning Gateway and Swamp releases.

Before the later lifecycle cutover, destruction must also gain a cancel guard after the proven provider-delete boundary so cancellation cannot abandon record cleanup.

Every registered effect fixes its Node, command and input. The Gateway resolves declared secret references after authorization; tokens, private bootstrap keys and cloud-init credentials never reach Swamp or receipts. Reads remain Services-only Gateway APIs. Successful completion requires proven command or child receipts. Error codes use lowercase letters, digits, dots, underscores and hyphens.

A parent yields its worker lease while its stamped child Operations run. Native effects carry the shared target receipt metadata, so recovery reads the original sanitized result before replay. Gateway projections accept only exact owning child proof; they never admit another Operation and wait for it. Package cache fills require list-result enforcement.

Image builds have one permanent Operation per attempt. When reconciliation finds a Running build without a matching Swamp Operation, it increments the attempt, assigns a new builder name, clears saved image and step state, and starts from stock. It never adopts PHP phase progress as Swamp proof. Repeated reconciliation keeps the new Operation and attempt.

Swamp checkpoints reset, launch, boot, the six recipe phases, cleanup, publication, smoke, topology seed, promotion and pruning. A lost reply reuses its saved child identity. A native phase keeps its boot and invocation identity; only a later explicit Incus resume may restart a proven failed invocation. Unknown observations fail closed.

Successful publication alone cannot move the base alias: smoke verification and a finished topology seed must have successful Operation receipts. UpCloud uses the same base recipe, verifies the firewall on its smoke server, and promotes only its own unverified template. Older templates stay while pinned by allocations; pruning keeps the newest two verified templates.

A failed recipe build does not retry because the Gateway restarts or releases code. Run `task-vms:build-recipe --retry` after fixing the cause. An Incus build with retained resources can resume its failed stage. A failed prune resumes without rebuilding or moving the alias again. A newer recipe generation fences every subsequent effect and promotion of the older build. The Gateway retains build state, validation and immutable recipe intent; Swamp owns ordering, waits and recovery.

Host sweeps use independent Operations, so one failed host does not block another host. The Gateway reads ownership and reports strays. Swamp deletes only the admitted owned orphans. Incus uses official SSH from 209; UpCloud uses the existing provider API helper and a shared vault execution copy. Active and recent failed builders remain protected.

Admission captures live VM names and the highest allocated VM and build IDs before native inventory. New allocations remain outside that cleanup scope. Native cleanup removes VM instances before selecting their orphan topology volumes, and verifies VM absence before deleting a volume. Cleanup and resume compete for the same build fence: cleanup blocks resume, and a successful resume prevents a stale sweep from deleting the builder or smoke VM. Two distinct sweep Operations must miss a VM before its row fails; a replay of one observation cannot fail it. Doctor retains its read-only path.

### Incus provider

Swamp runs task VM effects through recorded Operations. Each effect fixes its host, guest identity and command input before execution. The Gateway admits the effect and requires a matching successful command receipt before recording completion. A retry uses the same Operation and effect key. A lost reply does not authorize a second VM or a different target.

The fixed `incus.vm` agent command runs on the Operation's host Node as its managed user. `IncusTaskVmProvider` exposes observations only. It launches the row's `image_fingerprint` as a VM with the row's `name`, the host's `cpus`, `memory`, and `disk`, and `eth0` on the host's `network` with `security.port_isolation=true`. The user-data goes on stdin. The provider never creates a network.

A [medium VM](#size-of-a-task-vm) gets `limits.cpu=4`, `limits.memory=8GiB`, and a 40 GiB root disk instead, and a large VM `limits.cpu=6`, `limits.memory=12GiB`, and a 60 GiB root disk. Every task VM's config on stdin sets `raw.qemu.conf`. Its `[boot-opts]` keys `menu = "on"` and `splash-time = "0"` tell OVMF to skip its 3 s boot menu wait on every boot. A medium or large VM's key also turns on free page reporting for the balloon device `qemu_balloon`. Incus applies the key only when the VM starts, so the launch sets it.

A medium or large VM holds test Nodes, so it boots with a clone of the [topology seed](#build-the-base-image) of its `image_fingerprint`:

1. When the volume `tvm-<id>-topology-seed` is missing, create copies the seed `orbit-topology-seed-<12 hex>` to that name. On ZFS the copy is a clone. It uses only what the VM writes.
2. A retried create finds the clone and copies nothing.
3. The launch adds the clone as the disk device `topology-seed` in the `devices` of the instance YAML on stdin, beside its `config`.

When the host has no seed for the VM's base image, create fails with `task_vm.topology_seed_missing`, and nothing launches. A normal VM gets no clone, and an UpCloud VM is always normal. The [claim gate](#claims-use-the-current-recipe) needs a promoted base, and promotion needs the seed, so a claimed VM always finds one.

When the launch fails and the project has no `orbit-task-base` alias, create fails with `task_vm.base_image_missing`. [Build the recipe](#build-the-base-image) on the host. Orbit never falls back to the stock image: a VM from it has no `openssh-server`, so it cannot enroll.

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
6. Run `php artisan task-vms:build-image shark`. Require base and seed verification and promotion before the topology gate.
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

- The Incus project, such as `orbit-tasks`, with `features.networks=false`, and the stock image alias `ubuntu-26.04-vm` from `images:ubuntu/26.04/cloud`. Only the [base image](#build-the-base-image) build launches it.
- The bridge, such as `orbittask0`, with IPv4 NAT and no IPv6.
- The ACL `<bridge>-egress`, which the bridge gets at creation.
- ACL egress drops private, link-local, CGNAT, and multicast ranges, and allows the rest.
- ACL ingress allows TCP 22 from the bridge address and rejects the rest.
- The project's `default` profile: `eth0` on the bridge with `security.port_isolation=true`, and the root disk on the host's pool.
- The project restrictions `restricted=true`, `restricted.networks.access=<bridge>`, and `restricted.virtual-machines.lowlevel=allow`.
- One host rule: `ufw route allow in on orbittask+ comment 'orbit-task-vms'`.

Task VMs need nothing that the restrictions forbid, except low-level config: medium and large VMs set `raw.qemu.conf` for free page reporting. Only the Gateway sets instance config in the project.

The command takes these values from the host Node's typed `settings.task_vms`. See [Configure task VMs](#configure-task-vms). The script checks the form of each argument again before it passes it to Incus as root, and changes nothing when one is invalid. The bridge and the ACL live in the Incus project `default`, and the script names that project in every network and ACL command.

Orbit reserves bridge names that start with `orbittask`. Every such bridge must carry its egress ACL, because the ufw rule accepts forwarded traffic from all of them. Only `incus.prepare-host` creates these bridges, and it attaches the ACL at creation. Never create an `orbittask` bridge in another way.

The restrictions forbid privileged and nested containers, host devices, backups, snapshots, and every network except the task bridge. Host disks are limited to the [read-only package-cache directory](#shared-package-cache). Low-level VM config is allowed for memory reporting.

The dropped egress ranges are `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `224.0.0.0/4`, and `240.0.0.0/4`. Port isolation blocks traffic between VMs on the same bridge. Both are needed. The ufw rule lets bridge traffic pass the host's forward policy, and replies use the existing rule for established connections.

### Build the base image

**Old path.** Stage 6 of the [command development pipeline](/reference/command-development-pipeline) replaces this per-release recipe image build with a refresh of the topology seed by converge. Keep the build working until that refresh exists, and do not extend it ([old paths](/reference/old-paths)).

An Incus build puts the `recipe.json` and the Vite+ lock of the stored machine recipe in its intent. The recipe identity does not name the Orbit agent, and an image holds none; the guest gets the observer when it enrols. The UpCloud build still compiles its image input from the stored recipe and the pinned agent delivery. It selects architecture-specific tools, separates PHP and Rust inputs, and keeps empty tool maps as JSON objects. A mismatched agent pin refuses the UpCloud build before a guest phase starts.

Run `php artisan task-vms:build-recipe` on the Gateway to queue the current recipe on every active host in `settings.task_vms`. It returns build IDs without waiting for remote work. Repeating it for an unchanged recipe reuses the same rows, including failed rows; start another stock attempt for a failure with `task-vms:build-image NODE --resume=ID`, or restart failed builds with `task-vms:build-recipe --retry` after cleanup. `--retry` applies to both Incus builds and UpCloud template attempts.

Use `php artisan task-vms:build-recipe --rebuild` after an image-builder fix, or whenever the current recipe needs a fresh image. It rebuilds the unchanged recipe from stock on every configured Incus host and UpCloud. It cannot be combined with `--retry`. It refuses while a build for the same recipe is running or its UpCloud rebuild request is pending, or while a configured Incus host is building another recipe.

Each candidate runs the normal build, smoke and promote path

A failed or cancelled image Operation also marks its owning build attempt failed, including a Gateway run-budget timeout. That records failure, not native termination: the Operation keeps its native-settlement hold until the shared reader verifies the terminal run. When the shared reader corrects an expiry to verified native success, the same attempt and recipe become succeeded with the already promoted fingerprint; recipe retry reuses that image.

A failed candidate leaves the promoted image in service. A newer promoted build for the same host and recipe resolves the failed candidate's image-build incident. Promotion swaps the image only after the candidate passes. Build records and candidate aliases distinguish rebuilds of the same recipe; the recipe identity used by Nodes does not change.

A recipe revert builds the selected identity from stock. Swamp retains completed steps within each native run; an explicit Gateway retry records a new stock attempt. `task-vms:image-builds` (also `task-vms:list-image-builds`) shows Incus recipe identities, hosts, steps, fingerprints, and immutable aliases, plus recent UpCloud attempt IDs, identities, states, times, and template UUIDs. `task-vms:build-recipe` prints the current UpCloud attempt and whether a refresh was requested or a verified template already exists. `task-vms:build-image NODE` builds the current recipe on that host; every recipe starts from stock.

Release handoff queues builds immediately when the deployed recipe identity changes. The scheduler checks each minute to recover missed submissions and pick up new hosts. There is no fixed nightly Incus rebuild. Unchanged releases do not build images. A host added later builds the current recipe on its next check. The same reconcile path refreshes configured UpCloud templates when their recipe identity differs.

Each Incus image attempt starts one native Swamp workflow, `operation-task-vm-image-build`, in the steps of the chart `docs/cli/tasks/vm-image-build.mmd`. Every step runs on a 209 worker with `labels: {role: swamp}`; the build host needs no Swamp worker and no agent. The Gateway passes the full admitted intent as workflow inputs. It checks at the run start that the attempt owns its build and that its recipe is still current. Steps make no Gateway calls. A recipe that changes during the run does not stop it; the next reconcile builds the new recipe.

The intent names these:

- The host's SSH target with its recorded host keys, which Node 209 uses for every `incus` call and as the jump to the builder.
- The builder and smoke names, the project, the network and the size of the VM.
- The stock image, the candidate alias and the base alias with its `-previous` copy.
- The `recipe.json` of the release, the Vite+ lock, and the Pi server digest with its place on the Gateway host.
- The cloud-init config that makes the `orbit` account with the key of the Swamp control Node.

Each Node action is one official `@swamp/ssh` step with pinned host keys and the 209 worker key, as one argv bounded by a literal `timeout`. The first four steps run in this order:

1. **Remove earlier attempts.** `incus delete --force` for the builder and the smoke VM. A missing instance is fine.
2. **Launch the builder.** `incus init` reads the cloud-init config from standard input. `incus start` boots the stock Ubuntu VM. The config makes the `orbit` account and installs `openssh-server`, which the stock image lacks.
3. **Wait for the builder.** `incus wait agent`, then `cloud-init status --wait`.
4. **Run the task image phases.** The child workflow `task-vm-image-ansible` runs the `software`, `tools` and `check` phases of the [task VM role playbook](#converge-a-running-task-vm) through the host.

The child reads the builder's bridge address and host key from the host before it runs Ansible. The playbook knows it builds an image through `intent.image`. It removes its downloads and the snapshot apt configuration at the end of the `tools` phase.

The last six steps follow:

5. **Clean and stop.** The child workflow `task-vm-image-clean` cleans the VM and stops it.
6. **Publish the candidate.** `incus publish --quiet` with the properties `orbit.recipe` and `orbit.profile`.
   The quiet flag leaves only the fingerprint line, and the Gateway reads it from the output.
7. **Smoke the candidate.** The child workflow `task-vm-image-smoke` boots a throwaway VM from the candidate and runs the `check` phase on it.
8. **Check the seed.** It lists the volumes of the host's pool and looks for `orbit-topology-seed-` and the first 12 hex characters of the candidate's fingerprint.
9. **Promote the image.** The child workflow `task-vm-image-promote` points the base alias at the candidate's fingerprint. It keeps the old image as `-previous`.
   This step runs only when the seed exists.
10. **Remove builder and smoke.** This step always runs. A failed removal does not fail the run.

The parent passes the fingerprint to the promote child as a plain string, and `incus` refuses a wrong one. The builder has no nested fleet, so step 5 passes the child an empty `nested` object. A clone makes its own cloud-init state, machine id and host keys at its first boot. The Operation holds the host lock key for the whole run, so no other host Operation runs while the alias moves. A later attempt removes a stray builder first.

The base alias moves only after the candidate passed its smoke check and the host has a seed for it. A failed build leaves the promoted image in service. The old image stays as the `-previous` rollback copy until the next promotion.

Each host records one `task_vm_image_builds` row and one Swamp Operation per attempt. The row stores the full recipe document, identity, desired generation, and the base image location. The shared base preserves the source image’s DNS configuration, including link DNS for internal names. Topology Nodes use that same base fingerprint for every role.

Both builds run as one native workflow. No workspace or Project setup runs and neither package caches nor CI cache refs enter an image.

The Incus build records the image identity as the properties `orbit.recipe` and `orbit.profile` of the published image. The UpCloud build records it as the label `orbit.recipe` of the template. The Gateway stores progress and safe error codes; it does not read guest logs or run recipe commands.

Swamp resumes failed native steps; completed steps remain checkpoints in the same root.

An Incus build copies the pinned Pi build from the Gateway Node to 209 with official SSH and checks its SHA-256 there. The `software` phase copies it to `~orbit/.local/bin/pi-server` on the builder when the digest differs from the pin, and checks the digest again.

The Gateway projects the result once, from Swamp's terminal run data. Step statuses give the build steps and the failed stage. The published fingerprint, read from the output of `Publish the candidate`, and the successful `Promote the image` step give the image record.
A run whose seed check skipped the promote is a recorded build without a promotion: the candidate and its fingerprint stay, and claims keep the promoted image. A failed guest wait reports `task_vm.bootstrap_timeout`, a failed launch `task_vm.bootstrap_failed`, and any other failed step `task_vm.image_build_failed`. `task-vms:build-image` prints the steps when the run ends.

An image build, Incus or UpCloud, installs the recipe packages through the playbook. It writes the same snapshot configuration to `/var/tmp/orbit-image-apt` and installs with the `apt` module with `APT_CONFIG` pointing at it, and `allow_downgrade` because the pins are exact. The index read goes over plain http through the `apt-cacher-ng` of the VM (`localhost:3142`, no request pipelining, signatures still checked), which the first software task installs; the packages stay a direct download over https. The cache stays in the image, and the nested Nodes of a task VM read the snapshot indexes from it. The `check` phase asserts every package at its pin.

Images live in each host's configured Incus project. The base is a VM image. Its immutable alias uses `<image_alias>-recipe-<first 12 hex characters of the recipe SHA-256>-<profile>`, where the built profile is `base`. The shared alias helper shortens the configured prefix when needed to keep every alias within Incus’s 64-character limit. The database and image properties keep the full recipe identity. Every image records `orbit.recipe` and `orbit.profile` properties and `/etc/orbit-machine-recipe.json`. The build row's `images` field exposes project, alias, fingerprint, and instance type.

#### The topology seed

The image build does not build the topology seed, and it never promotes an image without one. A new image has no seed under its fingerprint until a [seed refresh](#refresh-the-topology-seed) makes one.
The build then keeps the candidate without a promotion, and the refresh promotes the image together with its seed. A task VM on that host keeps booting from the promoted image, which has its seed. The first seed of a host, a nested fleet built from scratch without ready copies, is not designed yet (chart `docs/cli/tasks/vm-image-build.mmd`).

The build leaves the promoted image and, from the first promotion on, the `-previous` alias as the rollback copy. Published images remain subject to normal image pruning. A failed build needs a fresh stock attempt; native resume cannot continue after the builder has been removed.

#### Refresh the task image and the topology seed

A new task image comes from the recipe build only for a new host or a new base OS. Every release after that brings the image and the seed up to date by converge ([stage 6](/reference/command-development-pipeline#6-per-host-task-image-and-seed-refresh)).

The seed that the image build leaves holds only the stopped `node-template`. A refresh adds the ready nested topology of the running release. That is three stopped system containers: `fleet-ready-gateway` (Gateway with Swamp), `fleet-ready-app-dev` and `fleet-ready-app-prod`. They run on the nested WireGuard network 10.45.0.0/24. A task VM that claims the seed has them at once and boots copies, so it does not build Orbit again.

Each task VM host refreshes its own image and seed. Nothing is copied between hosts: copying across hosts is not a supported flow, so each host refreshes and, if needed, builds its own. An operator runs one with `php artisan task-vms:refresh-image <host>` on the Gateway, or every host with `task-vms:refresh-images`. There is no CLI command of its own. Two triggers admit every Linux host: a release that went live and changed playbooks, pins or lock files, and a daily schedule at 03:20. A host with a refresh open returns it, and a host that refuses is skipped until the next trigger.

`php artisan task-vms:refresh-image <host> --seed=keep` runs the image part alone. It skips `Build the fleet` and `Save the ready copies`; the refresh VM still clones the current seed, and the clone is copied under the new image. The seed content stays stale on purpose, the first use of a task VM converges it, and the Gateway never counts a `keep` run as a usable seed. The triggers always use `build`.

The `task_vm.image_refresh` kind is admitted like [`tasks:prep`](/cli/tasks/prep). The refresh VM is a task VM like any other. The Gateway creates a group of its own in the proof Project and places it on this host only. The VM starts from the image the host promoted last, whatever its recipe or age. It keeps the root disk of the host's base VM: a published image is as large as its VM's root disk, and no VM can be smaller than its image.

The Gateway captures the whole intent in the admission transaction of provision, so no step of the run calls the Gateway. A second admission for a host returns the open Operation. The Gateway refuses a host that is not an active, configured task VM host. It also refuses a host with an image build running or no promoted image, and a Gateway whose release has no commit.

The workflow `operation-task-vm-image-refresh` runs from Node 209.

| Step | What it does |
|---|---|
| `Create the VM` | Runs `operation-task-provision` as a child: the VM from the current image and seed, the release checkout, the converge of the outer VM, and the nested fleet from the ready copies. |
| `Pin the VM` | Fills the host key of the new guest into the ready copies intent and the fleet build intent. |
| `Build the fleet` | Runs `operation-task-vm-fleet-build` as a child when the VM step started no fleet, because the seed has no usable ready copies. A fleet that started from ready copies skips it. |
| `Converge the nested Nodes` | Runs `task-nested-converge` when the fleet started from ready copies: a `node.converge` of each nested Node, one at a time, before the save. |
| `Save the ready copies` | Runs `operation-task-vm-ready-copies`: key-free copies in the seed disk, boot-checked. |
| `Stop the refresh VM` | Runs `incus stop` on the refresh VM. It yields only its seed disk and is never published. |
| `Build the image` | Runs `task-vm-image-builder` as a child: a builder cloned from the image the refresh VM started from, the phases `software`, `tools` and `check`, `task-vm-image-clean`, then `incus publish` of the stopped builder under a candidate alias. |
| `Smoke the candidate` | Runs `task-vm-image-smoke`, the smoke of the image build: a throwaway VM from the candidate and the check phase of the task VM role on it. |
| `Enroll the check VM` | Runs `task-vm-bootstrap`, the bootstrap steps of an enrollment, on the smoke VM: the account, the key, the sudoers file and the observer install with their checks. |
| `Converge the check VM` | Runs `task-vm-image-ansible` on the smoke VM with the phases `software`, `tools` and `check`, as the converge of a provision does. |
| `Copy the seed`, `Detach the seed` | Copy the seed disk of the stopped refresh VM to `orbit-topology-seed-<12 hex of the new fingerprint>` under the host seed lock, then run `zfs promote` on it, so refreshes form no clone chain. |
| `Promote the image` | Runs `task-vm-image-promote`, the promotion of the image build: the alias moves to the new image and the old image keeps `<alias>-previous`. |

The refresh has no converge step of its own, because the provision converges the outer VM before the nested fleet. The builder never is enrolled and never has a seed, so the clean step has one case: it resets cloud-init, the machine-id and the SSH host keys, trims the disk and stops the builder. The image build runs the same clean step.

The fleet build first makes the SSH key of the `orbit` account in the refresh VM, because a fresh task VM has none. The key reaches the nested containers. Its private half stays on the refresh VM, which is never published. The build creates the three nested containers one at a time, because three parallel `incus init` calls of one image race on the image cache of the client.

The fleet build runs only in a large task VM (12 GiB, 6 CPUs), because the three nested containers may use 10 GiB and a medium VM lost its nested Gateway to the out-of-memory killer. The Gateway refuses the build for any other size. The admission of the refresh sets the size to large, so the Project size lists do not decide it, and the refresh needs three free slots of its host.

Every wait of the build for the nested Gateway is one run of the playbook `task-vm-fleet-wait.yml` from Node 209 against the refresh VM. A task with `until`, `retries` and `delay` checks with one `incus exec` every 5 seconds. A check that finds the Gateway guest not running ends the wait at once. The playbook then lists the nested containers, fails naming any that is not running, and asserts the end state. An Operation that did not succeed fails with its failed step and error code. The last wait is the step `Check the nested fleet`, which the Gateway reads as the end of the run.

The build reads the checkout of the refresh VM's own workspace, which its intent names as `operator_checkout`. A task VM keeps it under `/home/orbit/apps/<project>/workspace`. The nested containers have that path as the disk device `checkout` at `/home/orbit/orbit`.

The old image keeps the alias `<alias>-previous`, and the old seed stays under its own name. Together they are the rollback copy. The cleanup job removes the check VM and the builder. When the alias does not point at the new image, it also removes the candidate image and the new seed. A failed run keeps the image and seed the host had. The builder starts only after the VM step and the save of the ready copies passed, so a VM whose fleet failed its readiness check promotes nothing.

A cancelled refresh cancels its group, whether it ends before its Swamp run starts or the run ends cancelled. A cancelled run marks the group cancelled and leaves its VM in provisioning, so the group cancel still runs while that VM is not destroyed. It destroys the refresh VM and frees its slot. When the run ends, the Gateway records the new image as the host's latest promoted build, with the recipe identity of the release. It then cancels the group, which destroys the refresh VM. A claim then picks the new image and finds its seed by the fingerprint.

##### How a claim selects the new seed

The seed of an image is named from its fingerprint (`orbit-topology-seed-<12 hex>`). A refresh makes a new image with a new fingerprint, so it makes a new seed under a new name. The old seed and image stay as they were. A task VM that already runs keeps its own clone of the old seed.

One host lock, `/run/lock/orbit-task-vm-seed.lock`, orders the seed copy and the alias move of a refresh against the seed clone of a claim.

Image pruning deletes an image and its seeds together. The `-previous` alias and the old seed go when pruning removes the old image.

##### Not built

The chart lists what the run still lacks:

- The converge of the nested Nodes through the nested Orbit. Stage 2 builds it.
- The replacement of old ready slots. Ready slots do not exist yet.

##### Save the ready copies

`php artisan task-vms:save-ready-copies <VM-ID>` saves the nested fleet that runs in one task VM as the ready copies of the VM's seed disk. It is the step "Save the new images" of the seed refresh, as a kind of its own (`task_vm.ready_copies`, chart `docs/cli/tasks/vm-ready-copies.mmd`); the refresh runs the same workflow as a child once it starts its own refresh VM. The VM must be `ready` and hold the nested containers `nested-gateway`, `nested-app-dev` and `nested-app-prod` on the network `nested`.

The workflow `operation-task-vm-ready-copies` runs one `incus` command per step over official SSH to the VM. A step for each nested container runs once per role with `forEach`, and Swamp names its steps `<name>-<role>`. Each step has its own behavior name, per task VM, so steps that run together do not wait for each other's model lock. It sets the WireGuard key of the nested Gateway's `operator` Node record to null and takes the operator peer out of the hub's peer files. It deletes the proof Swamp Club key and searches every file of the three guests for it. It then stops the guests.

It deletes the earlier `fleet-ready-*` copies and copies each guest without its snapshots. It removes the NIC from each copy, and the `checkout` disk device from the Gateway and app-dev copies, and stores the subnet of the network, the MAC and the address on them. A throwaway copy of each ready copy then boots and is checked. It holds no Club key, no file of a checkout and no `eth0` or `checkout` device.

A booted guest makes mount point directories there and writes a Laravel log, and those are no checkout. Its state is on its own disk, and the Gateway copy has no operator key in the record or on the hub. The budget of the kind is 3,600 seconds.

##### Build the nested fleet

`php artisan task-vms:build-fleet <VM-ID>` builds the nested fleet of one ready task VM from stock Ubuntu, as the kind `task_vm.fleet_build` (chart `docs/cli/tasks/vm-fleet-build.mmd`). The Gateway captures the whole intent at admission: the SSH identity of the VM, the fixed address and MAC of each nested container, the pinned Swamp release and the agent delivery. No step of the run calls the Gateway.

The workflow `operation-task-vm-fleet-build` runs one command per step over official SSH to the VM. It creates the network `oe-nested`, removes an earlier fleet and forgets its Gateway host key, and makes three system containers from the stock image with cloud-init and the key of the VM. It writes the Swamp key to `api-key` on the Gateway guest, the file the nested Swamp units read, the ready copies delete and a task VM pushes. It runs `gateway-bootstrap` once with a local `swamp workflow run`.

The bootstrap input names the VM's operator address, 10.45.0.10, so the nested Gateway records the `operator` Node that stage 2 gives its key. The local bootstrap run needs the Swamp key on the VM. The build places it there from its vault copy, on stdin, just before that run, and removes it after the run, also after a failure, so no key stays on a VM where a task's agent may run. A build on a proof VM would remove the key that VM got at provision.

Each container allows nesting and has a memory limit and no root size. Cloud-init disables the `wg` and `wg-quick` AppArmor profiles at boot, because the profile of Ubuntu 26.04 kills `wg-quick` in a nested container. The containers live in the pool `topology-seed` of the seed disk, so the ready copies are copies in the seed.

The nested Gateway makes its own root CA for 10 years and signs its leaves for 397 days, and its Swamp tokens last 30 days. The fleet is private to the task VM, and the daily refresh replaces the seed long before any of these runs out.

The new Gateway then does the rest through its own Orbit. It converges its own Node, then its `gateway` role, adds the `websocket` role, and enrolls app-dev and app-prod. Each of these is an Operation of the nested Gateway, and a step waits for its end at a fixed interval inside a literal timeout.

The Gateway and app-dev guests then take their checkout from the outer VM, as they do when a task VM starts from ready copies. The Gateway stops its services and moves its state to `/var/lib/orbit-checkout-state` on its own disk: `.env`, `storage`, `bootstrap/cache` and the state of the Swamp repository. It drops the checkout it built. The step `Attach the checkout` adds the checkout of the outer VM as the disk device `checkout` of both guests, after cloud-init made the `orbit` account, and stock systemd mount units put the state over it. The services start again on the shared checkout.

The end check waits until Doctor of the nested Gateway answers healthy for the whole fleet, not only for one Node, and passes when it has no finding on the shared checkout. The budget of the kind is 3,600 seconds. `task_vm.ready_copies` saves the copies afterwards.

##### Start the nested fleet

A task that declares a topology gets its nested fleet from the ready copies of the seed. The step is skipped for a task without a topology. It is also skipped when the seed holds no three ready copies of the current format, and the result then says `nested_fleet: none`. The provision decides that once, in the step `Look for ready copies`, and the child has no guard of its own. Placement already keeps a VM that needs the fleet (a large or medium VM) off a host whose seed holds no such copies, so a skipped step is the exception.

The save writes the format as the key `user.ready.format`, and the child lists only the copies that carry the current value, so copies from before a change to the steps that start or apply a fleet are not used.

The step `Start the nested fleet` of `operation-task-provision` calls the child `task-nested-fleet`, which runs one command per step over official SSH to the task VM. It creates the network `oe-nested` from the subnet that the copies store and copies the three ready copies to `nested-gateway`, `nested-app-dev` and `nested-app-prod`. Each Node gets its stored MAC and address back, and the three containers start.

The Gateway and app-dev containers get the checkout of the task as the disk device `checkout` before they start. The task VM joins as operator: the key goes into the nested Gateway's `operator` Node record, and the peer goes into a file of the hub's peer folder `/etc/wireguard/orbit-peers.d`, in the form the hub rendering writes. The hub loads that folder at every start, so a restore of `base` keeps the peer.

The provider step `observe-seed` lists the volumes of the pool and prints only the two it needs: the seed of the image and the seed clone of this VM. Swamp cuts step output at 4,096 bytes, and the whole list of a host with many seeds is longer.

The step `Converge once` runs before it. A task with a topology carries the converge intent as the workflow input `converge`. It stays outside the provision intent, because the VM provider passes that whole in one argument. It has the nested subnet `10.232.2.0/24` and the bridge `oe-nested`.

The step `Pin the converge` fills the guest host key that the VM step pinned, and `Converge once` runs the child `operation-task-vm-converge`. Its Incus phase recovers the topology seed pool with `incus admin recover` from the seed disk `scsi-0QEMU_QEMU_HARDDISK_incus_topology--seed`, so the three ready copies show in the VM.

The recovery needs every instance in the pool to have its network and devices, and an old seed holds instances that name networks the VM lacks. The phase therefore deletes every instance of the seed disk except `node-template` and the three ready copies before it recovers; the disk is a clone that belongs to this VM. A blank disk gets the pool with `incus storage create`, which refuses to format a disk that holds a file system. Every command of the attach is bounded by `timeout`, well under the phase.

The fleet child creates the network only when the converge has not, and then checks that the network has the subnet that the copies store.

The step `Apply the checkout` of the fleet child calls the child `task-nested-apply`, which the restore of the `base` snapshot reuses. The containers start with the checkout already attached. At boot, the bind mount units of the copies put `.env`, `storage` and `bootstrap/cache` from each guest's own disk over the checkout. systemd makes the mount point `.env` that the checkout lacks.

The child checks each of the three units on the Gateway and on app-dev and starts none. In a container no udev confirms the device behind the checkout's mount, so a start job would wait 90 s for it and fail. The child then runs the migrations of the checked-out code on the nested Gateway. It restarts the queue worker, the agent-view subscriber and the Swamp worker, and checks, one unit at a time, that they and the nested Swamp serve are active.

The behavior name of `Check the services` carries the index of the unit, not its name. Swamp opens an SSH control socket under `/tmp/swamp-ssh/<behavior name>`, and a path over 108 bytes cannot be a Unix socket.

The fleet child then calls the child `task-nested-ready`, which checks readiness from the VM, over the operator's WireGuard address and the API of the nested Gateway. It first waits up to 120 seconds, with one Ansible task, until Doctor of the nested Gateway is healthy, because the agents read online only after their next heartbeats. All three Node agents must be online, the Gateway must know the operator with its key, and Doctor must be healthy. The child then takes one plain snapshot `base` of the three nested containers.

The child `proof-fleet-restore` puts the fleet back to `base` for the proof. It restores the three nested containers with a limit of 45 seconds and no retry, which is a reboot, starts them, fails unless all three are `RUNNING`, and calls `task-nested-ready` and then `task-nested-apply`.

The batch workflow calls it before every batch but the first. The case workflow calls it after a case that did not pass and is not the last of its batch, so the next case starts from a clean fleet. A restore that fails stops the rest of the batch.

After the apply step, the fleet child gives the VM a CLI profile. The steps `Make the CLI home`, `Pin the nested CA` and `Add the CLI profile` read the root CA of the nested Gateway with `incus exec … cat` into `~/.orbit/nested-ca.pem` and run `gateway:add https://10.45.0.1 --name=nested --use --ca=…` with the candidate CLI of the checkout. The proof runner runs that same CLI (`php apps/cli/orbit`) and finds the nested Gateway through the profile. The apply child checks the Swamp services only when the VM has the proof Club key (input `club`), because the nested Swamp serve cannot start without it.

The VM then joins as operator. It makes its own WireGuard key and writes the public key into the nested Gateway's `operator` record. It puts the key on the hub and brings up `wg-nested` as `10.45.0.10`. For a task that needs the proof Swamp Club key, the VM makes the folders `/home/orbit/.config/swamp` in the nested Gateway, owned by `orbit` with mode 0700, pushes the key into them and restarts its Swamp.

##### The ready copies

One stage 6 step makes them, and the contract is in `swamp/proof/README.md`. The names are `fleet-ready-gateway`, `fleet-ready-app-dev` and `fleet-ready-app-prod`: stopped VMs beside `node-template`. A copy has no NIC, no source mount and no `user.orbit.e2e.*` label. The `user.ready.*` keys hold the set identity, the role, when the copy was made, the MAC, the IP and the subnet. ``user.ready.complete` is written last.

The age of a set does not matter: every provision that starts a fleet from the copies renews the Swamp tokens of the nested Gateway before the apply step (the play `remint` of `gateway-bootstrap.yml`, 30 days), so the refresh has no token step. Copies of format 4 carry the control plan that the play reads; copies of another format are not used. A failed renewal writes the failed task to stderr, which the run records with the step; the tasks print no token.

The ready Gateway's database is encrypted with the application key in the `.env` of the build’s source tree. That tree is a directory of the outer VM and not part of the seed. So the seed also holds a copy of it, with ACLs and owners, as the custom volume `ready-source`. A task VM restores it before it restores the copies.

The proof Swamp Club key is in no copy. `ready` removes it from the Gateway and scans for it. The check then boots a throwaway copy of each ready copy and searches it, and `ready-source`, for the key by path and by value. A key in the seed would reach every task VM of the host. A ready copy itself is never started.

The Gateway keeps no state of its own for a refresh. The succeeded Operation is the record. It holds the seed, the ready copies, the size of the seed (`referenced_bytes`) and the bytes the build wrote (`written_bytes`). The budget is in [Operations](/reference/operations).

After promotion, the final chain step prunes old recipe images on that host. It keeps the current and previous promoted base images, images used by existing VMs, and images of other running builds.

Pruning deletes a base image's topology seed, and a seed under construction, right before the image, so a kept base keeps its seed. An [image refresh](#refresh-the-task-image-and-the-topology-seed) keeps the old image and its seed as the rollback copy, under their own names, until pruning removes them. When VMs still use clones of a deleted seed, Incus moves the seed's dataset to its `deleted` path and destroys it with the last clone, so Orbit tracks no references.

Pruning also removes the unused gateway, app-dev, app-prod, and operator aliases for host-local build records, including current and previous recipes. Images and aliases used by existing VMs stay until unused. Otherwise aliased or retained base images stay; only their unused role aliases are removed. Pruning failures resume without rebuilding. The base-only chain has 15 jobs, including the topology seed, promotion, and pruning.

The Incus image build path alerts once per failed attempt that needs operator recovery and names `task-vms:build-recipe --retry` as recovery. An unacknowledged from-stock submission restarts on its own without an operator alert. An unacknowledged submission to reactivate a verified image set or resume a failed step alerts and needs `task-vms:build-recipe --retry`.

The two image-build workers check `builds` before `builds-optional`, so a waiting Incus chain step takes priority over the optional UpCloud job. A running UpCloud build is not interrupted. UpCloud remains one synchronous job, with no new lock. Failures from the recipe installers and recipe verification store their phase, exit status, and bounded, sanitized stderr before deleting the builder. Other stages, including launch, cloud-init, templatize, and smoke checks outside recipe verification, report their stage error without that structured diagnostic. `php artisan task-vms:image-builds` shows the retained diagnostics. The failed-attempt fence remains; only `php artisan task-vms:build-recipe --retry` admits another attempt. This command also retries failed Incus builds.

### Claims use the current recipe

A new Incus claim uses a succeeded, promoted base image for the deployed recipe on its selected host. The VM records `recipe_identity`, `image_fingerprint`, `image_build_id`, and `image_fallback` at allocation; launch uses the fingerprint, so a later alias move cannot change the selected image. Enrollment checks the observed fingerprint and trusts the image's software, as ADR 0206 requires. There is no full-enrollment fallback for stale images.

An existing group keeps its VM and recorded image across recipe changes, failures, parking, and resume. UpCloud claims require a verified template for the current recipe; they never take a fallback image.

#### Image fallback

While a host builds the current recipe, or after that build failed, a new claim on that host starts from the host's last promoted base image if that image was promoted at most one day ago. The VM then records that image's own recipe identity and build, with `image_fallback` true. A [`tasks.provision` result](/reference/tasks#provision-an-external-group) reports the same four facts under `image`.

A group created with `current_recipe: true` (`tasks:create --current-recipe`) needs the new recipe. It never takes a fallback image and waits as before. Proof topologies and ordinary tasks leave the field false.

A host with no current image and no promoted image under a day old cannot serve a new claim. The group's `capacity_wait_reason` says `Task VM: waiting for recipe <12-character digest> images on <host>.` Another host with a usable image can serve it. Image unavailability never triggers UpCloud overflow.

A fallback image is safe for enrollment: enrollment installs the pinned agent from its artifact URL and checks its digest, and the runtime step installs the pinned Pi build, before either is used. Pruning keeps the current and previous promoted base images and every image an existing VM uses, so the fallback image stays while a claim can select it or a VM runs from it.

### Add a task VM host

A second host needs Incus, ZFS, and ufw, and its managed user needs passwordless `sudo`. Then:

1. Set the host Node's [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) with `node:update --task-vms` or the Node update API. Use a unique `cidr` and a new `zfs_dataset`, such as `data/orbit-tasks`.
2. Run `task-vms:prepare-host {node}`. It creates the pool from the new dataset and sets up the host.
3. Run `task-vms:build-image {node}` to build the current recipe locally.

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
- the host and port of `task_vms.model_proxy_origin`;
- every active Node with a recorded Router-role assignment in the dev Cluster, including a provisioning replacement until its assignment is withdrawn.

The table filters only the reserved range. Its first rule passes every packet that has no address in the range, so traffic between other Nodes does not change. The range must be inside the WireGuard subnet, because every Node routes only the subnet through the tunnel. `TaskVmSettings` checks that. The command refuses a range that holds the address of a Node not named `tvm-<id>`. It also refuses a missing dev Cluster or origin, and an origin whose host is not an IPv4 address outside the range.

| Direction | Allowed |
| --- | --- |
| From task VMs | The Gateway API on TCP 443, CLIProxyAPI on TCP 8317, Reverb and the configured Swamp site on TCP 443, and DNS on port 53 of the VPN DNS address |
| To task VMs | The Gateway on TCP 22 and the Pi port,209 on TCP22 only, and the dev Cluster’s Router-role Nodes on TCP 80/443 only |

The 209 SSH and TLS probe rules and the Cluster Router web rules come from the same canonical definitions shown by `firewall:list --managed` on the VPN Node. A Router in another Cluster receives no rule for this range. Router web access grants neither SSH, Pi nor Vite-port access. Adding a UFW allow alone cannot override the earlier nft filter. The native hub convergence updates both managed surfaces. `firewall:list --live` reads the last completed native UFW inspection; an unreachable result does not prove current rules.

The table accepts established traffic and drops all other traffic to or from the range. The WireGuard address allocator skips the range for other Nodes. Only `AllocateTaskVmAction` assigns addresses in it. A task VM Node has no access grants to other Nodes.

### Refuse task VMs in Caddy

TCP 443 on the Gateway Node and on the `websocket` Node is one Caddy listener for every private site on that Node, so the hub table cannot tell `reverb.orbit` from `executor.orbit` or `analytics.orbit`. Caddy closes that gap. On every Node, every site except `gateway.orbit`, `reverb.orbit`, and the optional Swamp site named by `task_vms.swamp_host` aborts a client in `task_vms.wireguard_range`. [Caddy configuration](/reference/caddy-configuration#listener-addresses) shows the guard.

Set `ORBIT_TASK_VMS_SWAMP_HOST=swamp.orbit` for the managed private Swamp Route on the `swamp` Node. The setting adds no hub rule, so a task VM reaches the site only when the hub admits TCP 443 on that Node, as it does on the Gateway and `websocket` Nodes. The exception matches exactly one hostname on port 443 and never a public Ingress site or a block with extra hostnames. The other client-range guard still applies. It admits no sibling site, subdomain, or alternate port. An unset or empty value admits no Swamp site.

Caddy renders the guard only after you configure task VMs: you enable them, or you set `dev_cluster_id`, `model_proxy_origin`, `swamp_host`, or a host. Until then, every Caddyfile stays as it was. The guard reads only that condition, `task_vms.wireguard_range`, and `task_vms.swamp_host`. Another invalid task VM value never changes a Caddyfile or blocks a build.

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
| `task_vms.model_proxy_origin` | `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` | The CLIProxyAPI origin that Pi on the VM uses, such as `http://10.44.0.3:8317`. An `http` or `https` origin with no path, query, or credentials. It must be the CLIProxyAPI URL of the [ai-router extension](/reference/ai-router) |
| `task_vms.pi.artifact_path` | `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` | The absolute path of the pinned Pi executable on the Gateway |
| `task_vms.pi.artifact_sha256` | `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256` | Its lowercase SHA-256 digest. Set both Pi artifact values or neither |
| `task_vms.pi.models` | `ORBIT_TASK_VMS_PI_MODELS` | A JSON list of the models Pi offers. Default `[]` |
| `task_vms.medium_projects` | `ORBIT_TASK_VMS_MEDIUM_PROJECTS` | A JSON list of Project IDs whose groups get a [medium VM](#size-of-a-task-vm) when no subtask declares a topology. Default `[]` |
| `task_vms.large_projects` | `ORBIT_TASK_VMS_LARGE_PROJECTS` | A JSON list of Project IDs whose groups get a [large VM](#size-of-a-task-vm) when no subtask declares a topology. Takes precedence over `medium_projects`. Default `[]` |
| `task_vms.topology_paths` | `ORBIT_TASK_VMS_TOPOLOGY_PATHS` | A JSON object from Project ID to a list of path patterns that need a [declared topology](#prove-work-on-a-topology). Default `{}` |

Each task VM stores `ai_router_origin` with its model key, and revokes the key at that origin. So changing the origin never strands a key. A task VM that holds a key at another origin cannot prepare Pi again.

`TaskVmRuntime` reaches CLIProxyAPI only through the core interface `App\Domain\TaskVms\ModelKeyIssuer`: `assertReady()`, `origin()`, `issue(origin, key)`, and `revoke(origin, key)`. The [ai-router extension](/reference/ai-router) binds it and keeps the management key and the anchor key, so task VM code never sees them. `TaskVmRuntime` creates the group key and stores it with its origin on the task VM row before it calls `issue()`, so a retry registers the same key.

Every key change holds the lock `ModelKeyIssuer::Lock` (`orbit:compute:model-keys`). Under that lock, preparation calls `assertReady()` before it stores a key, and refuses with `task_vm.model_key_failed` (409) while the router cannot revoke keys. The `tasks` extension depends on `ai-router`, and enabling `tasks` calls `assertReady()`, which refuses until ai-router holds a management key. `ai-router:teardown` holds the same lock and refuses while a task VM holds a key. See [Teardown](/reference/ai-router#teardown).

The [Node settings field table](/reference/node-settings#task-vm-fields) owns the host configuration. The containing Node supplies host identity. `operator_owned` keeps listed resources out of the [stray report](#report-strays).

Placement selects active Nodes with this setting and capacity reads their slot budget. Host preparation uses its pool, network, and image alias. Package-cache targeting follows the same setting, without making `app-dev` a host marker.

To replace `ORBIT_TASK_VMS_INCUS_HOSTS`, [configure and verify Node settings](/reference/node-settings#migrate-the-environment-host-list) before removing the variable from `gateway.env`. The Gateway does not read the environment list.

`TaskVmSettings` validates the configuration once, when it is first used. It checks every value that is set, also while task VMs are off, so you can prepare hosts and the hub before you enable task VMs. An unset or empty value stays absent, and malformed JSON is an error. Enabling task VMs also requires `dev_cluster_id`, `model_proxy_origin`, and at least one host.

The reserved range must always be a private network. As soon as task VMs are enabled, or the Cluster, the origin, the Swamp hostname, or a host is set, the range must also be a smaller network inside the Gateway's VPN subnet, and no host's `cidr` may overlap that subnet. While none of them is set, Orbit does not compare the range with the subnet, so a fleet on another subnet keeps working. Invalid configuration fails with `task_vm.invalid_config` (HTTP 500).

### Errors

`IncusTaskVmProvider` reads the host's output: the instance state, its one IPv4 address inside the bridge range, and the host key fingerprint. `TaskVmGuest` reads the guest's output the same way for every provider: the cloud-init status. Invalid output fails with `task_vm.invalid_host_output`, and a failed guest command with `task_vm.host_command_failed`, also on UpCloud. Every task VM error code starts with `task_vm.`. The setup commands print the code and message, and for `task_vm.setup_failed` the last lines of the script's error output. After that check, Orbit trusts its own records.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.invalid_config` | 500 | A `task_vms` value is invalid, or the ai-router CLIProxyAPI URL is not `model_proxy_origin`. See [Configure task VMs](#configure-task-vms) |
| `task_vm.unknown_host` | 409 | The Node is not active or has no valid `settings.task_vms` |
| `task_vm.invalid_gateway_key` | 500 | The Gateway's SSH public key is not one OpenSSH public key line |
| `task_vm.workspace_mismatch` | 409 | A group's workspace is not on its ready task VM |
| `task_vm.foreign_instance` | 409 | An Instance on a task VM Node is not its group's workspace |
| `task_vm.access_refused` | 409 | An access grant names a task VM Node as its consumer |
| `task_vm.range_in_use` | 409 | A Node not named `tvm-<id>` holds an address in the reserved range |
| `task_vm.fleet_unavailable` | 409 | The fleet has not exactly one active `vpn`, `gateway`, or `websocket` Node, the dev Cluster has no active router, or one of these Nodes has no WireGuard address |
| `task_vm.setup_unavailable` | 409 | The setup script is missing, or the target Node has no WireGuard address |
| `task_vm.setup_failed` | 409 | The setup script failed or did not print `{"ok":true}` |
| `task_vm.invalid_host_output` | 502 | Incus or a task VM guest returned output that fails these checks |
| `task_vm.host_command_failed` | 502 | An `incus` command on the host, or a guest command in a task VM, failed |
| `task_vm.bootstrap_failed` | 502 | Cloud-init in the VM reports `error` |
| `task_vm.not_enrolled` | 409 | The task VM has no Node yet, so Pi cannot be prepared |
| `task_vm.model_key_failed` | 409 or 502 | CLIProxyAPI did not confirm the group's model key, the ai-router extension holds no management key, or another key operation runs |
| `task_vm.runtime_failed` | 502 | A Pi step failed on the VM: the executable, its digest, `models.json`, the token, or the `pi-server` Process |
| `task_vm.vm_not_running` | 502 | During enrollment, the VM is absent, or it stayed stopped |
| `task_vm.bootstrap_timeout` | 504 | Cloud-init was not `done` 10 minutes after the row was created |
| `task_vm.job_failed` | 500 | A task VM job failed with an error that has no code of its own |
| `task_vm.base_image_missing` | 409 | The host's project has no `orbit-task-base` image. Run `task-vms:build-image` |
| `task_vm.topology_seed_missing` | 409 | The host has no topology seed for the base image of a medium or large VM. Run `task-vms:build-image` |
| `task_vm.topology_seed_failed` | 502 | The topology seed build was refused. The error includes its bounded diagnostic |
| `task_vm.image_refresh_unavailable` | 409 | A refresh cannot start: the Gateway release has no commit |
| `task_vm.image_refresh_failed` | 502 | A refresh run failed. The Operation names the failed step |
| `task_vm.image_build_failed` | 502 | A base image build failed. The build row records the step and error |
| `task_vm.image_channel_lost` | 409 | The bounded observation period ended without a readable phase result. The guest outcome is unknown; inspect the retained builder before resuming |
| `task_vm.image_build_running` | 409 | A recipe image build on the host is already running |
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

## UpCloud overflow

The native orphan sweep verifies the recorded name and label and calls UpCloud directly. The template build cleans up its own servers and unverified template from 209. Retained synchronous task VM lifecycle callers keep their existing provider until their parent/child cutover.


When beast and shark have no free slot, a group of an opted-in Project may get its [task VM](#task-vms) at UpCloud. The overflow is off by default. It needs both the Gateway-wide switch `ORBIT_TASK_VMS_UPCLOUD_ENABLED=true` and the Project's ID in `ORBIT_TASK_VMS_UPCLOUD_PROJECTS`. Without both, the group waits with `Task VM: no capacity.` as before.

An UpCloud task VM follows the same path as an Incus one. It is a `task_vms` row with the provider `upcloud` and no host Node. The same jobs create, enroll, park, resume, and destroy it.

It gets the same task VM user-data, enrolls as the `app-dev` Node `tvm-<id>` of the dev Cluster with an address from the reserved WireGuard range, and starts from a verified [UpCloud template](#build-the-upcloud-template). UpCloud uses the same `recipe.json` base-profile installer as Incus.

Its template's `orbit.recipe` storage label records the full recipe identity, and each new claim records the verified template UUID for that identity. Template lookup runs before the admission transaction; admission rechecks the deployed identity and capacity. Provisioning clones the recorded UUID, including after a recipe change. So the hub filter, the Caddy guard, the rollout exclusion, and the placement invariant apply unchanged. Each claim [clones and sets up its workspace](#clone-and-set-up-every-workspace), as on Incus, without a shared host package cache on UpCloud.

### Place a VM at UpCloud

Placement creates a new VM on the Incus host with the most free slots, as in [Place task VMs](#place-task-vms). Only when no host has a free slot does a new VM go to UpCloud, and only while the switch is on, the group's Project is opted in, and fewer than `max_vms` UpCloud VMs are not `destroyed`. Parked and failed UpCloud VMs count. A VM never moves to another provider.

### Create and reach an UpCloud server

The server is named `tvm-<id>` in its title and hostname, and carries the label `orbit-task-vm=<id>`. Orbit records its UUID. A create whose answer was lost is found again by its label, so Orbit never sends a second create. Before each create, Orbit counts the account's servers with the `orbit-task-vm` label and refuses at `max_vms` with `task_vm.upcloud_budget_full`. A server that lost its row counts too.

The server clones the newest verified template for the claim's recorded recipe onto a disk with the storage tier of its plan, such as `standard` for a Starter plan. The default plan includes 30 GB of that storage, so the default disk costs nothing extra. It has one public IPv4 interface, and no utility or private network, so it reaches no private address of the account. Fleet traffic goes only through WireGuard and the hub filter. Console access is off. The UpCloud firewall stays off: it is stateless, so reply traffic needs source-port rules that open other ports too.

Before an UpCloud template is published, the `clean` phase of the task VM role allows public SSH on port 22 with the `orbit:public-ssh-recovery` comment, denies other incoming traffic, and enables UFW.

The server boots with ufw on, from the template. ufw denies all incoming traffic except public SSH on port 22, so Caddy and the other services in the image are not public before enrollment. The template holds the exact rule `orbit:public-ssh-recovery` that enrollment opens on a new Node. Enrollment finds it in place and changes nothing, then the `app-dev` role adds WireGuard trust and closes public SSH, as on any public `app-dev` Node.

UpCloud has no trusted host that can read the guest's SSH host key, as Incus does. The native provision step generates an ed25519 host key and stages the pair through the admitted `provider_bootstrap` private output before creating a server. The Gateway stores it encrypted on the owning Task VM and records its public pin. A retry reuses that pair; it cannot replace it or create a server before storage succeeds. Incus grants no reader or writer for this UpCloud-only field.

Swamp renders cloud-init from the staged pair and the admitted Gateway public key. No connection trusts a key on first use. Root login stays off. Swamp reads cloud-init over pinned SSH as `orbit` at the public address, and enrollment needs no jump Node.

No UpCloud step ships a script. Each provider request in `task-vm-provider-upcloud` and `task-vm-provider-power` is one inline `curl` on Node209. The API token and the bootstrap private key reach curl only from its environment, through `--variable`; they are never in argv or expanded by the shell. TypeScript on Node209 (`task-vm-lifecycle`) selects the server, builds each request body, and checks each answer.

Create lists the servers labelled `orbit-task-vm` and the plans, then posts one create body. The body is public intent with one curl expansion, `{{ORBIT_BOOTSTRAP_PRIVATE_KEY:json:json}}`. curl escapes the key twice, because cloud-init's user data is JSON inside a JSON string. The Gateway's vault copy `task-vm-provider-bootstrap-<id>` therefore holds the private key alone.

The create answer is discarded. The label list finds the server again, also after a lost answer. A server that is still missing then fails with `task_vm.upcloud_failed`. One server detail read checks the UUID, title, hostname, labels and the single public IPv4 address. A stopped server gets one start request and one more detail read.

A power action reads the label list and then the server detail. curl prints the HTTP status on the last line of the detail read. `404` is absence. Any other failed status fails with `task_vm.upcloud_failed`, so a provider error never supplies a missing-VM observation. A server with an unknown UUID that the label list does not show is absent without a detail read.

Start and stop are one `POST` each; a stop is soft with a 120-second timeout. Delete is one `DELETE` with `storages=1&backups=delete`. One more detail read checks the result.

A park stops the server cleanly, and a hard stop follows after 150 seconds. UpCloud bills a stopped server for its storage only. A destroy stops the server, deletes it with its disk, and waits until it is gone.

Swamp owns UpCloud image cleanup and orphan sweeps. Both run on role `swamp` workers on 209 with the token from the vault. An unported Task VM kind returns `operation.kind_unavailable`; it has no polled executor.

Task VM enrollment captures the guest Node and Pi Process intent without executing either. One native root bootstraps through its admitted first-visit transport, records the public SSH identity, publishes the network, issues and installs the exact Node worker, then prepares Pi through the shared native Process installer. Readiness requires execution on that guest's trusted Node worker. The group worker cannot substitute for it. Model-key steps use the stable router behavior shared with teardown. Private credentials stay on the admitted private-material transport; the Gateway projects public results.

### Build the UpCloud template

`php artisan task-vms:build-upcloud-image` records one image attempt and starts `operation-task-vm-upcloud-image-build`, in the steps of the chart `docs/cli/tasks/vm-upcloud-image-build.mmd`. It creates billed servers. Every step runs on a role `swamp` worker on 209. Each UpCloud request is one inline `curl`. The software is the [task VM role playbook](#converge-a-running-task-vm), run by Ansible straight to the server. Every other guest action is one pinned official `@swamp/ssh` call. No step calls the Gateway, and no Orbit agent runs on a build server or stays in the template. The Gateway records the attempt from the run's terminal result.

At admission the Gateway generates one ed25519 host key pair for the build servers and keeps it encrypted on the attempt. At the run start it refuses an attempt whose recipe changed with `task_vm.image_build_superseded`. It then copies the UpCloud token to the vault key `task-vm-upcloud-token` and the private host key to `task-vm-image-bootstrap-<attempt>`. The run gets the full public intent: the recipe document, the stamp, the public host key and 209's SSH identity. The intent also holds the Pi pin and its source, the Vite+ lock and the templates that live VMs pin.

| Step | What it does |
| --- | --- |
| Launch the builder | Creates the builder from the stock Ubuntu 26.04 template, labelled `orbit-task-image=<stamp>`, with the `orbit` account at UID and GID 1000 |
| Run the task image phases | The child `task-vm-upcloud-image-ansible` copies the pinned Pi build from the Gateway Node to 209 and checks its SHA-256. It then runs the `software`, `tools` and `check` phases against the public address of the builder |
| Prepare and clean | The same child with the `clean` phase: masks, ufw with only the public SSH rule `orbit:public-ssh-recovery`, identity files, `cloud-init clean` |
| Make the template, Read the template | Stops the builder, turns its disk into the template `orbit-task-base-<stamp>-unverified` and reads its UUID |
| Smoke the template | The child `task-vm-upcloud-image-smoke` creates a server from the template with the same host key, waits as the role does (`wait_for_connection`, then cloud-init), and runs the `check` and `template` phases |
| Promote the template | Records the `orbit.recipe` and `orbit-task-image` labels and renames the template to `orbit-task-base-<stamp>` |
| List servers, templates, Plan the cleanup | After the build, whatever its outcome, lists the labelled servers and the templates once, and confirms the promotion |
| Check the template | The end check: the promoted template is the one templatize made |
| Delete servers, templates | Stops and deletes this attempt's servers with their disks. After promotion it deletes verified templates beyond the two newest that no live VM pins; otherwise it deletes the unverified template |

The user data authorizes only the key of 209's worker. Every SSH connection pins the admitted host key, so no connection trusts a key on first use. The wait for the boot is the role's: Ansible's `wait_for_connection`, then `cloud-init status --wait`. A finish of cloud-init with recoverable errors (exit 2) passes. UpCloud refuses a templatize, a create from a template that is still being prepared, and a delete of a server that is still stopping, and changes nothing. curl's own retry, with a fixed 10-second delay under one literal `timeout`, waits for these states. No script polls.

A failed UpCloud request in the template, promote or listing step fails with `task_vm.upcloud_failed`. Other failures fail with `task_vm.image_build_failed`; the output of the failed step names the Ansible task.

Deletes are best effort: a failed delete does not change the run's outcome, and the [host sweep](#sweep-upcloud) removes what stays, also a server whose create answer was lost. A failed build keeps the previous template. Without a verified template for the claim's recipe, a create fails with `task_vm.base_image_missing`.

One build runs at a time. A build marks its time stamp as running for two hours, and no sweep deletes the servers or the template of a marked build.

### Refresh the UpCloud template on recipe changes

Each minute, the recipe reconcile queues `RefreshUpCloudTaskVmBaseImage` on the `builds-optional` queue when UpCloud is configured and no verified template matches the deployed recipe. The [Gateway queue](/reference/gateway-queue#queues) gives waiting required builds priority. There is no separate nightly schedule.

The job checks the identity again before building. A database ledger records each attempt before remote work, including failed and interrupted builds. Reconcile does not dispatch that identity again unless `task-vms:build-recipe --retry` explicitly requests it. A new identity can build automatically. A queued build that finds an earlier attempt refuses with `task_vm.image_build_superseded`; use the explicit retry command instead of redispatching it. The manual `task-vms:build-upcloud-image` command also starts an explicit attempt.

Reconcile raises a recipe-maintenance alert when the current identity has a failed attempt, or a running attempt at least two hours old, and no verified template. The alert names the identity and attempt and includes `php artisan task-vms:build-recipe --retry`. Repeated alerts for an attempt are limited to one per hour. The build command also reports this fenced state. Reconcile reports only the latest attempt and stops alerting once a verified template exists. `--retry` also retries failed Incus builds.

The build keeps the two newest verified templates and any template pinned by a live VM awaiting server creation. Pruning ignores the source template reference once the VM has a server UUID. It also runs with overflow off without enabling claims. A failed build keeps the previous template, but a stale template cannot serve a new claim. Reconcile reports UpCloud token, API, or refresh-dispatch errors and continues Incus submission recovery and image scheduling.

The build takes about 20 minutes; its run budget is 2,100 seconds. Swamp owns dispatch, cancellation and recovery. The token and the private host key reach curl only from its environment, through `--variable`; they are never in argv, inputs, outputs or history. Each Ansible run has one literal timeout of 3,300 seconds. A failed smoke check prevents promotion. An explicit retry records a new attempt.

### Sweep UpCloud

While the token file is set, also with the switch or task VMs off, the [host sweep](#sweep-task-vm-hosts) lists the servers with the `orbit-task-vm` label. So no server that Orbit created keeps costing money. The sweep deletes each server named `tvm-<label value>` without a row that is not `destroyed`. A `ready` or `parked` UpCloud row whose server is gone on two sweeps in a row becomes `failed`, and its group asks for assistance. It also deletes build servers and unverified templates older than two hours, which a killed build leaves, except those of a build that is still marked as running.

The sweep stops a started server with a hard stop, then deletes it with its storage. UpCloud refuses to delete a server that is still stopping, so that sweep fails and the next sweep deletes the server.

### Configure UpCloud

Set these values in the Gateway environment. `TaskVmSettings` validates them once, with the other [task VM settings](#configure-task-vms). While the token file is unset, the overflow is not configured, and Orbit sends no UpCloud request.

| Variable | Meaning | Default |
| --- | --- | --- |
| `ORBIT_TASK_VMS_UPCLOUD_ENABLED` | The Gateway-wide switch. It needs task VMs enabled, the token file, and `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS` | `false` |
| `ORBIT_TASK_VMS_UPCLOUD_PROJECTS` | A JSON list of the IDs of the Projects that may overflow | `[]` |
| `ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE` | The absolute path of the token file: a regular file of the Gateway user with mode `0600` and one `token: ucat_…` line | Unset |
| `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS` | The most UpCloud task VMs, from 1 to 16. Required once the token file is set | Unset |
| `ORBIT_TASK_VMS_UPCLOUD_ZONE` | The UpCloud zone | `nl-ams1` |
| `ORBIT_TASK_VMS_UPCLOUD_PLAN` | The server plan. UpCloud must list it | `STARTER-2xCPU-4GB` |
| `ORBIT_TASK_VMS_UPCLOUD_DISK_GB` | The root disk in GB, from 10 to 200 | `30` |
| `ORBIT_TASK_VMS_UPCLOUD_SOURCE_TEMPLATE` | The UUID of the stock Ubuntu 26.04 template that the build boots | `01000000-0000-4000-8000-000030260200` |

The token goes only to `https://api.upcloud.com/1.3`. The Gateway reads it from the file for each request.

| Error | Meaning |
| --- | --- |
| `task_vm.upcloud_budget_full` (409) | The account already runs `max_vms` task VM servers |
| `task_vm.upcloud_failed` (502) | An UpCloud request failed or did not answer |
| `task_vm.upcloud_invalid` (502) | UpCloud answered with data that does not match what Orbit created |
| `task_vm.upcloud_timeout` (504) | A server or template did not reach the expected state in time |
| `task_vm.upcloud_token_unavailable` (500) | The token file is missing, not private, or has no token |
| `task_vm.upcloud_not_configured` (409) | An UpCloud command ran while the token file is unset |
| `task_vm.host_key_failed` (500) | The Gateway could not generate or read the pinned host key |

### Why the UpCloud overflow works this way

One path for every provider keeps the security boundary and the code the same: only the provider API differs. Two switches keep billed VMs off unless the operator chose them for the fleet and for the Project.

The Project list lives in the Gateway environment next to the switch, so no Project field, API, or CLI change is needed. A count of the account's labelled servers backs the count of rows, so a lost row cannot raise the bill. A pinned host key that the Gateway generates replaces the trusted host that UpCloud lacks. A rename after the smoke boot marks a template as verified, so no VM clones an untested one.

| Alternative | Why not |
| --- | --- |
| A Project field for the opt-in | It needs a migration, API, CLI, SDK, and MCP changes for a switch that only the operator sets |
| The UpCloud firewall | It is stateless. Reply traffic needs source-port rules, which also let anyone reach other ports from those source ports |
| Trust on first use for the host key | The first connection could reach another machine at a reused address |
| The stock template with a full enrollment | Each VM would install the `app-dev` software, and its image would differ from the Incus base image |

## Orbit Project

A group of the `orbit` Project runs on a task VM, like the group of any other Project. Its workspace is the Instance `task-<group id>` without a Route, and every claim clones from the Gateway mirror and installs the dependencies of all five Composer projects and the web app. Its task check is root `composer check`, which runs `bin/review-check` on the VM as `orbit`.

Configure the Project like this:

- List it in `task_vms.medium_projects`, so its groups get a [medium VM](#size-of-a-task-vm) with 4 vCPU. Its check runs the test suites in parallel.
- Add the setup step [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) after the install steps.

Without the `@orbit/ci-caches` step, Pint and PHPStan start cold in every task VM workspace. With it, a fresh workspace gets the Pint and PHPStan caches that main CI published for its baseline.

The setup and teardown steps run on the VM as `orbit`. The `seed-dependencies` step does nothing there, because a task VM workspace has no `ORBIT_SEED_PATH`: the install steps build fresh dependency trees using private caches seeded from the read-only host cache.

### Declare a topology

A subtask declares the test nodes it needs in its `topology` field, for example `["app-dev"]` or `["app-dev", "app-prod+ingress"]`. Each entry names one node, `app-dev`, `app-prod`, or `app-prod-2`, at most once. After a `+` it may add the role `ingress`. `app-prod-2` needs `app-prod` in the same list. A role that conflicts with the node's base role, such as `app-dev+ingress`, is refused, by the [role compatibility](/reference/node-provisioning#role-compatibility) rules. The format takes more roles once one is proven to converge on a test node. Subtask creation and group creation keep the declaration. Only a subtask that has not started can change it.

The subtasks of a group declare at most two test nodes together, as many as a large VM holds. In the spike, a 12 GiB VM was full with two, so a third would run it out of memory. A group that declares more is refused with `tasks.invalid_topology`.

The Gateway validates a declaration once, when a request gives it. It trusts a stored declaration. So a node or role that leaves the format later never fails a tick or a response for a subtask that declares it; the harness refuses that node when the topology is prepared.

The group's topology is the union of its subtasks that are not cancelled: each node once, with every role any subtask adds to it. It decides the [VM size](#size-of-a-task-vm) at the claim.

### Prepare the topology

Orbit prepares the group's topology in the VM after the group's baseline check, because the check's Project setup installs what the harness needs, such as `apps/e2e/vendor`. Orbit admits the topology before each turn that it prepares for a subtask that declares one:

- the implementer's first turn;
- each relay of review findings to the implementer;
- each relay of the reviewer's answer to the implementer, after a consult or a direction relay;
- each review request;
- each rubric reminder.

Every other turn in the subtask follows one of these turns: a consult, a direction relay or review, a resolution, a deliverable correction, a nudge to continue, and the resume of an interrupted turn. These turns do not admit the topology again.

To prepare the topology, the Gateway calls `task-vm.start-topology` and polls `task-vm.topology-status` on each tick. The subtask waits meanwhile with `Task VM: preparing the topology <nodes>.` The Rust agent starts the systemd unit `orbit-task-topology-<run>` as the managed account, with its home and workspace checkout. The unit runs the compiled `task-topology` service:

| Step | What happens |
| --- | --- |
| `host-setup` | `sudo bin/e2e-topology-host-setup`: adopts the VM's clone of the topology seed as the Incus pool `topology-seed`, and installs the [isolation table](/reference/incus-topologies#run-topologies-nested-in-a-task-vm). |
| `acquire` | `bin/e2e-topology acquire TASK-<group> <checkout> --nodes=<union> --replace`, which first releases the topology the VM still holds from an earlier subtask. It copies each Node from `node-template`. After a failure it runs once more; the log records the retry. |

A medium or large VM [boots with a clone](#incus-provider) of its base image's topology seed. So preparation installs no package, copies no image, and creates no pool. A VM without the clone fails at `host-setup` with `e2e-topology-host-setup: this machine has no topology seed disk; only a task VM of a size that runs a topology boots with one.` The subtask then asks for assistance.

The base image already put `orbit` in the `incus-admin` group, so agents can run `bin/e2e-topology`. The row stores the union in `topology`, its state in `topology_state` (`preparing` or `ready`), and the run in `topology_run`.

A ready topology serves every next subtask whose declaration it holds. A subtask that declares more starts the unit again, which releases the topology and acquires the new union; the adopted pool stays. A [parked](#park-and-resume) or resumed VM forgets its topology, because its test machines stop with it, and the next of the turns above prepares it again.

A VM can also restart on its own. So before each of the turns above in a subtask with a ready topology, Orbit reads the run's state. The run records the VM's boot ID, and a run from an earlier boot is `stale`. Orbit then prepares the topology again.

When the unit fails, or stops without a result, the subtask asks for assistance with the step and the end of its log. The row forgets the run, so a resolution prepares again. A unit runs for at most 75 minutes. A start stops the units of earlier runs first, so one preparation runs at a time. When the start call fails, Orbit reads the run's state: a unit that runs or finished is the run, and only a run without a unit fails. Host setup gets the Gateway's VPN subnet, so its input rule covers the VM's own address.

The agent holds one start lock for the VM. A repeated start leaves an active, queued, or unreadable run alone. Without a result, status stays `running` until systemd and its cgroup prove the unit has stopped. The service writes its result atomically under the account's `.local/state/orbit/task-topology`, with the step, elapsed seconds, boot ID, and last 40 log lines. The returned log is bounded to 16 KiB. Project harness commands run as the managed account; only the existing host-setup command uses sudo.

### Prove work on a topology

The implementer and the reviewer use the topology with `bin/e2e-topology`, as the [using-incus-topologies](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill describes. Their prompts name the declared nodes. They never acquire or release it.

The reviewer proves each deliverable on the declared nodes with `bin/e2e-topology exec TASK-<group> NODE --argv=JSON --record=LABEL`. The harness writes the node, the command, the exit code, and the output to the [evidence log](/reference/incus-topologies#evidence-log). When the reviewer approves a subtask that declares a topology, Orbit reads the newest MiB of the evidence. The approval passes only when the evidence holds, for each node the subtask declares, a record that counts. Otherwise Orbit reminds the reviewer with the nodes that lack evidence, and the subtask is not approved.

The [evidence log](/reference/incus-topologies#evidence-log) defines the JSON record. The Gateway sends the checkout path to `task-vm.read-topology-evidence`. The agent reads at most the newest MiB of the fixed `.e2e/evidence.jsonl` file as the managed account. A missing file supplies no evidence. The Gateway skips partial or malformed lines and applies the review rules. A record counts when:

- its command exited 0;
- it started after the implementer's last handoff and before Orbit reads the evidence;
- its command does work on the Node. `true`, `:`, `false`, `exit`, `echo`, `printf`, `sleep`, and `yes` do not count, including inside `sh -c` or `bash -c`.

The gate is a cooperative check, not a control against forgery. The agent owns the VM and can write the file directly. The gate catches a reviewer that did not run anything on a declared node, and a person audits the records.

Orbit adds the counted records, with their time, node, label, and command, to the approval, and so to its commit, for a person to audit. Appending to `.e2e/evidence.log` is not a workspace change: the file is ignored, and the reviewer's prompt says so.

`task_vms.topology_paths` is the safety net. It maps a Project ID to path patterns in the syntax of [file deliverables](/reference/tasks#deliverables). For Orbit, list its Node, agent, guest, deployment, and routing code, for example:

```json
{"46": ["apps/agent/**", "apps/e2e/resources/guest/**", "apps/gateway/app/{Domain,Infrastructure,Actions}/{Nodes,Deployments,Routes,Caddy,WireGuard}/**"]}
```

Orbit checks this when the handoff check of a group passes. It refuses the handoff when the group's own changes touch a matching path and no subtask of the group declares a topology. It compares the candidate tree with the merge base of HEAD and the current `origin/main`, including uncommitted files. Changes inherited from main do not count; the group's own changes, including merge resolutions, still count. An unreadable merge base or diff fails the handoff. See the [handoff rubric](/reference/tasks#execution-ownership).

The reminder starts with `This subtask changes paths that need real machines`. The implementer reverts those changes, or ends its turn blocked so the operator can add a subtask with a topology.

## VM power

Task group responses report `vm_power` separately from task status. For a [task VM](#task-vms), it is `running` while the VM is `ready`, `stopped` while it is [`parked`](#park-and-resume), and `destroyed` once it is `destroyed`. In the other states it is `null`, and the group's wait reason names the state. The CLI shows this value as **VM power** when it is not `null`.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. A task VM with `preview` is never [parked](#park-and-resume). Setting `preview` does not start a VM that is already parked. The next work for the group starts it.

Use `orbit tasks:create --preview` or `orbit tasks:update <group> --preview`. Use `--no-preview` on update to release the preview. The two update flags are mutually exclusive. The API accepts JSON booleans; omitted updates preserve the current intent.

The former lifecycle and standalone Incus admissions for Task VMs return `operation.kind_unavailable` (409) before reading a host or recording an Operation. Standalone enrollment uses `StartTaskVmEnrollmentOperationAction`; provision parents compose the same native lower steps directly. The old enrollment job and runtime transport/contract registrations are removed. Private material for the model router requires the admitted top-level router Node to equal the private binding owner and the configured issuer owner.

Admission holds the existing model-router teardown lock through its outer database transaction, including the router-ready check and model-key reservation. Teardown sees every committed reservation before it can disable the router. This admission fence does not replace the shared native behavior lock that serializes API-key list changes.
