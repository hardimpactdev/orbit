---
title: "Compute drivers"
description: "Incus task VMs for every Project."
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

Schedule log viewers use the owning Node and the fixed Schedule journal unit. Opening a [live log stream](/reference/live-logs) does not provision a task VM or run a workflow.

Every task group runs in its own [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster. This holds for every Project, `orbit` included. See [Orbit Project](#orbit-project).

Task VMs are off: nothing [configures them](#configure-task-vms), and no task group starts one.

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
| `instances` | The two [shared folders](/reference/development-containers#shared-folders) of an Instance container host, `runtime` and `agent`, and the runtime folder's [static PHP build](#static-php-build), `php`. See [Shared Instance folders](#shared-instance-folders) |

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

No catalogued tool is `internal` today: the Pi build, its last user, [left the recipe](/reference/old-paths#the-pi-agent-server).

A profile's effective set is the union of `common` and its own list, sorted by tool identifier. A `php:<version>` entry expands to that version's PHP set. Profiles cannot refer to other profiles, so role inheritance cannot hide software or form cycles. A multi-role Node's set is the union of its roles, not the base image set.

### Shared Instance folders

The `instances` object pins the two shared folders of an [Instance container host](#prepare-an-instance-container-host). Each of `runtime` and `agent` maps a part name to one part. A part either pins its own artifact, as a tool does, with `version`, `artifacts` and `bin`, or names a recipe artifact `tool` with `tool` and `bin`, so that a Node and its containers share one pin. `bin` maps each program the part gives the folder to a relative path inside the unpacked artifact. A program name appears once in a folder, and a path never leaves the artifact.

A part that pins its own artifact may leave `bin` out: it gives the folder files that a container reads by path, and no program.

| Folder | Parts |
| --- | --- |
| `runtime` | `node`, `bun` and `deno`, from the recipe's tools; `composer`, the release phar; `ripgrep`; `php-data-bridge`, with no program; `vite-plus`, the recipe's `vp-cli` release, whose `vp` runs from the folder's [Vite+ home](#the-vite-home). Each published [static PHP build](#static-php-build) adds `php/<version>`, with `bin/` and `modules/` |
| `agent` | `t3` and `conn-server`, the release tarballs; `claude-code` and `codex`, the npm packages of the Linux x64 builds; `chrome-headless-shell`, the Conn preview browser ZIP, which agent-browser opens too; `agent-browser`, the release's Linux x64 binary |

#### The Vite+ home

`vp` runs only from a home that holds its locked `vite-plus` package and its Node, and it fetches both on first use. The host builds that home once; containers link its runtime files read-only and use their egress proxy to fetch Project package managers. The home holds absolute paths, so the host builds it at its final path, `/opt/orbit-runtime/vite-plus/<digest>`. The digest names what goes into it: the `vp-cli` release, the [Vite+ runtime lock](#bump-vite) and the recipe's Node release.

The folder install builds the home the way a Node builds its Vite+ store. It copies the release's `vp`, installs the locked package with the folder's own npm and `npm ci --ignore-scripts`, and links the `node`, `npm` and `npx` shims. Then `vp env default` and `vp env install` make the recipe's Node the home's Node, and the install checks that `vp env exec node` reports it. `vp` checks the signature of the Node release it downloads; Orbit never sets `VP_NODE_SKIP_SIGNATURE_VERIFY`. `.complete` comes last; the next run removes a home without it and builds it again.

Each runtime folder version links `vite-plus-home` to its home and `bin/vp` to `vite-plus-home/bin/vp`. A home goes once no folder version links it and no process maps it. A process maps the home's own path, so a running `vp` keeps its home after its folder version went. The runtime folder must have a Vite+ part: every container links its home.

Every process in a container gets `VP_HOME=/var/lib/orbit/vite-plus-home` and `VP_NO_UPDATE_CHECK=1`: the Instance profile sets them for `incus exec` sessions, systemd's `DefaultEnvironment` for units, and `/etc/profile.d` for login shells. `InstanceImage::RuntimeEnvironment` holds the one list. The container home is writable by `agent` and `orbit`, with the workspace's group and default ACL. Its `bin`, `js_runtime`, `node_modules`, `recipe` and `config.json` link into `/opt/orbit-runtime/current/vite-plus-home`; its cache and downloaded package managers stay in the container. Thus `vp install` can fetch the Project's pinned manager, such as Bun, without changing the shared runtime. A seed snapshot carries those downloads into its clones. `vp` never checks for a release of its pinned copy. The profile converge adds and changes keys; it removes none.

The object is not part of the [recipe identity](#recipe-identity): no task image or Node profile holds the folders, so a new T3 or Claude Code rebuilds no image. A Node gets the new pins with its next converge.

### Swamp worker

The one task VM base profile includes the official Swamp binary, pinned by version and SHA-256. Its release matches the orchestrator's `swamp serve`: `20261114.210101.0-sha.537e03d1`. Roll out a reviewed recipe pin and matching serve release together. Image verification checks the installed binary against its artifact digest. No fleet or account credential belongs in the image.

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

OpenTelemetry is installed for the `php.opentelemetry` profiles and enabled in PHP-FPM only. The `app-dev` profile needs its `OpenTelemetry\Instrumentation\hook()` API for php-data-bridge, which instruments web requests and loads on a Node in PHP-FPM only. The installer always disables OpenTelemetry in the CLI, including links enabled by Sury's package hooks. This preserves the CLI extension parity with CI that PHPStan's shared result cache needs.

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
2. No task VM host refreshes its base snapshot any more: the refresh has no trigger ([refresh the base snapshot](#refresh-the-base-snapshot)).
3. The next [PHP step](/reference/php-runtime#php-build) of an Instance brings its versions to the recipe.
4. Hosted CI installs the new build in its next run. See [runner updates](/reference/implementation-loop#one-php-build-in-ci) for the pre-merge operator steps.

### Static PHP build

The runtime folder of a [development container](/reference/development-containers#php) holds a static PHP CLI and FrankenPHP for each PHP version. Both come from one [static-php-cli](https://static-php.dev) build, so web requests and commands run the same PHP with the same static extensions. The same build gives the CLI-only extensions as shared files, which only the PHP CLI loads. No published release ships both binaries from one build, so Orbit builds them. [ADR 0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov) moves the build from musl to glibc and adds the CLI-only PCOV.

The recipe's `instances.php` section defines the builds:

| Field | Contract |
| --- | --- |
| `static_php_cli` | The full static-php-cli commit that runs the build, on its `v3` line, which FrankenPHP's own static build uses |
| `frankenphp` | The exact FrankenPHP release, such as `1.13.5` |
| `without` | Extensions of the [PHP build](#php-build) that the static build leaves out: `ffi` |
| `cli_extensions` | Extensions of the PHP build that the build makes shared, for the PHP CLI only: `pcov` |
| `versions.<X.Y>.php_version` | The exact PHP release, such as `8.5.12`. It can differ from the Node pin's patch release for a while, because a new release needs a new build |
| `versions.<X.Y>.build` | The build id: a SHA-256 over the PHP release, FrankenPHP, the static-php-cli commit, the static and CLI-only extension lists, `tools/static-php/craft.yml` and `bin/static-php-build` |
| `versions.<X.Y>.url` | The release asset `static-php-<php_version>-<build id prefix>-linux-x86_64.tar.gz` under the `static-php-<php_version>-<build id prefix>` release |
| `versions.<X.Y>.sha256` | The asset's SHA-256, or `null` until the build is published |

static-php-cli resolves the sources of the libraries and PECL extensions when it builds, so two builds of one id can differ. The archive's `sources.json`, static-php-cli's download record, names each source's version and hash. The published SHA-256 is what fixes the bytes a Node installs.

A build's static extensions are its PHP version's `extensions` less `without` and less `cli_extensions`, so the Node build and the static build share one list. Today that is every extension Orbit's Laravel projects require, from their `composer.json` files and locks: `intl`, `exif`, `sodium`, `pcntl`, `sockets` and the rest, with `pdo_pgsql`, `pdo_mysql`, `pdo_sqlite`, `redis`, `igbinary`, `imagick`, `gd` and `opentelemetry`. Both binaries load OpenTelemetry, because they share one build; on a Node only PHP-FPM loads it. The section is not part of the [recipe identity](#recipe-identity): no Node profile or task image holds the static build.

static-php-cli builds `pcov` only as a shared extension, so it is a CLI-only extension. The archive holds each CLI-only extension as `modules/<name>.so`. Only the PHP CLI loads it, through [`php-cli.ini`](/reference/php-runtime#pcov-in-the-cli-only).

`tools/static-php/craft.yml` is the static-php-cli build definition. It builds the `cli` and `frankenphp` SAPIs together with ZTS, for the baseline x86_64 CPU, without static-php-cli's suggested extensions. Each binary links everything statically except glibc. `bin/static-php-build` sets static-php-cli's target `SPC_TARGET=x86_64-linux-gnu.2.17`: its Zig toolchain links against glibc 2.17, which is older than Ubuntu 26.04's, so the binaries run in every container. It builds the `cli_extensions` as shared extensions. Its libraries give `gd` its image formats and `curl` HTTP/2 and HTTP/3. PHP reads its configuration from `/etc/orbit/php` and `/etc/orbit/php/conf.d`.

`bin/static-php-build` runs the build:

| Command | Effect |
| --- | --- |
| `definition` | Prints each version's build id, release tag, asset and URL |
| `verify-pins` | Fails when a recorded `build` or `url` is not the definition's |
| `build --version X.Y --output DIR` | Fetches the pinned static-php-cli and FrankenPHP sources, runs `spc craft`, and packs `bin/php`, `bin/frankenphp`, `modules/pcov.so` (one `modules/<name>.so` for each of the `cli_extensions`), `build.json` and `sources.json` with a `SHA256SUMS` file |
| `check --version X.Y --bin DIR` | Fails unless both binaries report the pinned PHP release and load exactly the build's static extensions, and the CLI, given the `cli_extensions`, loads exactly the static extensions and those |

The check is the Ansible module `orbit_static_php`. The [runtime folder](/reference/development-containers#shared-folders) holds each published build as the part `php/<version>`, unpacked to `php/<version>/bin` and `php/<version>/modules`, and its install runs the check on each one before the folder version is whole. A build whose `sha256` is still `null` is not in the folder.

FrankenPHP runs its probe through `frankenphp php-cli`, and the CLI runs it with `php -n`: both must load exactly the static extensions. The CLI then runs the probe with `php -n` and `-d extension=<part>/modules/<name>.so` for each of the `cli_extensions`, and must load exactly the static extensions and those. FrankenPHP's own SAPI module, such as `frankenphp-cli`, is not an extension, so the check leaves it out for FrankenPHP only.

`frankenphp php-cli` takes the SAPI name `cli`, so it reads `php-cli.ini` and the `conf.d` scan directory, and it has no `-n`. The probe prints the ini files that PHP read, and the check fails when one binary read any. So the check runs only where the build's config path, `/etc/orbit/php`, does not exist: on a Node and on the CI runner, not in a container. The probe runs in an empty folder, because `frankenphp php-cli` also looks for its ini file in the working folder. Output that PHP prints at startup, such as a warning that it cannot load an extension, also fails the check.

#### The static PHP workflow

The `Static PHP build` workflow runs when the recipe, the build files or the workflow change. It requires every pin to name its definition. Compilation exceeds the [CI budget](/reference/implementation-loop#ci-budget), so a pull request only validates the pins. On main, a push or a manual run builds the versions whose release does not exist yet. The proof job keeps each archive it proved in the Actions cache under its build id, so a change to the proof alone does not build again.

A proof job unpacks the archive, checks its SHA-256 and runs the extension check. Then `tools/static-php/prove.py` proves the build. It runs from the run's own commit, so a manual run from main uses main's copy, not the built branch's. A branch's change to its own proof is never what proves it. The branch's own code still runs in the job, so run only a reviewed ref.

The proof has four parts:

- **Linkage.** `php`, `frankenphp` and each CLI-only extension in `modules/` may need only glibc's own libraries, no newer than Ubuntu 26.04's glibc. A fully static binary also passes.
- **CLI only.** The proof writes the `php-cli.ini` that a container has. It holds one `extension=` line for each CLI-only extension in the archive's `build.json`. `php -m` with it must list each one.
- **Not in FrankenPHP.** `frankenphp php-server` serves a page with the same configuration. The page must run under the `frankenphp` SAPI and load no CLI-only extension.
- **Coverage.** After the Gateway install, Pest runs with that `php-cli.ini`. It runs `tests/Unit/Infrastructure/MachineSoftware` with a clover report. The report must cover lines of `StaticPhpBuild.php`.

The job also runs `php -v`, `php -i`, `php -m` and `php -d memory_limit=1G`, installs the Gateway with Composer and its scripts, runs Artisan, and serves the Gateway's `/up` with `frankenphp php-server`. A build whose `build.json` names no `cli_extensions`, such as a fully static musl build, fails the proof.

On main, from a push or a manual run, the publish job creates the release with the archive and `SHA256SUMS`. It never replaces a release: a published SHA-256 may already be pinned. Pull requests never build, prove or publish.

A manual run from main takes an optional `ref`: a branch of this repository or a full commit SHA. The run resolves it to one commit, then builds, proves and publishes that commit's definition, and the release targets that commit. The workflow file is always main's. Without `ref` the run builds its own commit.

So a pull request that changes a build records the published SHA-256 before it merges, and the new build id and its SHA-256 reach main together. After the merge, main's push run finds the release and builds nothing. Its code publishes from main, so run only a ref you have reviewed. Only main's own commits fill the cache.

#### Change a static build

A build whose `sha256` is `null` is not in the runtime folder. A release of a new build id without its SHA-256 would remove that PHP version from every container. So one pull request records both:

1. Change the definition: the `instances.php` section, the PHP build's extensions, `tools/static-php/craft.yml` or `bin/static-php-build`.
2. Run `bin/static-php-build definition`. Record each changed version's `build` and `url`, set its `sha256` to `null`, and push the branch. The pull request's workflow validates the pins.
3. Run the workflow from main with the branch as `ref`: `gh workflow run static-php.yml --ref main -f ref=<branch>`. It builds, proves and publishes the new definition.
4. Record the published SHA-256 from the release's `SHA256SUMS` in the same pull request. Every `sha256` must be set before the merge.

### Recipe identity

The recipe identity is `sha256:<digest>`, computed from the validated document's canonical JSON after removing internal-artifact filenames. Loading a recipe resolves its `agent` field from the Gateway delivery manifest before hashing. This field contains the delivered Linux SHA-256 digests keyed by platform; the source recipe uses `null` and carries no agent version or artifact pins. Changing either Linux digest changes the identity. macOS artifacts and delivery storage locations do not. Stored build recipes retain the resolved map, so resuming a build cannot silently substitute another agent. A build or Node convergence refuses a missing or mismatched target pin before applying software.

Internal source commits and digests remain in the identity; relocating identical internal bytes does not change it. Canonicalization recursively sorts object keys by byte order, preserves array order, emits no insignificant whitespace, leaves slashes and Unicode unescaped, and adds no final newline. Only JSON integers, strings, arrays, objects, booleans, and null are permitted; schema fields further restrict their types. Duplicate keys are rejected before hashing. The identity is derived, not stored as a self-referential field in the recipe.

Whitespace and object-key order do not change the identity. Changing a snapshot, version pin, external artifact URL, `artifact_mirror`, source commit, digest, role assignment, schema version, or list order does. The identity covers all profiles together; there is no independent version counter for each role and no Git commit SHA masquerading as the recipe version. Git history provides provenance, while the content hash names the exact software intent even when the same recipe appears in another commit.

A [refresh](#refresh-the-base-snapshot) recorded the same identity on the build row of the base snapshot; the refresh is removed and the build rows went with the table `task_vm_image_builds`. Schema version `1` and a short display hash are not substitutes. The table `task_vm_image_recipe` that held the current recipe is dropped ([old paths](/reference/old-paths#the-task-vm-tables)).

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

Git build watch retries upstream 5xx and connection failures with four attempts, a 15-second request timeout, and waits of 10, 20, and 30 seconds. The whole `check` job has a 10-minute timeout across all pages and retries; the separate publishing job has a 5-minute timeout and creates issues without an assignee. Its `check` job is an advisory update signal, not a required merge check; persistent failures remain visible. Network failures are failures to verify, not evidence that a release is invalid or permission to choose another version. A successful structural-only show is not a successful availability check.

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

The task VM lifecycle is removed: no claim, resume or release runs, and every task runs in a [task copy](/reference/tasks#task-copy) ([old paths](/reference/old-paths#the-pi-task-loop)). The tables `task_vms`, `task_vm_image_builds` and `task_vm_image_recipe` are dropped ([old paths](/reference/old-paths#the-task-vm-tables)). This section describes what a task VM was, and what the Swamp workflows still hold until they go. The Node setting `task_vms` and the task VM settings are removed ([old paths](/reference/old-paths#task-vm-settings)).

A host had a `containers` setting with separate container places and a placement switch. They were built for a separate task container runtime, which ADR 0250 dropped: tasks move into [Instance containers](/reference/development-containers#tasks-in-an-instance-container), and the setting is removed with `task_vms`. Task VMs are [off in the live fleet](/reference/old-paths#off-in-the-live-fleet). Before that, every task group ran on a task VM.

A task VM is an Ubuntu 26.04 VM on an Incus host. It is a copy of the [base snapshot](#the-base-vm-and-its-snapshot) of its host, which the removed base refresh converged to the recipe and which holds the `app-dev` tool set. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the normal Instance and task paths, pinned to that Node. Task VMs run only on the Incus hosts Beast and Shark. When neither host has capacity, the group waits.

The host's image, instance-name, and host-wide resource inventories use the typed agent commands `incus.images`, `incus.instances`, and `incus.inventory`. Rust validates complete Incus output and returns fingerprints, aliases, and resource names. Instance-name reads first prove that the configured project exists; a missing project cannot count as an empty host. Host-wide inventory includes every project and storage pool so the Gateway can compare it with recorded ownership. These reads take no mutation lock. Invalid or incomplete output refuses with `task_vm.invalid_host_output`; a failed Incus call returns `task_vm.host_command_failed`.

The first phase of every base refresh prepared its host through Ansible; the refresh is removed, and this section says what it did. It validates existing storage, project network scope, and bridge address before changing them, then converges the pool, project, bridge, ACL, profile, stock image and forwarding rule. The agent observes only; it does not prepare hosts.

`incus.volumes` reads the custom volumes in the configured project and pool. Swamp owns volume changes through keyed Operation children; the Gateway keeps volume ownership and inventory reads.

`incus.vm-state` returns one VM's running state, explicit `settled: true`, guest bridge address, and original image fingerprint. It refuses a VM unless Incus reports `Running` or `Stopped`.

`incus.vm` creates, starts, stops, or deletes that VM under one target lock. The agent owns exact-name matching, Incus output validation, clean shutdown followed by forced stop, and the observation after a failed mutation. Create intent supplies the Gateway public key, image claim, resource limits, and topology requirement. The agent renders the fixed cloud-init account and QEMU configuration, clones a required seed before launch, and refuses an existing VM with a different claimed image. Delete removes the VM before releasing its seed clone. The Gateway keeps placement and resource-size policy.

Image builders and smoke VMs use the same `incus.vm` boundary. Their `create_image` intent selects the source image, account key, resource limits, and builder account mode. Rust renders cloud-init, attaches an Incus cloud-init configuration disk, and initializes the VM with NIC isolation. Before its first start, Rust removes the source image’s NoCloud seed templates. The disk supplies the account configuration at power-on, so cloud-init cannot run with an empty datasource while the Incus agent copies seed files and requests a reboot. This applies to stock builders, smoke VMs, and task VMs.

A stopped VM receives the same metadata preparation before it starts. A repeat preserves a VM from the requested image and refuses a different image. `remove_instance` removes an exact VM without changing storage volumes; the Gateway's existing image and orphan sweep decides which names it may remove. An absent VM counts as removed.

No agent command reaches a guest before enrollment. Nothing built an agent guest, so S10d removed the guest command runner and its `incus exec` transport, with that transport's refusal `task_vm.git_credential_refused` ([old paths](/reference/old-paths#the-guest-agent-chain)). The command runner reaches only a Node, through the [Node transports](/reference/node-agent#transports).

`incus.instance-images` reads the original image fingerprint of each instance.

The template builder verifies the guest account before it becomes a template. Cloud-init uses the configuration disk on its first boot.

`guest.cloud-init` and `guest.fingerprint` stay in the frozen agent contract: `guest.cloud-init` returns readiness from the guest’s complete cloud-init result and reports bootstrap errors as typed refusals, and `guest.fingerprint` reads and validates the ed25519 host fingerprint. No Gateway or Swamp path sends them, because the guest transport is removed ([old paths](/reference/old-paths#the-guest-agent-chain)).

### From claim to workspace

No task start claims a place any more: a [task start](/cli/task/start) makes a task copy. The workflows that took a place, copied the [base snapshot](#claims-copy-the-base-snapshot), added the hub peer and converged the VM (`operation-task-provision`, `task-vm-start`, `task-vm-hub-peer`), and the ones that released it (`operation-task-vm-slot-release`, `task-vm-slot-phase`, `task-vm-hub-peer-remove`), are deleted ([old paths](/reference/old-paths#the-pi-task-loop)). The steps below describe the old claim.

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

No release runs any more: `task_vm.slot_release` left the registry with the task VM lifecycle. An old row keeps its state. A cancel of a group without a task copy ends it at admission with no run; a group that still holds a task VM workspace refuses the cancel with `tasks.workspace_ownership_refused`, and its complete asks for `removal_failure` assistance ([A task VM workspace](/reference/tasks#a-task-vm-workspace)). Old release Operations stay as history ([kinds that left the registry](/reference/operations#kinds-that-left-the-registry)).

### Place task VMs

Placement is serialized by the Gateway's SQLite transaction. The claim checks for an existing VM, counts occupied places, records a new VM, and queues its creation after commit. The removed slot pool selected the active host with the most free places. Hosts with equal capacity use their configuration order.

Every host has two independent fixed pools, configured with `task_places` and `ci_places`. Each live VM holds one place in its admitted pool until deletion is confirmed, including failed or stopped VMs. Task provision used the task pool. Base-refresh proofs use the CI pool. A [`proof:run`](/cli/proof/run#the-run) takes no VM place: it runs in copies of the proof Project's default, and its shards, budgets and verdicts are described there.

The capacity migration sets existing Beast and Shark hosts to two task and two CI places, and Sabre to zero task and two CI places. It removes `max_vms`, `task_max_vms` and `ci_only` while preserving other Node settings.

No entry admits a task VM any more: each answers `tasks.vm_runtime_removed` ([Task runtime](/reference/tasks#task-runtime)), and nothing waits for a place. Every task VM copied the base, which carried only the proof Project's checkout. No read shows the places any more: the task places view of `task:status` is removed. Lowering a pool size deletes no VM.

A task publishes its PR and releases its VM, workspace, keys and peer. An [idle](/reference/tasks#release-an-idle-task-vm) group releases its VM too. Later work claims a fresh place and restores the task branch from the base. See the [capacity model](/reference/command-development-pipeline#capacity-ideal-state).

Each old row records its runtime, `vm` or `container`. The host keeps one recorded base per runtime.

Every new VM copies the base and has the Large profile: 6 vCPU, 12 GiB of memory and a 60 GiB disk. VM size does not multiply its capacity charge.

### Image permissions and verification

Image installer services use `UMask=0022`, matching recipe execution on Nodes. Tool directories and package state must remain readable by the guest. Rust and Cargo must also report their pinned versions. Guest wrappers use the compiled agent contract to identify mutations: smoke and recipe verification reads receive only their declared inputs; mutations retain keyed effect receipts. Builder, smoke and seed boot observations stay pending until their own agent installation child succeeds. Only then does the Gateway probe cloud-init; real probe failures remain errors.

Verification runs Node, pnpm, Bun, and Vite+ launchers only as `orbit` and compares their versions with inspection. Other guest-facing binaries must report the same version as root when run as `orbit`. Launcher caches remain owned by `orbit`.

Existing base snapshots and VMs retain their permissions. No refresh can be started any more: task VMs are off and their refresh triggers are removed ([old paths](/reference/old-paths#off-in-the-live-fleet)).

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

Swamp publishes the [checked task candidate](/reference/tasks#publish-an-external-group) from Node 209, which receives the commit from the task copy as a bundle. The publish step asks the Gateway for a repository installation token when it starts, and the Gateway writes it to the vault. The Gateway projects the result; it runs no push job or mirror import. The VM never receives the token.

The native action uses an empty temporary bare Git repository with the workspace object store as an alternate. Credentials reach only Git in that trusted directory. The action ignores repository hooks and user/system Git configuration, permits HTTPS only and refuses redirects. It pushes the exact admitted commit without force and reads the remote tip before repeating a mutation. No repository command receives the credential. Native locking, cancellation and replay follow the [Tasks contract](/reference/tasks#publish-an-external-group).

#### What the Gateway trusts

From the VM, the Gateway takes only commit IDs and pack data. A commit ID must be 40 or 64 lowercase hex characters. Git in the mirror never reads a file from the VM. It runs with these settings: `core.hooksPath=/dev/null`, `core.fsmonitor=false`, an empty `credential.helper`, `http.followRedirects=false`, `protocol.allow=never`, and `protocol.https.allow=always`. A pack from the VM goes through `git index-pack --strict`, which runs the object checks that `transfer.fsckObjects` runs for a fetch. Fetches from GitHub skip them, because an old commit in a real repository can fail them.

Native Swamp publication consumes its repository token through the shared private reader on the admitted worker. The separate Composer setup token described above is a narrow exception for dependency downloads.

No agent command runs through `incus` any more, so nothing refuses a command for the source Git credential it carries. S10d removed that transport with its refusal `task_vm.git_credential_refused` ([old paths](/reference/old-paths#the-guest-agent-chain)). The Gateway's SSH executor sends a command without asking whether its target is a task VM, because no task VM exists ([old paths](/reference/old-paths#the-task-vm-readers)). `project:update` reads no task VM either: it sends each checkout's preflight the token of the Project's source access.

`GitReadScript` still marks its input as one that carries a credential. A test fails when other code builds the credential header. It also fails when a new file sends remote commands with GitHub access in reach, so each such path gets a review.

The checkout keeps the Project repository URL as `origin`. After setup ends, the agent can run Git there, but its environment holds no setup credential. So it cannot fetch from or push to a private repository.

### Converge a running task VM

The task VM role and its base-build graph are removed because nothing calls them. Normal Node convergence keeps its package and runtime helpers under `node-converge-*` names. The [proof replay chain](/reference/old-paths#the-unreachable-swamp-vm-paths) stays until replay is ported.

### Park and resume

No VM parks or resumes any more: `task_vm.resume` and the Artisan command `task-vms:resume` are removed, and no admission wakes a VM. A `parked` row stays as it is. Old resume Operations stay as history.

### Sweep task VM hosts

No sweep runs any more. The Gateway job `SweepTaskVmHosts`, its 30-minute schedule, the 5-minute [idle release](/reference/tasks#release-an-idle-task-vm) schedule and the per-minute observation of parked VMs are removed with the task VM runtime ([Task runtime](/reference/tasks#task-runtime)). A VM row that a failed start or release left stays until an operator removes the leftovers by hand.

A VM that dies on an Incus host stays in its row until its group ends; no sweep fails the row. Cancel or complete the group of a missing VM to release it, as for [a release](#release-a-task-vm).

[Doctor](/cli/doctor) does not inspect task VM hosts: it has no code for an orphaned, missing or stray VM. The operator lists a host with `incus list --all-projects` instead.

Doctor reads no task VM row. It reports no `node.task_vm_parked` and inspects every Node by its families ([old paths](/reference/old-paths#the-task-vm-readers)).

### States

A task VM row had six states. The table and its states are dropped ([old paths](/reference/old-paths#the-task-vm-tables)).

| State | Meaning |
| --- | --- |
| `provisioning` | Orbit is creating, enrolling, or preparing the VM |
| `ready` | Pi runs, and Orbit can create the workspace |
| `parked` | Orbit stopped the VM, or is starting it again. The disk stays. See [Park and resume](#park-and-resume) |
| `failed` | A job failed. The row kept `error_code` and `error_message`. The group shows the error as its reason and asks for assistance |
| `destroying` | Cleanup has started |
| `destroyed` | The VM and the Node were gone. The row stayed for audit until the table was dropped |

Orbit derives progress inside `provisioning` from facts: the VM exists, the row has a Node, the Node is active, and the Pi Process exists. A group has at most one task VM that is not `destroyed`. `failed` is final for the row. Cancel the group to destroy the VM.

### Jobs

No task VM job is left: `ReleaseTaskVmSlot` is removed with the release, and the `task-vms` queue and its supervisor went before it ([Gateway queue](/reference/gateway-queue#queues)).

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

No provider class is left in the Gateway: the observation provider and its host reader had no caller and are deleted. No task start copies the base snapshot any more: the `start` and `delete` play of `task-vm-host.yml` is deleted with the task VM start and release ([old paths](/reference/old-paths#the-pi-task-loop)).

A [medium VM](#incus-provider) gets `limits.cpu=4`, `limits.memory=8GiB`, and a 40 GiB root disk instead, and a large VM `limits.cpu=6`, `limits.memory=12GiB`, and a 60 GiB root disk. A task copy inherits the boot config of its base snapshot.

A medium or large VM boots with a clone of the [topology seed](#the-base-vm-and-its-snapshot) of its base, which the claim's recorded base snapshot carries.

### Incus LTS packages and upgrades

Incus uses Zabbly's signed `lts-7.0` repository for Ubuntu 26.04. Each artifact records its exact Debian `version`, because architecture build timestamps differ. The tool `version` names their shared 7.0.x point release. The recipe pins `incus`, `incus-base`, and `incus-client` for amd64. Zabbly bundles QEMU, qemu-img, virtiofsd, firmware, and the agent. Hosts remove the obsolete separate Ubuntu `incus-agent` package. Rebuilt images start from stock; their stock agent unit runs `/run/incus_agent/incus-agent`, supplied by the outer host. The installer does not replace that unit.

`bin/recipe-artifacts bump` authenticates Sury and Zabbly indexes and updates their complete package sets together. It verifies the pinned signing key digests, `InRelease` signatures, package index hashes, and Incus Debian file digests. Only changed Incus artifacts are downloaded; merge verification reads signed indexes without downloading Debian files. The existing [artifact mirror](#the-recipe-artifact-mirror) retains the same content-addressed Debian files used by Git and Sury. Managed machines have no rolling Zabbly APT source. Review the [7.0 release notes](https://discuss.linuxcontainers.org/t/incus-7-0-lts-has-been-released/26641) and [7.0.1 fixes](https://discuss.linuxcontainers.org/t/incus-7-0-1-lts-has-been-released/26942) for new requirements. The 7.0 line is supported until June 2031.

The bump tests own fixed Incus versions and fake indexes, with distinct builds per architecture. Artifact and snapshot updates therefore change no expected test result. Tests that decode or send the recipe compare with the document under test.

The `ci-runner` role selects the complete Incus package set. Configured task VM hosts also add that set to their role-selected recipe tools. Other roles do not select Incus merely because it is installed; inventory those hosts separately before planning an upgrade.

Incus 7.0 requires Linux 6.12, cgroup v2, QEMU 8.2, nftables 1.0, dnsmasq 2.90, and ZFS 2.1 when using ZFS. Ubuntu 26.04 uses kernel 7.0 and cgroup v2. Incus 6.0.5 already uses nftables; 7.0 removes the alternative xtables backend. Orbit's bridge and ACL setup continues to use nftables. The harness's separate iptables rules use Ubuntu's nft backend; its isolation hook precedes either filter chain. Orbit uses neither storage buckets nor OVN.

Orbit uses complete CLI command names and explicit archive destinations, compatible with 7.0's stricter argument parsing. Its regular image archives do not use the changed file-copy behavior. Existing Ubuntu VMs retain their NVRAM filenames. On hosts only, the installer adds firmware aliases in Zabbly's bundled firmware directory. Incus 7.0.1 searches `INCUS_EDK2_PATH` then `/usr/share/OVMF`, while its AppArmor profile permits only `INCUS_EDK2_PATH` when set ([firmware selection](https://github.com/lxc/incus/blob/v7.0.1/internal/server/device/edk2/driver_edk2.go), [AppArmor](https://github.com/lxc/incus/blob/v7.0.1/internal/server/apparmor/instance.go)). Remove the aliases after an upstream release fixes selection and confinement and a VM created on Ubuntu Incus boots without them. Rebuilt images and new VMs do not need these aliases.

1. Drain Shark with the admission procedure below. Inventory every project with `incus list --all-projects`.
2. Save and disable instance autostart with the drain script below, then stop all remaining instances. Take the stopped-state backup below.
4. Verify client and server 7.0.1. Compare project, VM, ZFS, custom volume, and seed inventories with the backup.
5. Boot an old-image guest and a new disposable guest. Check agent exec, stop/start, bridge isolation, and ACL egress.
6. Not possible any more: the refresh that proves and promotes the snapshot has no trigger (see [Refresh the base snapshot](#refresh-the-base-snapshot)).
7. Run one complete topology task group explicitly on Shark. Require seed adoption, Node copies, enrollment, checks, teardown, and clone removal.

The Shark topology group is the **go/no-go point**. The full host 7.0.1 → task VM 7.0.1 → topology Node chain remains unverified until it passes. Failure stops rollout and invokes the rehearsed rollback below before normal admission resumes.

The drain includes every `orbit-ci` container on the host, task VMs, builders, and parked task VMs. Inventory names at rollout time; do not rely on a fixed runner list. Preserve parked guest data and configurations before finishing their rows; retain an offline representative old-image guest for compatibility checks. Do not delete unrelated workloads. Beast's drain also includes every operator-owned VM in every project, including any remaining `adr0223-memory-20261019` VMs. Never clear Beast's task VM settings for this upgrade.

No task VM starts any more, so no admission switch is needed; the task VM settings are removed. Wait for all rows on this host to end, including parked rows, and for image builds to finish. Stop CI dispatch before shutting down runner containers.

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

Every base refresh started with the shared `host-prepare` Ansible phase (the refresh is removed). It checks existing resources before changing them. A missing pool is created from a new `zfs_dataset` when configured; an existing dataset is refused because Incus takes ownership of its source. Without a dataset, Incus creates a ZFS pool on its own loop file, sized by `pool_size` (default `640GiB`). A leftover loop file or symlink at `/var/lib/incus/disks/<pool>.img` is refused untouched, because Incus would truncate it. An existing pool must use ZFS, and a configured dataset must match its source. Existing pools are never resized or replaced.

On a host whose pool source is a regular file, this phase caps the host-wide ZFS ARC at 2 GiB, both now and after reboot. That is about 6.6% of a 30.24 GiB host and leaves room for databases and other services. The phase does not change ARC settings on native-ZFS hosts, including an existing native pool with no `zfs_dataset` setting. It installs the ZFS userspace tools when needed and loads the kernel module before creating a pool. Then it sets up these parts:

- The Incus project, such as `orbit-tasks`, with `features.networks=false`, and the stock image alias `ubuntu-26.04-vm` from `images:ubuntu/26.04/cloud`. Only the creation of the base VM of a host launches it.
- The bridge, such as `orbittask0`, with IPv4 NAT and no IPv6.
- The ACL `<bridge>-egress`, which the bridge gets at creation.
- ACL egress drops private, link-local, CGNAT, and multicast ranges, and allows the rest.
- ACL ingress allows TCP 22 from the bridge address and rejects the rest.
- The project's `default` profile: `eth0` on the bridge with `security.port_isolation=true`, and the root disk on the host's pool.
- The project restrictions `restricted=true`, `restricted.networks.access=<bridge>`, and `restricted.virtual-machines.lowlevel=allow`. The first refresh adds `restricted.snapshots=allow`.
- One host rule: `ufw route allow in on orbittask+ comment 'orbit-task-vms'`.

Task VMs need nothing that the restrictions forbid, except low-level config: medium and large VMs set `raw.qemu.conf` for free page reporting. Only the Gateway sets instance config in the project.

The phase took these values from the host Node's `settings.task_vms`, which is removed ([Configure task VMs](#configure-task-vms)). The bridge and ACL live in the Incus project `default`. The phase preserves unrelated projects, pools and networks.

Orbit reserves bridge names that start with `orbittask`. Every such bridge must carry its egress ACL, because the ufw rule accepts forwarded traffic from all of them. Only the shared host preparation phase creates these bridges, and it attaches the ACL at creation. Never create an `orbittask` bridge in another way.

The restrictions forbid privileged and nested containers, host devices, backups, and every network except the task bridge. Snapshots are allowed because the host's base VM snapshot is the base of every task start ([The base VM and its snapshot](#the-base-vm-and-its-snapshot)). Only a client of the host's Incus API can take a snapshot; a task VM reaches only its guest socket, which cannot. Low-level VM config is allowed for memory reporting.

The dropped egress ranges are `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `224.0.0.0/4`, and `240.0.0.0/4`. Port isolation blocks traffic between VMs on the same bridge. Both are needed. The ufw rule lets bridge traffic pass the host's forward policy, and replies use the existing rule for established connections.

### Prepare a container host

A host whose `settings.task_vms` had a `containers` member also got the container host preparation below, which [Instance containers](/reference/development-containers#nodes) reuse. The same `host-prepare` phase prepares that side, in two task files that run only on such a host:

- `tasks/task-container-host-system.yml` runs before the VM part, because the VM part needs Incus.
- `tasks/task-container-host-prepare.yml` runs after it, because it needs the pool.

A host without the member runs neither file, and its VM part is unchanged. A run admitted before the release has no `host.containers` and counts as such a host.

Before first use, the operator upgrades the host and reboots it into the current kernel with `apt-get full-upgrade` and `reboot`. The phase never does this, because a reboot stops the host's task VMs. The UpCloud template ships an older kernel than the archive.

#### The host system

The system file sets up the kernel and Incus side of the host.

- **Incus.** The host runs the recipe's Incus line, as described below.
- **SCTP.** The `sctp` module cannot load, as described below.
- **Kernel modules.** `wireguard`, `overlay` and `br_netfilter` go in `/etc/modules-load.d/orbit-task-containers.conf` and load now. A container cannot load a module itself.
- **Sysctls.** `/etc/sysctl.d/60-orbit-task-containers.conf` holds the floors in the table below. They apply now.
- **Subordinate IDs.** Root's range in `/etc/subuid` and `/etc/subgid` must hold `(containers.task_places + 1) × 101,000,000` IDs: one isolated range per task container place and one for the base. A smaller range fails the phase with both numbers and changes nothing.
- **ZFS ARC.** It sizes the ARC cap, which never rises. See [the ARC cap](#the-arc-cap-of-a-container-host) below.

A host without Incus gets `incus`, `incus-base` and `incus-client` from the recipe's [pinned packages](#incus-lts-packages-and-upgrades). They are the packages of its architecture, checked by SHA-256. The package's `db:Status-Status` must be `installed`; a hold does not cause a reinstall, and config files left after removal do not count as installed. A host with Incus must run a server on the recipe's line, such as 7.0. The inner Incus of the base uses the same line. The phase never upgrades a running Incus.

The `install sctp /bin/false` rule comes from the [kernel policy](/reference/node-provisioning#kernel-policy) that `node:add` and `node:converge` write. This phase only checks it: `modprobe --dry-run` must show the rule, and the module must not be loaded. A loaded module needs a reboot.

The sysctl floors are the Incus production values for nested systemd, Docker and file watchers. A higher host value stays, such as Ubuntu's `vm.max_map_count` of 1048576.

| Sysctl | At least |
| --- | --- |
| `fs.inotify.max_queued_events` | 1048576 |
| `fs.inotify.max_user_instances` | 1048576 |
| `fs.inotify.max_user_watches` | 1048576 |
| `kernel.keys.maxkeys` | 2000 |
| `kernel.keys.maxbytes` | 2000000 |
| `vm.max_map_count` | 262144 |

#### The ARC cap of a container host

The ARC took 2.6 GB of an 8 GB UpCloud host and left the places short, so a container host caps it. The cap is the lowest of these values:

- one eighth of the host's memory, at least 1 GiB;
- the cap the host already runs, when it runs one;
- 2 GiB, on a loop pool.

The rule never raises a cap. A higher cap would take memory from the host's task VMs, databases and services, and ZFS gives memory back only when the kernel asks. Beast's loop pool keeps its 2 GiB. A native pool with no cap, such as Shark's, gets one eighth of its memory.

The VM part applies the cap now and after reboot, in `/etc/modprobe.d/orbit-task-vm-zfs.conf`. This changes a native pool, whose ARC a VM host leaves alone. When `containers` is removed, a loop pool keeps its 2 GiB cap. A native pool keeps the last cap until the operator deletes the file.

#### The container side

The prepare file sets up the rest. The pool is the host's ZFS pool, which the VM part prepares and requires to be ZFS. On a directory pool a base copy took 55 s. On UpCloud the root disk is ext4 on the whole disk. There the operator creates a zpool on a separate block device and sets `zfs_dataset` to a new dataset in it.

| Part | On a container host |
| --- | --- |
| Bridge | `containers.network`, such as `orbittaskc0`, at the first address of `containers.cidr`, with IPv4 NAT and no IPv6 |
| ACL | `<bridge>-egress`, with the rules of the VM bridge's ACL |
| Project | `containers.project`, such as `orbit-task-containers`, with `features.networks=false` |
| Profile | The root disk on the host's pool, and `eth0` on the bridge with `security.port_isolation=true` |
| Stock image | The alias `ubuntu-26.04-ct` from `images:ubuntu/26.04/cloud`, the container variant |
| Input rules | `orbit:task-containers-dhcp` for DHCP, and `orbit:task-containers-dns-tcp` and `-udp` for DNS to the bridge address |
| Forwarding | The existing rule `route allow in on orbittask+`, which covers the bridge |

The project gets these restrictions:

- `restricted=true` and `restricted.networks.access=<bridge>`.
- `restricted.containers.nesting=allow`, `restricted.containers.interception=allow` and `restricted.containers.privilege=isolated`.
- `restricted.containers.lowlevel=allow`, because `security.idmap.size` is a low-level key. That also allows `raw.*` keys, so the start refuses a copy that has one.
- `restricted.snapshots=allow` and `limits.virtual-machines=0`.

Host-path disks and privileged containers stay forbidden. The phase sets every missing restriction in one call: Incus checks each profile against all of them at each change.

The phase then proves that a copy is a clone:

1. It deletes a leftover `orbit-clone-probe` and its copy from a failed run.
2. It creates `orbit-clone-probe` from the stock image with an isolated ID map, without starting it.
3. It copies the container, and starts and stops the copy. The start is where Incus would shift the files.
4. `zfs get written` on the copy's dataset must stay under 8 MiB.
5. It deletes both containers.

Without idmapped mounts, Incus would shift the owner of every file of a copy at its first start. Each task start would then rewrite the whole root disk. Incus 7 reports empty `kernel_features`, so `incus info` cannot answer this.

Every refusal fails the phase with `task_vm.setup_failed`. Ansible runs its modules through a pipe that it closes, so `incus` never reads a stray standard input as YAML.

### Prepare an Instance container host

An `app-dev` Node on the [build list](/reference/development-containers#the-build-list) that is not a task VM gets a container branch, a task VM host too in its role converge: `tasks/instance-host.yml`, from `tasks/node-role-runtime.yml`. The Gateway's `InstanceHostIntent` sends the branch's intent as the role runtime's `instances` key, only for such a Node; every other Node gets the same intent as before. The admission of a role add or converge allocates the Node's Instance bridge before it captures the intent; the capture only reads it. [Instance containers](/reference/development-containers#prepare-a-node) describes the result.

The branch shares the two task files of a [container host](#prepare-a-container-host). `container_host` names what differs:

| | Task container host | Instance container host |
| --- | --- | --- |
| Pool | The VM side's ZFS pool | The Node's pool `orbit-instances`: ZFS, or a directory pool, as on [`nested-app-dev`](/reference/development-containers#instance-host-in-the-fleet) |
| Project, bridge | `containers.project`, `containers.network` | `orbit-instances`, `orbitinst0` at the first address of the Node's bridge |
| Kernel modules, sysctl files | `orbit-task-containers` | None, or `wireguard` and `overlay` in `orbit-instances` with the [nested-fleet switch](/reference/node-settings#nested-fleet-instance-hosting); `60-orbit-instances.conf` |
| Subordinate IDs | 101,000,000 per place and the base | 65,536 for each of 201 containers, plus the task container need on a host that has both |
| ACL egress | The VM bridge's rules | Bridge and NIC reject by default; the bridge admits the Node’s endpoint union and proxies, each NIC its own targets and proxies |
| ACL ingress | The VM bridge's rules | TCP ports 1024 to 65535 from the bridge address, and T3's port 3773 from any source |
| Bridge DNS | dnsmasq answers on the bridge address | None: `raw.dnsmasq` is `port=0`, so dnsmasq serves DHCP only |
| Profile | Root disk and `eth0` | Root disk, `eth0` with the common ACL and reject defaults, read-only idmapped shared folders (unshifted on an [Instance fleet Node](/reference/development-containers#instance-host-in-the-fleet)), and proxy settings as `environment.*` keys |
| Restrictions | Nesting, interception, low-level keys | Isolated ID maps, host disks with no path list (Incus refuses `shift` under one), proxy devices |
| Input rules | `orbit:task-containers-*`: DHCP and DNS | `orbit:instances-*`: DHCP and the egress proxy, no DNS |
| Clone probe | Always | On a ZFS pool only; a directory pool makes full copies |
| Error code | `task_vm.setup_failed` | `instance.host_unavailable` |

With the [nested-fleet switch](/reference/node-settings#nested-fleet-instance-hosting) on a ZFS pool, the branch also sets `volmode=dev` on the pool's `custom` dataset, once. A copy's [inner volume](/reference/development-containers#the-inner-volume) then gets its device in dev mode.

An Instance container host also gets the [fast wake](/reference/development-containers#the-node-resumes-a-frozen-container): the [power helper](/reference/prepared-restore#the-power-helper) `/usr/local/libexec/orbit-instance-power` with its capability manifest, the enabled path unit `orbit-instance-wake.path`, its dispatcher and the worker template `orbit-instance-wake@.service`. Writing them changes no running unit.

The role repeats the [read-only NIC audit](/reference/development-containers#prepare-a-node) at its end. An unsafe NIC fails convergence; bridge egress stays reject. No NIC may reference the bridge union ACL.

The bridge ACL admits the Node's preview and development connections and T3's clients:

| Ingress rule | Who needs it |
| --- | --- |
| TCP 1024 to 65535 from the bridge address | The Node's Caddy, which connects from that address: to FrankenPHP at 8080 for the preview, and to an agent's development ports |
| TCP 3773 from any source | T3's clients, through a NAT proxy that preserves the WireGuard peer's address |

The clone's Node route rules admit WireGuard peers but no task VM. The NIC combines the common and Instance ACLs as a union, so its own ACL does not narrow these ingress rules. The live check found that the Node also reaches a default's 3773 and 5173; cross-container connections to 3773 and 8080 failed with port isolation enabled. [F10](/reference/development-containers#network) records this limit and the required follow-up.

The Gateway renders the rules as the intent's `ingress`. The Node needs no rule for port 8080 alone: it lies in the range of the development ports.

Incus admits DNS to the bridge address ahead of the ACL, and the bridge's dnsmasq would answer every name the Node resolves, fleet names too. The Node's input rules admit no DNS from the bridge, but another rule of the Node may. `port=0` turns dnsmasq's DNS off, so the answer does not depend on the Node's rules. DHCP still works.

The branch then:

1. refuses a bridge range that another interface of the Node holds;
1. requires the pool, and on a ZFS pool applies the ARC cap that the system part sized, in `/etc/modprobe.d/orbit-instances-zfs.conf`;
1. installs each shared folder (`tasks/instance-folder.yml`);
1. prepares the bridge, project and profile, and unsets a `restricted.devices.disk.paths` that a host still has;
1. reconciles the bridge endpoint union with egress default reject;
1. audits every effective bridge NIC of running and stopped containers;
1. runs the [egress proxy](/reference/development-containers#egress-and-dns) and its nftables table (`tasks/instance-egress.yml`);
1. builds the image (`tasks/instance-image.yml`).

A task VM host keeps the ARC cap of its [task side](#prepare-an-incus-host), or none. The intent's `arc` is false there, so the branch writes no cap.

A folder version is built under a staging name and renamed last, so a version that exists is whole. The agent folder refuses a Node whose own group holds gid 2750.

Each artifact is fetched by its digest into `/var/cache/orbit/instance-folders`. A tarball or zip is unpacked into its part's folder; any other artifact is the part's one file. Each program in `bin` links to its path, and every link must reach an executable file.

The runtime folder is readable by everyone. The agent folder's `agent/` is `root:2750` and closed to others; its notes stay public. Then `current` flips to the version. When it flips from an earlier version, a mark `.restart` stays until each running container restarts `t3.service` for the agent folder, or every `orbit-*.service` for the runtime folder, one after another, so a failed run restarts them again next time. A version that no process on the Node maps goes.

The image's alias is a digest of the definition that the Gateway sends. A Node without that alias builds it in the container `orbit-instance-build`, from the stock alias, at the bridge's last address. It has the bridge's ACL and reaches the internet through the egress proxy, which the profile names for its `incus exec`.

The build waits for cloud-init's first boot to finish. The build container sets an isolated ID map, as the project requires. The build installs the packages, runs the setup script, writes the files with their owners and modes, enables the units and cleans the package lists.

The first boot leaves two things that the build removes. Cloud-init makes the group `lxd` for its default user, and that group takes gid 1000, which `orbit` needs: the setup script deletes the group after the user. The boot also writes a machine-id, which every container of the image would share: the build empties `/etc/machine-id` last, so each container writes its own at its first start. The file stays, because systemd treats a missing file as a first boot and resets the units to their presets. A clone still copies the machine-id of its default Instance.

The build refuses an image with a sudo path: an `ubuntu` user, a file in `sudoers.d` but its README, or a member of `sudo` or `admin`. Then it publishes the container. The build container goes in every case. The stable alias `orbit-instance` then moves to the new image, and older images whose aliases are only digest aliases go.

### The base VM and its snapshot

Each task VM host keeps one stopped base VM, `<host>-base`, with its seed volume `<host>-base-seed`. The VM never gets an identity. A ZFS snapshot of the VM and of its seed is the base of every task start: a start copies it ([provision chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/start.mmd)). There is no Incus image and no publish.

Only the base snapshot refresh made a base, and it is removed ([Refresh the base snapshot](#refresh-the-base-snapshot)); this section says how the recorded bases were built. The recipe image build is gone, with the kind `task_vm.image_build`, `task-vms:build-image`, `task-vms:build-recipe`, the per-minute recipe reconcile and the image reaper ([old paths](/reference/old-paths)). The Gateway records a host's base as the snapshot that its latest refresh proved. A promoted build that holds an image, not a snapshot, is never a base, and it never replaces the snapshot. A start on a host without a recorded snapshot is refused with `task_vm.image_unavailable`.

A new host gets its base VM in the first phase of its first refresh, `base-start`, once. The phase creates an empty seed volume `<host>-base-seed`, runs `incus init` from the stock Ubuntu VM image that host preparation copied, and boots the VM. The first boot makes the `orbit` account with the key of the Swamp control Node and installs the SSH server.

Until a snapshot is proved and recorded, each refresh discards any unpromoted base VM and seed before building from stock. Cloud-init can mark its package module as run even when the first boot fails to install the SSH server; restarting that VM does not run the module again. A host with a recorded snapshot keeps its existing base unless `--from-stock` is requested.

The converge that follows builds everything else, because no digest is recorded yet. The seed holds no nested fleet, so the fleet phase [builds the fleet](#build-the-nested-fleet-in-the-converge). The refresh then stops the VM, snapshots it and proves the snapshot like any other.

The machine recipe still describes the software of the base VM. `RecipeImageBuilds::currentRecipe()` reads the `recipe.json` and the Vite+ lock of the release the Gateway serves, and the converge intents of a refresh and of a task start carry them. The recipe identity does not name the Orbit agent, and the base holds the pinned observer build that the converge installs.

The base was identified by the recipe identity that its refresh converged to, recorded on its build row, which went with the table `task_vm_image_builds` ([old paths](/reference/old-paths#the-task-vm-tables)). Image freshness counts a path when it belongs to a carried folder or matches any carried lock-file pattern.

The converge of a refresh installs the recipe packages through the playbook. It writes the same snapshot configuration to `/var/tmp/orbit-image-apt` and installs with the `apt` module with `APT_CONFIG` pointing at it, and `allow_downgrade` because the pins are exact. The index read goes over plain http through the `apt-cacher-ng` of the VM (`localhost:3142`, no request pipelining, signatures still checked), which the first software task installs; the packages stay a direct download over https. The cache stays in the base VM, and the nested Nodes of a task VM read the snapshot indexes from it. The `check` phase asserts every package at its pin.

#### The topology seed

The seed volume `<host>-base-seed` of the base VM holds the pool `topology-seed` with the nested fleet of the running release. The tools phase of the converge makes the pool once, on the blank seed volume. The Incus database of the base VM keeps the pool, and a task VM copies that database with the VM. The phase refuses a seed disk with a file system that the Incus of the VM does not know, because Orbit imports no seed from another VM.

The snapshot of the seed is taken with the snapshot of the VM, and a task start copies both. A medium or large VM mounts its copy as the disk `topology-seed`, so a topology task has its Nodes at once and does not build Orbit again. A task VM that already runs keeps its own clone of the older seed snapshot.

#### Log task VM egress

The host part that logged task VM egress is removed with the base snapshot refresh, its only caller. [Task VM egress log](/reference/task-vm-egress-log#running-the-host-part) lists the files that a host which ran it keeps.

#### Refresh the base snapshot

No refresh of a base snapshot exists any more. The kind `task_vm.image_refresh`, its intent and projection, and the artisan command `task-vms:image-builds` are removed; its triggers went before them. It is replaced by the [refresh after a release](/reference/development-containers#refresh-after-a-release), which updates the default Instance of each nested-fleet Project. No task start copies a VM base snapshot. Replaying proof on the merge base remains separate and is not ported by this removal.

The workflows `operation-task-vm-image-refresh` and `operation-task-vm-hub-converge` and their children `task-vm-image-ansible`, `task-vm-host-phase` and `task-vm-candidate-proof` are removed from Swamp, one release after the Gateway stopped starting them. So are the host parts `egress-log` and `host-clean` of `swamp/ansible/task-vm-host.yml`, and later its task start and release parts `start` and `delete`. The remaining `host-prepare` and `base-*` parts and the uncalled role graph are removed too; shared container tests call their live helpers directly. Old Operations of the kind stay as history: `operation:list` and `operation:show` show them, and an open one ends at its budget or on a cancel request ([kinds that left the registry](/reference/operations#kinds-that-left-the-registry)).

##### Build the nested fleet in the converge

The uncalled VM fleet builder, `task-vm-fleet.yml` and `task-vm-fleet-build.yml`, is removed with the base-build graph. A task copy gets its nested fleet from the Project's default Instance; [the fleet step](/reference/development-containers#the-fleet-step) owns its build and refresh. The separate restore and apply children below stay for proof replay.

##### Apply and check the nested fleet

The child `task-nested-apply` applies the checkout to the nested fleet, and the restore of the `base` snapshot reuses it. The containers start with the checkout already attached. At boot, the bind mount units of the containers put `.env`, `storage` and `bootstrap/cache` from each guest's own disk over the checkout.

The child checks each of the three units on the Gateway and on app-dev and starts none. In a container no udev confirms the device behind the checkout's mount, so a start job would wait 90 s for it and fail.

The child places the agent delivery and migrates as the app user: as root through `runuser -u orbit --`, with `incus exec --cwd` where a command needs a folder. `runuser` gives `orbit` its groups, among them `work`, which opens the checkout (`agent:work`, mode 2770). `incus exec --user 1000` gives only the user ID, with group 0, and fails with EACCES. The read of the nested checkout in `proof-fleet-verify` runs Git the same way.

The child then renders and reloads the checkout’s scoped Swamp grants after optional token renewal, with the renewed or still-valid serve-driver credential, before migrations. Reconciliation requires successful agent delivery and successful or skipped renewal; either prerequisite failing stops apply. It uses the candidate manifests, not the grants saved in the bootstrap control plan.

Before the single authenticated reload, it waits up to 60 seconds for the local Swamp listener. The wait and the reload’s 90-second bound fit inside the 180-second reconciliation limit with 30 seconds for setup. The proof runner also allows 180 seconds for its Gateway preparation command and 190 seconds for the calling process. Its overall fleet preparation limit remains 330 seconds. A listener timeout or failed reload stops apply and prevents snapshots.

It restarts the queue worker, the agent-view subscriber and the Swamp worker, and checks, one unit at a time, that they and the nested Swamp serve are active.

The behavior name of `Check the services` carries the index of the unit, not its name. Swamp opens an SSH control socket under `/tmp/swamp-ssh/<behavior name>`, and a path over 108 bytes cannot be a Unix socket.

The child `task-nested-ready` checks readiness from the VM, over the operator's WireGuard address and the API of the nested Gateway. It first waits up to 120 seconds, with one Ansible task, until Doctor of the nested Gateway is healthy, because the agents read online only after their next heartbeats. All three Node agents must be online, the Gateway must know the operator with its key, and Doctor must be healthy. The first fleet build migrates in `gateway-bootstrap` before it checks Doctor.

The child `proof-fleet-restore` puts the fleet back to `base` for the proof. It restores the three nested containers with a limit of 45 seconds and no retry, which is a reboot, starts them, fails unless all three are `RUNNING`, and waits for cloud-init. Since the fleet build disables cloud-init, it also runs `Wait for systemd boot` inside all three containers: up to 120 seconds for systemd to reach running or degraded, then a check of each required state mount on Gateway and app-dev. The whole exec has a 135-second limit. A timeout, another systemd state or an inactive mount stops the restore before apply and names the failed check.

It then calls `task-nested-apply` and, only after that succeeds, `task-nested-ready`. The checkout is already mounted, so the candidate can require columns that the restored database lacks. Migrations must finish before any nested Gateway API read, including the Doctor wait. Apply needs the boot and state mounts, not API readiness.

The batch workflow calls it before every batch but the first. The case workflow calls it after a case that did not pass and is not the last of its batch, so the next case starts from a clean fleet. A restore that fails stops the rest of the batch.

After the apply step, the VM gets a CLI profile. The steps `Make the CLI home`, `Pin the nested CA` and `Add the CLI profile` read the root CA of the nested Gateway with `incus exec … cat` into `~/.orbit/nested-ca.pem` and run `gateway:add https://10.45.0.1 --name=nested --use --ca=…` with the candidate CLI of the checkout. The proof runner runs that same CLI (`php apps/cli/orbit`) and finds the nested Gateway through the profile. The apply child checks the Swamp services only when the VM has the proof Club key (input `club`), because the nested Swamp serve cannot start without it.

The VM then joins as operator. It makes its own WireGuard key and writes the public key into the nested Gateway's `operator` record. It puts the key on the hub and brings up `wg-nested` as `10.45.0.10`. For a task that needs the proof Swamp Club key, the VM makes the folders `/home/orbit/.config/swamp` in the nested Gateway, owned by `orbit` with mode 0700, pushes the key into them and restarts its Swamp.

### Claims copy the base snapshot

A new Incus start copies the host's recorded base snapshot and its seed snapshot, which are ZFS clones, starts the copy and writes the identity of the claim. The VM records the `recipe_identity` of the snapshot at allocation. A later refresh cannot change the snapshot a VM was copied from. A host without a base snapshot cannot serve a new claim.

An active work turn keeps its VM across recipe changes. Publishing the PR deletes the VM; later work starts from the base and the task branch.

#### Image fallback

There is no fallback image. A claim copies the recorded base snapshot of its host, or waits. Another host with a base snapshot can serve it.

### Limit fleet traffic on the hub

Nothing converges the hub filter any more, and nothing configures a task VM host. The artisan command `task-vms:prepare-hub`, the kind `task_vm.hub_converge`, the Node setting `task_vms` and the task VM settings of the Gateway are removed ([old paths](/reference/old-paths#task-vm-settings)). The firewall catalog names neither the VPN Node's UFW rules for the reserved range nor a host's task bridge input rules (`orbit:task-vms-*`, `orbit:task-containers-*`). The next converge of the VPN Node deletes the route rules whose comment starts with `orbit:task-vm-`.

A filter that an earlier run loaded (`inet orbit_task_vms` and its systemd unit on the VPN Node) goes with the next converge of the VPN Node ([Converge the hub](/reference/wireguard-endpoints#converge-the-hub)). It filtered only the reserved range, so traffic between other Nodes does not change.

The WireGuard address allocator still skips the reserved range `10.44.0.128/25`, the upper half of the default VPN subnet `10.44.0.0/24`: it never hands an address of it to a Node. A task VM Node has no access grants to other Nodes. No Caddy site refuses the range any more: the `@orbit_task_vms` matcher and its `abort` are gone from every route block ([Caddy configuration](/reference/caddy-configuration#listener-addresses)).

### Placement invariant

No task VM exists, so no Node is a task VM Node. An Instance save, an access grant, the fleet rollout, a metrics exporter and a Node removal do not check for one. The refusals `task_vm.foreign_instance`, `task_vm.access_refused` and `instance.task_vm_node` and the rollout reason `sandbox` are removed ([old paths](/reference/old-paths#the-task-vm-readers)).

No task workspace command runs over SSH. The SSH task workspace path and its refusal `task_vm.workspace_mismatch` are removed ([old paths](/reference/old-paths#the-task-vm-readers)).

### Configure task VMs

There is nothing to configure. `config/task_vms.php`, `TaskVmSettings` and every `ORBIT_TASK_VMS_*` variable are removed, with `ORBIT_PI_PORT` and `ORBIT_SWAMP_PROOF_CLUB_API_KEY`. The `gateway` role's convergence removes them from the Gateway's environment file ([Gateway environment](/reference/gateway-environment)).

Task VM code reaches the model router only through the core interface `App\Domain\TaskVms\TaskVmModelRoute`: `assertReady()`, `origin()`, and `key()`. The [ai-router extension](/reference/ai-router) binds it. `origin()` is the [task VM guard](/reference/ai-router#task-vm-guard) on the router's Node, such as `http://10.44.0.3:8318`, and `key()` is the one fixed placeholder key that every task VM holds. The Gateway keeps the key as a secret setting and changes it only on an incident, with no scheduled refresh. A task start writes the origin and the key into the Pi models file of the VM, inside the claim. No start and no release calls the model router, and no per-VM key exists.

The `tasks` extension depends on `ai-router`, and enabling `tasks` calls `assertReady()`, which refuses with `ai_router.disabled` (409) until the ai-router is set up and has its Node. `ai-router:destroy` refuses only while `tasks` is enabled. See [Teardown](/reference/ai-router#teardown).

### Errors

Every task VM error code starts with `task_vm.`. The setup commands print the code and message, and for `task_vm.setup_failed` the last lines of the script's error output.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.setup_failed` | 409 | The Ansible host preparation refused the host |
| `task_vm.image_refresh_unavailable` | 409 | A refresh cannot start: the Gateway release has no commit |
| `task_vm.image_refresh_failed` | 502 | A refresh run failed. The Operation names the failed step |
| `task_vm.vm_missing` | 502 | A frozen `incus` agent command found the task VM gone from its host |

### Limits

Incus accepts DNS before the ACL, so a task VM can query port 53 on any host address and read instance names from other bridges. This risk is accepted. Transfers over WireGuard may pass through the VPN hub.

Publication deletes the VM and its workspace. No VM stays allocated for PR review, including a group with preview intent.

The [Git mirror](#the-mirror) needs repository-sized Gateway storage. A fresh workspace receives the full history of its selected branches, without tags. Fetch, pack, and push operations serialize per Project, with a 300-second lock wait. Operations that would send a credential to a VM require its group to end first.

The hub filter failed open to keep the fleet VPN up. Nothing loads it again: if an `nftables.service` restart removes the table, it stays absent, while its unit can still show active.

### Why task VMs work this way

The VM edge is the security boundary. Agents have root inside it, so an in-VM check is fast feedback, not proof against tampering. [CI on the pull request](/reference/tasks#review-and-merge) is the merge gate. Public egress is open on every port; the bridge ACL and hub filter limit private traffic.

Task VMs are untrusted WireGuard peers. On the shared HTTPS listeners, Caddy admits only the Gateway API, Reverb, and the single configured Swamp site. Admitting Swamp grants network reachability to that whole site; Swamp still authenticates each worker with its short-lived per-group credentials. The exception grants no access to the other sites on Services.

Limiting egress to HTTP(S) and DNS breaks clock sync, package sources, and key installs. It adds no protection beyond the VM edge.

Host output is validated once, and Gateway records are trusted after that, so six states and no marker timestamps describe the whole lifecycle. `claimed_at` times the claim. Parking reads the settle times the group already records.

The agent in a task VM has root through sudo, so a token anywhere in the VM, even in another user's process, is the agent's token. The Gateway already holds the tokens and already reaches every task VM over SSH. So Git that talks to GitHub runs on the Gateway, and only objects cross the VM edge.

Packs on the standard input and output of the existing SSH commands need no new transport, keep the check that a command reaches only its group's workspace. The Gateway checks what comes back the way a fetch checks a remote: only the approved commit ID names what it pushes, and `index-pack --strict` checks every object.

Most groups wait for review far longer than they work. Deleting the VM at publication frees its whole place and removes the failed park path. Re-entry reconstructs the workspace from the base and the published task branch. Separate pools stop task work from consuming CI capacity. Capacity is freed only after confirmed removal.

A topology declaration before the claim gives placement a known workload and the reviewer a declared set of Nodes to check. An agent's request during a turn would supply neither in advance. Resizing or moving a running group would change its environment during work. A fresh group makes that choice explicit. One size for every Orbit VM would waste capacity: most groups need no topology, but a large VM takes three places. Measurements on beast put the test Gateway, an `app-dev` Node, and the operator at about 2.7 GiB together. Four test machines filled a large VM's 12 GiB under IO.

These alternatives were also rejected:

| Alternative | Why not |
| --- | --- |
| A fallback to the stock image | A VM from it cannot enroll, so the failure would come later and less clearly |
| Placement in host order only | The first host fills before the second takes any VM |
| A token in the VM for each fetch and push | The agent can read any process in the VM. A read token exposes every private branch, and the write token lets the agent push any commit |
| WireGuard in cloud-init | It bypasses normal enrollment. The Node record, peer, and roles would come from separate paths |
| A hub table for each VM | One static filter on the reserved range enforces the same limits without per-VM rules on the live hub |
| The Incus REST API from the Gateway | It needs certificate and host setup. The Gateway already has root SSH, and host-local builds need no image transfer between hosts |
| Subscription sign-ins copied into VMs | They expose the subscriptions. Rotating OAuth refresh tokens fail when several copies refresh |
| A task VM outside the fleet for every Project | Project work needs real Routes, deployment, and previews. Enrollment with no access grants and the hub filter support those features within the VM boundary |
| A finished task VM as the next image | Agents and Project install scripts can change any file. Cleanup cannot prove that disk clean |

## Orbit Project

A group of the `orbit` Project runs on a task VM. It is the proof Project, and the only Project a task VM serves: the base carries only its checkout. Its workspace is the Instance `task-<group id>` without a Route, and every claim clones from the Gateway mirror and installs the dependencies of all five Composer projects and the web app. Its task check is root `composer check`, which runs `bin/review-check` on the VM as `orbit`.

Configure the Project like this:

- Add the setup step [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) after the install steps.

Without the `@orbit/ci-caches` step, Pint and PHPStan start cold in every task VM workspace. With it, a fresh workspace gets the Pint and PHPStan caches that main CI published for its baseline.

The setup and teardown steps run on the VM as `orbit`. The `seed-dependencies` step does nothing there, because a task VM workspace has no `ORBIT_SEED_PATH`: the install steps build fresh dependency trees using private caches.

### Declare a topology

A subtask declares no topology. The API ignores an empty `topology` list and refuses any other list with `422 tasks.orchestration_unsupported`. A value that is not a list, such as a string or `null`, fails validation with `422 validation.failed`. This holds when a request creates a group, creates a subtask, or updates a subtask. Task responses have no `topology` field, and the CLI has no `--topology` option.

Nothing reads a declaration that a group saved before this rule. The column `tasks.topology` is dropped ([old paths](/reference/old-paths#task-vm-settings)). The columns `task_vms.topology`, `topology_state`, `topology_run`, `topology_started_at`, and `topology_error` went with the table `task_vms` ([old paths](/reference/old-paths#the-task-vm-tables)). A contributor can still acquire a topology by hand with `bin/e2e-topology`, as [Incus topologies](/reference/incus-topologies) describes.

## VM power

Task group responses keep the field `vm_power`, and it is always `null`. No task VM exists, and the Gateway reads no task VM row for it ([old paths](/reference/old-paths#the-task-vm-readers)). The field stays so that older clients keep their shape. The CLI shows **VM power** only when the value is not `null`, so it shows none.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. A task VM with `preview` is never [parked](#park-and-resume). Setting `preview` does not start a VM that is already parked. The next work for the group starts it.

Use `orbit task:create --preview` or `orbit task:update <group> --preview`. Use `--no-preview` on update to release the preview. The two update flags are mutually exclusive. The API accepts JSON booleans; omitted updates preserve the current intent.

The former lifecycle and standalone Incus admissions for Task VMs return `operation.kind_unavailable` (409) before reading a host or recording an Operation. Standalone enrollment uses `StartTaskVmEnrollmentOperationAction`; provision parents compose the same native lower steps directly. The old enrollment job and runtime transport/contract registrations are removed. Private material for the model router requires the admitted top-level router Node to equal the private binding owner and the configured issuer owner.
