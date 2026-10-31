---
title: "Compute drivers"
description: "Task VMs for every Project and their UpCloud overflow."
covers:
  - apps/agent/src/commands/{image_recipe.rs,image_recipe/**,pi_artifact.rs,pi_artifact/**}
  - apps/gateway/app/{Domain/TaskVms,Infrastructure/TaskVms,Jobs/TaskVms,Actions/TaskVms,Domain/Compute}/**
  - apps/gateway/app/{Models/TaskVm.php,Models/TaskVmImageBuild.php,Console/Commands/*ImageBuildsCommand.php,Console/Commands/PrepareTaskVmHostCommand.php,Console/Commands/PrepareTaskVmHubCommand.php,Console/Commands/BuildTaskVmImageCommand.php,Console/Commands/BuildTaskVmRecipeCommand.php,Console/Commands/BuildUpCloudTaskVmImageCommand.php,Console/Commands/ResolvesTaskVmHostNode.php}
  - apps/gateway/{app/Infrastructure/MachineSoftware/**,app/Console/Commands/MachineRecipeCommand.php,resources/machine-software/**,resources/remote/task-vms/**}
  - .github/workflows/{php-build-mirror,git-build-watch}.yml
  - bin/{php-build,git-build-watch}
  - apps/gateway/{app/Models/PackageCache*.php,app/Console/Commands/PackageCacheUsageCommand.php,config/package_cache.php,config/task_vms.php}
  - apps/gateway/{resources/task-vms/**,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
---

# Compute drivers

Every task group runs in its own [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster. This holds for every Project, `orbit` included. See [Orbit Project](#orbit-project).

Task VMs are off until you [configure them](#configure-task-vms). Until then no task group can start.

## Machine software recipe

The Node.js artifact pin is `24.22.1`, including the [October 21, 2026 security fixes](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases). Nodes and task images install it through the shared recipe installer. Vite+ project pins and retained releases select their own runtimes and must be updated separately.

The repository's machine software recipe is [`apps/gateway/resources/machine-software/recipe.json`](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/resources/machine-software/recipe.json). It declares one Ubuntu snapshot, tool version pins, the [PHP build](#php-build), and the tool sets for `gateway`, `app-dev`, `app-prod`, `operator`, and the task VM base image. Project dependencies, workspace setup, credentials, Node identity, and service configuration are not recipe inputs.

The recipe pins both the Vite+ runtime (`vp`) and CLI (`vp-cli`) to `1.4.0`. Project lockfiles stay independent: [deployment admission](/reference/tools#project-environments-in-the-vite-store) accepts a manager at least as new as the Project's locked Vite+ release, including Projects on `1.2.x`. A recipe update does not update those lockfiles.

The loader and validator define the recipe contract. [Recipe image builds](#build-the-base-image) use that document for a host-local base image. [Node convergence](/reference/node-provisioning#converge-a-node-to-a-recipe) applies its role tools to production Nodes. Claims require the [current recipe](#claims-require-the-current-recipe), and topologies [copy their Nodes from its topology seed](#prepare-the-topology).

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

Ubuntu pins are version floors on installed machines. The same Debian package on the native architecture (or `all`) satisfies the recipe at the pinned version. A newer version also satisfies it when `dpkg --compare-versions` orders it above the pin and local APT policy confirms Ubuntu origin in the same release, updates, or security pocket. Convergence and base and role image verification use this rule. Plans and results label accepted newer packages `newer_than_recipe` and include both versions. Older or missing packages install the snapshot pin.

A newer package from another release, a third-party source, or an unproved origin is refused before package changes; it is never downgraded. An installed version listed in any untrusted index is refused even if a trusted index also lists it or offers a newer candidate. Plans label it `unsupported_newer` with a `refusal` field naming `origin:<tool>`.

APT policy must associate the installed version with a trusted Ubuntu index. Evidence can come from the normal sources, the refreshed generic Ubuntu pockets during application, or the cached recipe snapshot. The index's release metadata must name `o=Ubuntu`, `n=resolute`, and `a=resolute`, `resolute-updates`, or `resolute-security`. The index must also come from an Ubuntu archive, security, or snapshot host, or from a matching source scoped to `/usr/share/keyrings/ubuntu-archive-keyring.gpg`. Release metadata alone cannot establish trust. The dpkg status records package identity, architecture, and version, but not its repository or release. A version suffix alone cannot prove origin.

Rolling pocket indexes drop superseded builds. When the installed version appears only in dpkg status, convergence dates it by the earliest matching `install` or `upgrade` action in `/var/log/dpkg.log*`, including compressed rotations. Without an action, it uses the earliest `status installed` record for that version. Later trigger runs do not change the date. It must find that exact package version in a trusted index at `snapshot.ubuntu.com` at that time. A newer Ubuntu candidate alone is not proof.

Snapshot checks use temporary sources and indexes without changing the system APT configuration or indexes. Each run caches indexes and failures by timestamp, including for the post-install assessment. A run permits at most three lookups by install timestamp, each with a 60-second update timeout. Refusals name the cause after `origin:<tool>`: `dpkg-log-missing`, `snapshot-unavailable`, `snapshot-unproved`, or `snapshot-lookup-limit`. Restore retained install logs for missing history. For other refusals, inspect the package's provenance and snapshot availability before retrying; the recipe does not downgrade an unproved build.

There is a known fail-closed window between `apt update` and installation. If Ubuntu supersedes a build during that window, the snapshot at its install time can omit it. Convergence then refuses with `snapshot-unproved`, even for a real Ubuntu build. It does not infer provenance from a newer candidate or guess an earlier update time.

Recipe installs use a temporary snapshot source and leave the normal Ubuntu sources in place, so unattended-upgrades can continue installing security updates. Application repairs the exact snapshot source file written by earlier Orbit convergence. It first saves that file beside the APT configuration as `ubuntu.sources.orbit-snapshot-backup`, then restores archive, updates, backports, and security sources with the Ubuntu archive keyring, and logs the repair through `logger` with the `orbit-recipe` tag. Other source content is preserved.

The restored sources use the generic Ubuntu hosts. A regional mirror cannot be recovered because main overwrote the original file without a backup. See [Node recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe) for APT index refresh during application. Dry runs show `repair_ubuntu_sources` in the plan's `actions` and assess the restored sources with temporary indexes; they leave the system sources and indexes unchanged. Without a repair, dry runs use existing indexes.

Node convergence and image installs refuse Ubuntu downgrades. Exact Debian artifacts permit downgrades only after an APT simulation rejects every non-artifact downgrade; installed Ubuntu recipe versions remain explicit install targets. Installation ends with `apt-get check` and `dpkg --audit`; audit output also causes failure. Sury PHP artifacts remain exact: the PHP build installer owns their installation and verification, and the Ubuntu floor rule never applies to them.

The recipe pins both Ubuntu `docker.io` and `containerd` for Docker roles and the base image. Migration reads those pins rather than choosing a separate containerd version.

Docker CE is not the recipe's Ubuntu `docker.io` provider. Convergence refuses it with `docker-provider:docker-ce` before planning or installing packages. It never stops containers to change providers. Follow the explicit [Docker provider migration](/reference/docker-provider-migration) during a planned window. That command may downgrade Docker and containerd after inventory and compatibility checks; normal recipe convergence still refuses downgrades.

An `artifact` tool has an `artifacts` object with exactly one key, `amd64`. Each entry contains an HTTPS `url` and exactly one digest field, `sha256` or `sha512`, with a full lowercase hexadecimal digest. A URL must identify the pinned release, not a moving installer or a `latest` alias. Artifact contents, not the spelling of the URL or a successful HTTP response, are the integrity check. The validator downloads and hashes the bytes without executing or installing them.

Debian artifact filenames omit a version epoch; the pin and package metadata retain it. Architecture-independent `.deb` packages declare `all` in their metadata.

Git and `git-man` are exact Debian artifacts from Launchpad's `ppa:git-core/ppa`, built for the recipe's Ubuntu release. The `git.packages` list names the owned packages. Both belong to `common`, so every Node profile and task VM image gets the same build. The loader requires matching versions and SHA-256 pins for both packages. Convergence accepts the pinned build already installed, replaces an Ubuntu build after proving its origin, and refuses a different third-party build with `origin:git` or `origin:git-man`. The Ubuntu provenance rules for other tools stay unchanged.

After verifying both installed package versions, convergence and image builds save their versions in root-owned `/etc/apt/orbit-git.json`. A later recipe pin may replace that recorded build. A missing, malformed, writable, or unowned receipt proves no ownership; an installed version that differs from its receipt still needs Ubuntu provenance or the current exact pin.

Convergence backs up and removes git-core PPA entries for `resolute main`, including deb822 sources with inline signing keys. It preserves unrelated source entries and refuses mixed or unexpected git-core entries. Dry runs report `retire_git_source` and `pin_git` without writing. An Orbit APT preference permits only the pinned Git version, so unattended upgrades cannot introduce a second owner. The APT Tool Manager protects both packages from adoption and removal.

Image builds refresh the snapshot indexes before assessing Git with the Ubuntu tools. Both installers retire the PPA before installation and publish the preference only after the install succeeds. Backups live in `/etc/apt/`, outside APT source directories. A source file with no remaining entries is removed.

Installed binaries built from source package `git` but absent from `git.packages` are refused as `git-dependency:<package>` before Git source or package changes. This includes siblings such as `gitk` and `git-gui`, even at the pinned version. An operator must remove these unmanaged siblings before convergence; the recipe owns only its declared package set.

PPAs delete superseded builds. The recipe-level `artifact_mirror` serves every external Debian artifact as `<package>_<digest>.deb`. The `php-build-mirror` release holds all these artifacts. `bin/php-build mirror` and its workflow skip assets already published. Each installer and the validator tries the mirror before the original URL. A transport failure permits fallback; an integrity failure stops the operation.

Exact Git pins block automatic Ubuntu and PPA security updates. Orbit owns the delay between an upstream fix and a reviewed recipe bump. The daily `Git build watch` workflow queries Launchpad for published Git builds for the recipe release and Ubuntu’s security notice API. It opens or updates one GitHub issue for a newer build or a notice published on or after `git.usn_since`. The check job uploads its report with a read-only token. Only a separate publishing job on main's scheduled or manual runs gets `issues: write`; pull-request runs never publish issues. Network and parsing failures fail the workflow visibly.

Review each notice even when its Ubuntu version differs from the PPA build; the check does not claim that Ubuntu version ordering proves a PPA fix. Close the issue only after reviewing the fixes and rolling out the pin. Advance `git.usn_since` explicitly after reviewing the covered notices.

Git bumps stay separate from the nightly snapshot and artifact pull requests. The snapshot flow changes its date and Ubuntu pins together; the artifact bump authenticates Sury and Zabbly indexes and updates their package sets. No automated flow verifies Launchpad's signed indexes, so a maintainer bumps Git by hand. To bump Git, verify the PPA's signed `resolute` indexes, change both package versions, URLs, and SHA-256 digests together for amd64, download and hash both files, and validate the recipe. After merge, the mirror workflow retains the files and normal recipe convergence rolls out the build.

The loader has a closed catalog of supported tool identifiers and their source kinds. Each `php<version>-<name>` pin of a declared PHP version is an `artifact` tool; the [PHP build](#php-build) decides which of them exist. A new tool needs a catalog change and validation tests as well as a recipe entry. Every profile reference must resolve to a catalogued entry in `tools`; an unused entry, a missing tool, or an incompatible source is an error. The recipe must record a concrete version, including for an Ubuntu floor: `latest`, `stable`, `lts`, version ranges, and unversioned installer URLs are not pins.

An `internal` tool is an Orbit-built binary pinned by `source_commit` (12 to 40 lowercase Git hexadecimal characters), an exact `version`, and architecture-specific entries in `artifacts`. Each entry has a simple `filename` and a full lowercase `sha256`; filenames resolve under the Gateway's sandbox-artifacts directory (or the command's `--artifact-directory` override). Source commit and digest identify the software; the directory and filename are only byte locations and are not part of the identity. The default directory is `$ORBIT_HOME/sandbox-artifacts`, using the Gateway's configured Orbit home.

Internal pins do not require publishing HTTPS releases. Third-party tools still require HTTPS artifact pins. Online validation fails clearly if a local file is missing or its digest differs. It never executes the binary. Existing installers keep their pre-install hash verification.

The initial Pi pin comes from `apps/pi-server` at source commit `e5933d4c8696`, file `pi-server-e5933d4c8696-linux-x64`, SHA-256 `286620c586b13b60fce4ce71f286d282da185fd95f7971c4109fb52bc8f0a78c`. It is an `amd64` build, like every recipe artifact.

A profile's effective set is the union of `common` and its own list, sorted by tool identifier. A `php:<version>` entry expands to that version's PHP set. Profiles cannot refer to other profiles, so role inheritance cannot hide software or form cycles. A multi-role Node's set is the union of its roles, not the base image set.

### Swamp worker

The one task VM base profile includes the official Swamp binary, pinned by version and SHA-256. Its release matches the orchestrator's `swamp serve`: `20261022.021804.0-sha.983e2929`. Roll out a reviewed recipe pin and matching serve release together. Image verification checks the installed binary against its artifact digest. No fleet or account credential belongs in the image.

Before publishing the base image, the builder installs the Gateway's public Orbit root certificate at `/usr/local/share/ca-certificates/orbit-root.crt` and refreshes the system trust store. Orbit CA rotation requires rebuilding the base image before new task VMs use that CA. The worker explicitly uses this certificate for its WebSocket and HTTP/2 connections. A [provision enrollment](/reference/tasks#optional-swamp-worker) starts the worker after workspace setup; an ordinary provision starts none. Swamp's [remote execution manual](https://swamp-club.com/manual/explanation/remote-execution) and [worker reference](https://swamp-club.com/manual/reference/remote-execution/worker-commands) own its protocol and reconnect behavior.

### Role and base tool sets

The recipe pins the OS packages and tool distributions for every profile. Exact versions, artifact URLs, and digests live in the recipe.

| Profile | Tools in addition to `common` |
| --- | --- |
| `common` | Bootstrap packages: `ca-certificates`, `curl`, Git and `git-man` from git-core, `gnupg`, `libnss-resolve`, `openssh-client`, `sudo`, `ufw`, and `wireguard` |
| `gateway` | Caddy from the Caddy project's release, not Ubuntu's Caddy package, and `php:8.5` |
| `app-dev` | `acl`, `attr`, Caddy, Composer, `docker.io`, OpenSSL, `php-curl`, `php-xml`, `unzip`, Vite+ (`vp`), Node, pnpm, npm/npx, and Bun; `php:8.5` and `php:8.4` |
| `app-prod` | The application prerequisites and JavaScript/Composer managers, as for `app-dev`, and `php:8.5` only. The PHP step installs `php:8.4` when an Instance selects it |
| `websocket` | Caddy, Composer, OpenSSL, `php-curl`, `php-xml`, and `php:8.5` |
| `ci-runner` | `incus`, `acl`, `attr`, `unzip`, and `php:8.5`. The [CI runner role](/reference/node-provisioning#ci-runner-role) selects this profile through the normal role union |
| `operator` | No additional role prerequisite tools; this is the recipe profile for a roleless operator Node, not a new Node role |
| `base` | The full `app-dev` set, explicit image-build packages described below, the nested Incus packages, Rust with Cargo/clippy/rustfmt, the Node agent, and Pi |

The base list explicitly contains the app-dev tools. It does not inherit a role. One pinned distribution can supply several binaries, such as npm and npx, or Rust and Cargo. Every fetched input must have a recorded digest. Production Nodes receive only their role tools.

The base also includes the existing image builder's 26 explicit Ubuntu packages: `openssh-server`; workspace tools `rsync`, `bind9-dnsutils`, `gcc`, and `libc6-dev`; and all 21 libraries in `TaskVmCloudInit::ChromiumLibraries` for Playwright's headless Chromium. These are base-only tools, not common or role inputs. Each pin is the newest version in that authenticated snapshot, identical on both architectures.

The base also holds the nested Incus packages, so a task VM [runs a topology](#prepare-the-topology) without an install:

- The exact Incus 7.0 LTS package set, whose recovery API adopts the [topology seed](#build-the-base-image).
- `dnsmasq-base`, for the nested bridge.
- `btrfs-progs`, for the pool of the topology seed.
- `nftables`, for the isolation table.

Zabbly bundles QEMU, qemu-img, virtiofsd, and firmware with Incus. No separate Ubuntu packages provide them. The recipe installs packages without recommended packages. The `base` profile is amd64 only, because Pi is. The topology Nodes start from the same base and carry these packages unused.

After the packages, the image build adds `orbit` to `incus-admin`, because cloud-init adds groups only to a user it creates. Software verification checks the membership. `image.clean` stops Incus and empties `/var/lib/incus`, and fails when it is not empty. So every VM and topology Node from the image creates its own Incus server certificate and database.

Builder cloud-init declares the `orbit` account with UID 1000 and its own primary group. It runs no account-creation script. Before preparing either an Incus or UpCloud builder, the Gateway installs the pinned agent and calls `image.owner`. That command requires both UID and GID 1000. A taken ID or a pre-existing account with different IDs fails the build; Orbit does not renumber accounts or accept another group. This check runs before recipe packages or workspace volumes can use the account. Image smoke checks require the same IDs again.

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

Merging this mitigation does not change the recipe identity, so it starts no fleet rollout or image rebuild. A Node receives the file at its next PHP installer run: an Instance PHP step, `node:converge-recipe`, or the rollout after the PHP build bump. Task VM base images and topology seeds receive it at their next rebuild through the same installer.

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

Sury, Launchpad, and Zabbly can delete superseded builds. The mirror keeps rollbacks, new Nodes, images, and CI on the exact build after that.

#### Bump Vite+

Change both `vp` and `vp-cli` versions, URLs, and integrity pins together. Regenerate `apps/gateway/resources/machine-software/vite-plus-package-lock.json` with the recipe's Node/npm, using a private package whose only dependency is the exact `vite-plus` version and `npm install --package-lock-only --ignore-scripts`. Review the complete lock, including platform packages. Update `tools.vp.lock_sha256` to the file's SHA-256. This digest is part of the recipe identity. Both images and Nodes install the locked runtime with scripts disabled and keep the existing global tool store.

#### Bump the PHP build

Sury keeps only its newest build in its pool, so keep the recipe on Sury's newest patch. One pull request changes every pin of a version together, with each URL, SHA-256 digest, and version. A partial change fails the loader.

`bin/recipe-artifacts bump` makes that change. It downloads Sury's signing key and refuses it unless it matches the pinned SHA-256 digest. It verifies the signed `InRelease` file of the recipe's Ubuntu release with `gpgv`, and checks each package index against it. For every architecture, it then moves each pin of every PHP set, `php-common` and `libgd3` included, to the newest version in the index, with its URL and digest. It stops without writing when Sury is between builds, so that two core pins of one version differ. `--dry-run` prints the changes and writes nothing.

[Nightly recipe maintenance](#nightly-recipe-maintenance) runs it every night, with the snapshot pull request. One `nightly/artifacts-<digest>` pull request holds all authenticated Sury and Incus pin changes. The digest names those pins; later nights reuse the PR, and a closed PR is never reopened.

The Gateway App verifies its signed single commit, the exact recipe-only change, the branch digest, and an authenticated update from the parent recipe using signed indexes only. That proof is cached for one hour per head; failed index fetches retry at most hourly. A newer authenticated fingerprint closes a superseded PR, even when its runner check is red. Any other edit blocks automatic merge.

The Gateway needs `python3`, `gpg`, and `gpgv`. Run `php artisan orbit:machine-recipe --validate` to check a bump by hand. A red self-hosted runner check holds this shared PR, including its Incus pins, until an operator updates the runners from the PR branch. The Gateway App then merges on green Required checks, under the same rule as Ubuntu snapshot PRs. ADR 0213 will automate runner PHP updates later. After merge:

1. The [mirror](#the-recipe-artifact-mirror) workflow publishes the new files.
2. Each task VM host [builds a new base image](#build-the-base-image).
3. [Nightly recipe maintenance](#nightly-recipe-maintenance) converges each Node, or you run `php artisan node:converge-recipe <node>`. The next [PHP step](/reference/php-runtime#php-build) of an Instance also brings its versions to the recipe.
4. Hosted CI installs the new build in its next run. See [runner updates](/reference/implementation-loop#one-php-build-in-ci) for the pre-merge operator steps.

### Recipe identity

The recipe identity is `sha256:<digest>`, computed from the validated document's canonical JSON after removing internal-artifact filenames. Loading a recipe resolves its `agent` field from the Gateway delivery manifest before hashing. This field contains the delivered Linux SHA-256 digests keyed by platform; the source recipe uses `null` and carries no agent version or artifact pins. Changing either Linux digest changes the identity. macOS artifacts and delivery storage locations do not. Stored build recipes retain the resolved map, so resuming a build cannot silently substitute another agent. A build or Node convergence refuses a missing or mismatched target pin before applying software.

Internal source commits and digests remain in the identity; relocating identical internal bytes does not change it. Canonicalization recursively sorts object keys by byte order, preserves array order, emits no insignificant whitespace, leaves slashes and Unicode unescaped, and adds no final newline. Only JSON integers, strings, arrays, objects, booleans, and null are permitted; schema fields further restrict their types. Duplicate keys are rejected before hashing. The identity is derived, not stored as a self-referential field in the recipe.

Whitespace and object-key order do not change the identity. Changing a snapshot, version pin, external artifact URL, `artifact_mirror`, source commit, digest, role assignment, schema version, or list order does. The identity covers all profiles together; there is no independent version counter for each role and no Git commit SHA masquerading as the recipe version. Git history provides provenance, while the content hash names the exact software intent even when the same recipe appears in another commit. [Node recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe) records this complete identity after verification.

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

A closed, versioned JSON document is reviewable without executing code. A content hash names all role sets together without a manually maintained counter. Separate structural and online checks distinguish malformed intent from unavailable inputs. Explicit artifact hashes cover software that Ubuntu's snapshot cannot freeze. One catalog prevents misspelled tools or roles from silently changing an image. The [image build fence](#build-the-base-image) and [nightly recipe maintenance](#nightly-recipe-maintenance) own publication and Git updates; the validator checks recipe inputs.

One recipe connects development images to production maintenance. A changed recipe rebuilds the whole base from stock on each host. Partial builds and incremental images were rejected because previous image history would become another input. A fixed nightly rebuild without a versioned recipe would not identify the intended software or connect it to production.

Trusted Gateway jobs build images and fill package caches. Agents have root inside task VMs, so letting them publish either shared result would allow one Project to affect another. The cache inventory records which Projects use each package for incident investigation.

Every workspace clones and runs Project setup. Per-Project snapshots would keep setup coupled to image builds, invalidation, and retention. Package and CI caches speed setup without a saved workspace. Fresh VMs also avoid capacity held by idle pre-enrolled guests and their refill lifecycle, at the cost of boot time.

Nightly convergence checks one Node before advancing to the next. This limits failure spread but permits a mixed-recipe fleet and a partly changed Node. Automatic rollback of a Node's convergence was rejected: the operator investigates and selects a reviewed recipe for recovery. The PHP build is the exception: its installer keeps the previous build as files and [restores it at once](/reference/php-runtime#one-installer-with-a-rollback).

#### One PHP build

PHPStan writes `PHP_VERSION_ID` and the sorted `get_loaded_extensions()` into its result cache metadata, and Pint keys its cache on the full PHP version. [CI cache refs](/reference/implementation-loop#ci-cache-refs) only help where the PHP build matches exactly. One build also gives every machine the same behavior and the same security patches.

A pin for each environment was rejected. CI, Nodes, and images would each keep a copy of the version and the extension list, and the copies drift. [Feature delivery](/reference/implementation-loop#one-php-build-in-ci) explains why CI does not use `setup-php`. [PHP runtimes](/reference/php-runtime#why-it-works-this-way) explains the Sury packages, the recipe's files instead of the Sury apt source, the one installer, and PCOV.

The Gateway's package cache cannot hold the mirror. That cache lives on each task VM host and serves no other machine, and GitHub-hosted runners cannot reach the Gateway. A release in Orbit's repository serves Nodes, images, and every CI runner.

Purging extra PHP packages on every Node could break a client site that uses an extension an operator installed. Convergence refuses instead. Only the container installer uses the `ci-runner` profile to purge, because each container runs only CI jobs. A host with that role can share other workloads and always refuses extras.

A separate scheduled GitHub workflow for the bump would duplicate the nightly recipe pull request flow and its GitHub App. Also, a pull request that `GITHUB_TOKEN` opens starts no CI.

The `ci-runner` role selects the CI host recipe and replaces the recorded-profile exception. Roleless operator Nodes do not gain workload or maintenance status from old profile settings.

#### Git from exact artifacts

A live PPA would be a second owner of Git, and an exact APT version alone does not last, because Launchpad deletes superseded builds. The cost is that Orbit, not unattended-upgrades, delivers Git security fixes, so the daily `Git build watch` workflow signals each one for review.

## Task VMs

Swamp requests review parking through `tasks.park`. A parked row reports unknown power until the provider confirms stopped. A wake invalidates that confirmation. Gateway resource observation refreshes parked power in every group status and never starts or stops a VM.

A `task_vm.destroy` Operation cannot be cancelled after its exact `provider-delete` child has succeeded with a proven deletion step. The Gateway returns `operation.cancel_refused` before changing cancellation flags or descendants. Destruction must finish or resume to remove the VM’s Node and workspace records. Before provider deletion succeeds, the Operation remains cancellable.

A task VM is an Ubuntu 26.04 VM on an Incus host. It boots from the [base image](#build-the-base-image), which each host builds from the same recipe and which holds the `app-dev` tool set. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the normal Instance and task paths, pinned to that Node. Incus provides local capacity; [UpCloud](#upcloud-overflow) provides optional overflow.

The host's image, instance-name, and host-wide resource inventories use the typed agent commands `incus.images`, `incus.instances`, and `incus.inventory`. Rust validates complete Incus output and returns fingerprints, aliases, and resource names. Instance-name reads first prove that the configured project exists; a missing project cannot count as an empty host. Host-wide inventory includes every project and storage pool so the Gateway can compare it with recorded ownership. These reads take no mutation lock. Invalid or incomplete output refuses with `task_vm.invalid_host_output`; a failed Incus call returns `task_vm.host_command_failed`.

`task-vms:prepare-host` sends the configured project, bridge, subnet, pool, and optional ZFS dataset to `incus.prepare-host`. The command validates existing storage, project network scope, and bridge address before changing them. It refuses to create a pool on a dataset that already exists. Rust owns the ACL, isolated profile NIC, project restrictions, stock image copy, and UFW forwarding rule. Repeated calls converge the same resources under the host and firewall locks. A setup failure returns `task_vm.setup_failed`; it does not claim to undo a partly completed setup.

`incus.volumes` reads the custom volumes in the configured project and pool. Swamp owns volume changes through keyed Operation children; the Gateway keeps volume ownership and inventory reads.

`incus.volume` clones a base image's topology seed for a VM, gives a completed seed its final name, or removes an explicitly selected volume. The agent derives seed names from the image fingerprint and clone names from the VM name. It preserves existing clones, refuses a missing source seed, and treats an absent removal target as removed. Volume changes share a host lock; the Gateway keeps the decision about which records and images still own each volume.

`incus.vm-state` returns one VM's running state, guest bridge address, and original image fingerprint. `incus.vm` creates, starts, stops, or deletes that VM under one target lock. The agent owns exact-name matching, Incus output validation, clean shutdown followed by forced stop, and the observation after a failed mutation. Create intent supplies the Gateway public key, image claim, resource limits, and topology requirement. The agent renders the fixed cloud-init account and QEMU configuration, clones a required seed before launch, and refuses an existing VM with a different claimed image. Delete removes the VM before releasing its seed clone. The Gateway keeps placement and resource-size policy.

Image builders and smoke VMs use the same `incus.vm` boundary. Their `create_image` intent selects the source image, account key, resource limits, and builder account mode. Rust renders cloud-init, attaches an Incus cloud-init configuration disk, and initializes the VM with NIC isolation. Before its first start, Rust removes the source image’s NoCloud seed templates. The disk supplies the account configuration at power-on, so cloud-init cannot run with an empty datasource while the Incus agent copies seed files and requests a reboot. This applies to stock builders, smoke VMs, and task VMs.

A stopped VM receives the same metadata preparation before it starts. A repeat preserves a VM from the requested image and refuses a different image. `remove_instance` removes an exact VM without changing storage volumes; the Gateway's existing image and orphan sweep decides which names it may remove. An absent VM counts as removed.

Before enrollment, guest commands use the same command runner, delivery manifest, and mismatch handling as managed Nodes. Incus carries them through its host's pinned transport with `incus exec`; UpCloud carries them through the guest's pinned SSH connection. The Incus adapter refuses source Git credentials before contacting the host, including for a builder with no task VM record. The transport stages only the pinned agent candidate. A builder has no Node row: an installation refusal fails its build, and never records a failed pin on the Incus host or creates a Node record.

`incus.instance-images` reads the original image fingerprint of each instance. `incus.image` prepares single-boot metadata, stops and publishes a builder, promotes an alias, or prunes selected image candidates. Rust preserves metadata maps, removes only NoCloud seed templates, validates publication output, and protects images used by existing instances or unrelated aliases. The Gateway selects obsolete aliases and retained fingerprints from build records. Pruning removes the selected image’s seed volumes before its image. All image changes share the Incus volume lock.

The image builder verifies the guest account before publication. Cloud-init uses the configuration disk on its first boot; publication needs no guest service ordering drop-in.

`image.boot` applies the fixed first-boot and APT service masks. Incus images also receive the DHCPv4 network file and IPv4 wait-online drop-in. `image.owner` verifies the fixed `orbit` UID and GID. `image.check` verifies that account and its authorized key, plus the provider’s requested lean-boot or active-firewall checks. These commands replace delivered image-check and boot scripts.

`guest.cloud-init` returns readiness from the guest’s complete cloud-init result and reports bootstrap errors as typed refusals. `guest.fingerprint` reads and validates the ed25519 host fingerprint through the trusted guest transport. Image builders install command mode before their first readiness check. A guest transport that cannot yet return output remains pending within the existing boot deadline; malformed or refused agent results fail the build. Incus smoke checks also prove a host key and one completed boot before promotion.

Before an image builder can install command mode, its system bus must be available. Incus can reach a guest earlier in boot. The native supervisor returns `agent.supervisor_not_ready` for an absent bus socket; image boot polling waits within its deadline. This refusal records no failed agent pin. Other installation refusals still fail the build.

Before publication, `image.clean` stops identity-writing services and removes the builder's machine, SSH, Incus, and service identities. It clears authorized keys, temporary files, Pi transfer artifacts, and logs, then verifies that the identities are absent. It preserves the installed Pi binary, package lists, and systemd private temporary directories. An agent secret or WireGuard configuration refuses cleanup before any change: an enrolled Node cannot become a base image.

The workspace Instance has one durable [workspace lease](/reference/tasks#workspace-lease). Agent turns, detached checks, publication, and teardown share that ownership even when the Gateway restarts. VM readiness and a stopped check supervisor do not prove that checkout commands stopped. An uncertain teardown retains its lease until termination is proven.

### From claim to workspace

A claim moves a group through these steps. Steps 2 to 5 are queued [jobs](#jobs).

1. A keyed provision operation reserves a group. `AllocateTaskVmAction` decides the VM's [size](#size-by-declared-topology). It records a new `provisioning` row, and queues `ProvisionTaskVm`.
2. `ProvisionTaskVm` launches the VM from the base image with cloud-init user-data. Its disk is a ZFS clone of the image. On beast, the launch takes under a second. Cloud-init is done about 10 seconds later.
3. `EnrollTaskVm` waits for cloud-init. Then it reads the guest's IPv4 address, and its ed25519 host key fingerprint from the host through `incus`. Enrollment [trusts the image](#skip-what-the-image-holds) for the `app-dev` software.
4. `EnrollTaskVm` runs the normal [Node provisioning](/reference/node-provisioning#add-a-node) for the Node `tvm-<id>`, through the host as [jump host](/reference/node-provisioning#enroll-through-a-jump-host).
5. `PrepareTaskVmRuntime` creates the group's CLIProxyAPI key and starts Pi as `orbit`. See [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). The row becomes `ready`.
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

Pi preparation uses `pi.inspect-artifact` to check the installed digest and executable mode. A missing or different binary goes through the bounded `pi.receive-artifact` receiver and `pi.install-artifact`. `pi.configure-runtime` writes the managed account's `models.json` and token under its private `.pi/agent` directory. It validates all input and existing paths before writing, publishes each file atomically with mode 0600, and repeats without rewriting unchanged files. Credentials enter only typed command input. The Gateway retains the model-key record and creates the normal `pi-server` Process. Resume checks `agent.status` before requesting Pi capabilities.

#### Skip what the image holds

Task VM enrollment trusts the [base image](#build-the-base-image) that the same Gateway code built. It does not run the `app-dev` software programs: `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, and `composer-manager`. It does not read or compare program fingerprints. Every other Node runs every program at enrollment.

Enrollment still renders and applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi.

New claims [require the deployed recipe](#claims-require-the-current-recipe). The base includes both selectable PHP versions, now `8.5` and `8.4`, and Rust with Cargo, clippy, and rustfmt. The Instance's [PHP runtime check](/reference/php-runtime#development-runtime) verifies installed packages without repeating an installation.

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

An ended group is `completed`, `failed`, or `cancelled`. Orbit removes its workspace with the VM through the same cleanup path for all three statuses. The checkout lives on the VM's disk, so Orbit deletes no checkout in the guest. Before removal, the group pushes its latest reviewed, unpublished head to origin under the shared [removal rule](/reference/tasks#complete-and-cleanup) and logs the SHA. A failed or pending push keeps the workspace and VM, including a stopped VM. A merged task is never pushed during cleanup.

A parked VM starts before preservation is attempted. Pushes, fetches, checks, and agent turns need the VM `ready`, so they finish before the VM is deleted. Workspace removal on a task VM runs these steps:

1. While the VM is `ready`, it runs the Project's teardown steps in the guest. A removal that an earlier attempt started has run them already.
2. It sets the row to `destroying`, revokes the group's model key, and deletes the VM and its volumes.
3. It removes the workspace records offline, as for [a VM that died](#a-vm-that-dies-before-its-group-ends).
4. It queues `DestroyTaskVm`, which removes the Node.

A step that fails keeps the Instance and records the error on the row. The group asks for assistance with the removal reason, and the next removal attempt continues. A group's topology runs in its VM, so it goes with the VM, and removal runs no topology release.

Explicit completion, cancellation and workspace removal queue `DestroyTaskVm`. Swamp owns retries and cleanup scheduling. `DestroyTaskVm` then runs these steps:

1. It sets the row to `destroying` and revokes the group's model key.
2. It deletes the VM, then the VM's [clone of the topology seed](#incus-provider). Incus keeps custom volumes after VM deletion. A VM or a volume that is gone counts as deleted.
3. It removes the Node offline and with force. This sheds its `app-dev` role and removes its Process rows, WireGuard peer, and private DNS records.
4. It sets the row to `destroyed`.

Orbit deleted the VM itself, so the Node removal does not probe the Node's address first. It also runs no Metrics pass, because a task VM Node never hosts a Metrics exporter.

The removal of other Instances moves a checkout within one filesystem only. [Instance removal](/reference/instance-removal) refuses a checkout on a mounted volume with `cross-device:`.

Deletion clears the row's Pi token after the VM is gone. The Node goes before the row becomes `destroyed`, which also clears the token. A row that is not `destroyed` keeps its Node a task VM Node, so Orbit never sends the Gateway's own Pi token to it. When a step fails, the row stays `destroying` with the error, and the job runs again after 1, 5, and then every 15 minutes. A [parked](#park-and-resume) VM is deleted while it is stopped. Only its workspace removal starts it first.

Keep a host's `settings.task_vms` until all its task VMs are `destroyed`, because destroy needs the settings. Node removal refuses live task VMs and configured hosts. Clear the host setting after draining it. Removing a host also deletes its destroyed VM rows.

#### A VM that dies before its group ends

Workspace removal deletes a dead VM like a live one. Only the Project's teardown steps need SSH to the VM. When they cannot run, removal fails and the group asks for `removal_failure` assistance. An ownership refusal has the same kind. Runtime decisions use the open request and kind, not its reason text.

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

#### Size by declared topology

At the claim, Orbit decides the size of the group's VM once. It takes the union of the `topology` fields of the group's subtasks that are not cancelled, and the Project's place in `task_vms.medium_projects`. The row stores the size in `size`, and it never changes while the VM exists.

| Size | When | VM | Slots |
| --- | --- | --- | --- |
| `normal` | No subtask declares a topology | The host's `cpus`, `memory`, and `disk` | 1 |
| `medium` | The topology has one test node, or no subtask declares a topology and the Project is in `task_vms.medium_projects` | 4 vCPU, 8 GiB of memory, and a 40 GiB disk | 2 |
| `large` | The topology has two test nodes | 6 vCPU, 12 GiB of memory, and a 60 GiB disk | 3 |

A test node is `app-dev`, `app-prod`, or `app-prod-2`, whatever roles its entry adds. The [test Gateway and the operator](/reference/incus-topologies#declared-nodes) run in every topology and do not count. Each size defines how many test nodes it holds, one in a medium VM and two in a large VM, and the group gets the smallest size that holds its topology. The largest size sets the most test nodes a group may declare.

The sizes are caps, not reservations. Incus does not pin the vCPUs, and the guest takes host memory only when it first uses it. Medium and large VMs have free page reporting on their memory balloon, so the host gets back the memory that the guest frees, but not the guest's page cache. Plan for a VM that runs a topology to use all of its memory on the host.

A subtask whose topology needs a larger VM than its group got waits before its first turn, with the reason `Task VM: this subtask's topology needs a larger task VM than the group got, and Orbit does not resize it.` This happens when a subtask is added or changed after the claim. Orbit never runs it without its nodes, and never moves the group to another VM. To go on, cancel that subtask, or cancel the group and create it again with the topology declared. See [Orbit Project](#orbit-project).

### Shared package cache

Retained Task workspace callers keep their synchronous VM stop, deletion, runtime and cache-preparation bindings. They do not admit an Operation and wait for it. Their owning cutover replaces those calls with parent and child Operations before removing the synchronous paths.

Each configured task VM host has one Gateway-owned package cache at `/var/lib/orbit/package-cache`. The Gateway reads hosts through `TaskVmSettings`; the cache does not select hosts by role or read the environment host list itself. Task VMs receive an Incus directory disk at `/opt/orbit/package-cache` with `readonly=true`. The restricted Incus project permits only this directory as a host disk source; host preparation and the first cache attach set that narrow exception. This host-enforced mount prevents a root agent in the guest from publishing bytes to another Project.

Swamp fills the owned host cache through the pinned local `package-cache.host` primitive. Workspace setup initializes the cache through the agent runner, attaches its fixed read-only directory with `incus.package-cache`, and runs `package-cache.seed` as the guest account to copy verified archives into private manager caches. The Incus transport names its fixed `orbit` guest account for the native supervisor, which drops privilege before account mutations. Rust owns archive validation, download credentials, redirects, integrity checks, size limits, and extraction. No cache program source crosses the transport.

Image installer services use `UMask=0022`, matching recipe execution on Nodes. Tool directories and package state must remain readable by the guest. The existing assessment owns version policy; Rust and Cargo must also report their pinned versions. Guest wrappers use the compiled agent contract to identify mutations: image owner, smoke and recipe verification reads receive only their declared inputs; mutations retain keyed effect receipts. Builder, smoke and seed boot observations stay pending until their own agent installation child succeeds. Only then does the Gateway probe cloud-init; real probe failures remain errors.

Verification runs Node, pnpm, Bun, and Vite+ launchers only as `orbit` and compares their versions with inspection. Other guest-facing binaries must report the same version as root when run as `orbit`. Launcher caches remain owned by `orbit`. The cache-parent probe reads `/opt/orbit` as that user without writing to it.

Cache failures carry a typed code, operation, path, and bounded, sanitized reason. Seed failures identify the artifact key, package, and version and retain the underlying I/O cause, including archive extraction errors. Checking the mount marker preserves access errors instead of treating them as a missing mount.

Existing images and VMs retain their permissions. After an image-builder fix, use `task-vms:build-recipe --rebuild` to replace the images, then recreate affected VMs. Attaching the cache again does not repair an old VM.

When the default branch moves in the Gateway Git mirror, Orbit compares the blob IDs of `composer.lock`, `package-lock.json`, `bun.lock`, and `pnpm-lock.yaml`, at the root and below `apps/` and `packages/`. A lock contributes only when the same directory contains a regular `composer.json` for Composer or `package.json` for JavaScript. This excludes standalone example and test fixture locks.

An unchanged commit reuses its snapshot. Changed or removed lock blobs replace the Project's desired inventory, invalidate its recorded host inventory, and queue a fill on each configured host. A Project stays pending until every configured host has verified all its selected archives. Pending fills coalesce by host; workers drop duplicates that overlap a running fill. A fill clears pending only for the inventory it read. A lock change during a fill stays pending for the next mirror fetch, including at an unchanged commit.

Retained workspace cache preparation stays synchronous. The prepared `task_vm.prepare_package_cache` workflow initializes the host cache, proves its read-only mount, and seeds only the admitted Project inventory; a failed mount prevents guest seeding. The owning workspace cutover must compose that Operation as a child before replacing its synchronous call.

The `builds` queue records one `task_vm.fill_package_cache` intent with the observed lockfile inventory. One native Swamp run fills the cache on that host’s exact Node worker, without Project setup, Composer plugins, or package install scripts. Swamp partitions the inventory and calls the pinned local `package-cache.host` primitive through sudo. The shared private-material callback supplies the GitHub credential only in worker memory and agent stdin. Bounded batch results update cache inventory through the parent Operation; there are no Gateway child Operations, executor claims, or keyed receipts. Verified archives remain available when another batch fails. A changed lockfile snapshot refuses stale inventory projection.

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

Every new task workspace starts with an empty checkout on the VM's root disk at `<apps directory>/<project slug>/workspace`. The Gateway sends the selected branches and CI cache ref bound to the tested SHA from its [Git mirror](#git-on-a-task-vm). The workspace checks out its task branch, then the baseline check runs all [Project setup steps](/reference/instance-setup), every time. Each claim runs Project setup.

After source resolution has observed the default-branch lockfiles, the Gateway attaches the [read-only package cache](#shared-package-cache) and seeds private manager caches. The `@orbit/ci-caches` setup step imports the selected [CI cache ref](/reference/instance-setup#import-ci-main-caches). Missing packages download into private caches; a missing or invalid CI ref leaves checks cold. Neither miss queues a workspace image build. The fixed checkout path preserves cache paths across claims without sharing a writable checkout.

A task VM has no default checkout and no dependency trees in its recipe image, so `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` are empty. The Project's `seed-dependencies` step therefore skips its reflink copy by design. Fresh task VMs reuse verified Composer archives to build `vendor/`, including `apps/cli/vendor`, without downloading those packages again.

For remaining GitHub downloads, trusted setup receives a fresh GitHub App installation token with only `metadata: read`. Incoming pull-request baselines receive no setup token. GitHub installation tokens expire after one hour. Orbit carries the token on a pipe into setup, then into Composer’s in-memory authentication store for API and codeload requests. It never exports the token into a process environment. Composer plugins can use it while the install runs; child commands and later checks do not inherit it.

The check stops each setup step's process group when its shell exits, times out, or is cancelled. Setup steps must finish their work before returning. Long-lived services belong in [Processes](/reference/processes-and-schedules), not setup background jobs. See the [baseline check](/reference/tasks#baseline-check) for output cleanup and timeout behavior.

Orbit writes no auth file. Setup output redacts the token before writing the check log. The Project check and deliverable commands receive no setup token.

If the App cannot supply a token, setup continues without one. Shared fills retain existing verified GitHub archives and skip uncached GitHub artifacts without authentication, while other packages still fill. Source Git fetches and pushes still run only in the Gateway mirror.

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

The ref command returns at most 1,000 distinct ref object IDs, the worktree's recorded cache baseline, its branch, and its `HEAD`. Calls inside a Tasks lease retain the workspace executor and its remote operation journal, including an agent installation after a pin mismatch.

[New task workspace selection](/reference/implementation-loop#ci-cache-refs) separates the starting code commit from the cache baseline. A main checkout uses its selected main commit. A task branch keeps its recorded main baseline in the worktree-private `refs/worktree/orbit/ci-baseline`. Another branch with no recorded baseline whose `HEAD` is a main commit, such as a fresh agent branch, uses that `HEAD` commit, not a newer main commit. [CI cache import](/reference/instance-setup#import-ci-main-caches) validates the ref and payload before using any cache files.

| Operation | Branches |
| --- | --- |
| Workspace creation | The default branch and `task-<id>` |
| [Fetch before a turn](/reference/tasks#fetch-before-a-turn) | The default branch, `task-<id>`, and a pull request base that is not the default branch |
| Base branch fetch | The pull request base |

A new workspace starts from `git init`, with the Project repository URL as `origin`, and gets its branches this way. The log messages about a fresh clone mean this new repository. The VM's refs only shrink the pack. A VM that names commits it does not have gets a pack it cannot complete, and its own Git operation fails.

#### Publish from the VM

The [hosted handoff CI prerequisite](/reference/tasks#check-an-external-group) reuses this VM push job and record for the operation's recorded commit on `task-{group}-handoff`, without force or a pull request. It adds no push journal or VM runner. Same-operation recovery reconciles the recorded push and remote tip before repeating work. Cancellation invalidates a queued push; a started push must reconcile before ownership ends. Once only CI waits, cancellation stops observation, not GitHub workflows, and retains the pushed evidence. The Gateway still refuses external review-and-merge groups. The tick-owned publication flow below keeps its existing ownership.

A VM sends its pack at its own pace, so the task tick does not publish. The push of an approved commit records the commit and `task-<id>` on the task VM row as `queued`, and queues the job `PublishTaskVmCommit` on the [Git queue](#jobs). The approval stays in review, without a failure. Each later tick reads the row:

- `queued`: the job waits or runs. The tick changes nothing.
- `pushed`: GitHub has the commit at the branch. The approval continues, and the pull request opens.
- `failed`: the tick reports the job's error as a [failed push](/reference/tasks#pull-request-and-settle-metrics), once. The next push after its backoff queues the job again.

The job records its start on the row. A job that has not finished 40 minutes after its start counts as failed. A push that waits behind other jobs stays pending, for up to 2 hours. A push whose job has not started after 2 hours counts as failed too, so a lost job or a worker that never starts cannot hold the group. The job pushes only while the row still names its commit and branch as `queued`, so a repeated or late job changes nothing. It does this:

1. The Gateway fetches the default branch and `task-<id>` into the mirror.
2. It asks the VM for a pack of the approved commit. The pack leaves out the history of the mirror's commits of those branches.
3. The Gateway calls `task-vm.export-git-pack` through the agent runner and the workspace executor. The command streams the pack to a private Gateway file through [bounded binary output](/reference/node-agent#commands).

4. The Gateway indexes the pack into a quarantine object directory with `git index-pack --strict`. That command checks every object. The quarantine reads the mirror's objects but changes nothing in the mirror.
5. It checks that the approved commit is a commit there, and that its history is complete.
6. Only then does the verified pack move into the mirror. A pack that fails a check leaves nothing behind.
7. It pushes `<commit>:refs/heads/task-<id>` from the mirror with the write token, and records the branch in the mirror.

Pack export accepts one approved commit and at most 1,000 known object IDs. It runs Git as the checkout account, uses only locally available commits to reduce the pack, and keeps the 2 GiB pack limit. A partial transfer, a missing final result, or an oversized pack fails publication before quarantine promotion or a push. The Gateway verifies the reported byte count against the received file.

The job and each of its Git commands end within 1600 seconds. Only the commit ID that the Gateway approved names what it pushes. The VM's branch names, refs, and configuration play no part.

#### What the Gateway trusts

From the VM, the Gateway takes only commit IDs and pack data. A commit ID must be 40 or 64 lowercase hex characters. Git in the mirror never reads a file from the VM. It runs with these settings: `core.hooksPath=/dev/null`, `core.fsmonitor=false`, an empty `credential.helper`, `http.followRedirects=false`, `protocol.allow=never`, and `protocol.https.allow=always`. A pack from the VM goes through `git index-pack --strict`, which runs the object checks that `transfer.fsckObjects` runs for a fetch. Fetches from GitHub skip them, because an old commit in a real repository can fail them.

The Gateway refuses to send a command that carries a source Git credential to a task VM. The separate Composer setup token described above is a narrow exception for dependency downloads.

A source command fails with `task_vm.git_credential_refused` before SSH starts. An `incus` command on a task VM host refuses one too. So an Instance or Project operation that would fetch on a task VM with a token fails there and passes no token. Examples are `instance:destroy` of a task VM workspace and `instance:clone` from one. `project:update` with a new repository URL sends no token: it reads the proposed repository on the Gateway and only sets `origin` on the VM. A task VM workspace goes with its group; see [Destroy a task VM](#destroy-a-task-vm).

The guard knows a credential by the mark that `GitReadScript` puts on its input. A test fails when other code builds the credential header. It also fails when a new file sends remote commands with GitHub access in reach, so each such path gets a review.

The checkout keeps the Project repository URL as `origin`. After setup ends, the agent can run Git there, but its environment holds no setup credential. So it cannot fetch from or push to a private repository.

### Park and resume

Swamp requests review parking through the keyed `tasks.park` operation. The Gateway checks that the published group has no open work, assistance or workspace owner, stops the VM through its provider and records `waiting_for_review` only after stopped power is confirmed. It does not run automatic idle parking or retry a failed park. Swamp owns retry policy and execution-slot accounting.

The row enters `parked` before the provider stop. Its `vm_power` stays unknown until `stop_confirmed_at` records a confirmed stop. A failed stop clears that proof and retains the error. A resource observation job refreshes proof every minute for parked VMs in every group status, including assistance and ended groups. Running, missing, unsettled or unreadable power clears the proof. Observation never starts or stops a VM.

A requested workspace operation queues the retained `ResumeTaskVm` job. It clears stop proof, starts the VM and waits for the Node agent and Pi within a fixed nine-minute deadline. Confirmed runtime readiness records `ready`; a failed wake retains its error evidence.

Parking retains the disk, workspace and sessions. It releases CPU and memory. It retains the provider's allocated VM count; Swamp's maintenance execution slots are separate.

### Sweep task VM hosts

The Gateway scheduler queues `SweepTaskVmHosts` every ten minutes. It runs while task VMs are enabled or the [UpCloud](#sweep-upcloud) token file is set. While task VMs are enabled, the sweep admits an Operation for each active configured host. `task_vm.sweep_host` starts one native Swamp run immediately on that exact Node worker. Swamp reads inventory, checks current Gateway ownership before each deletion, and projects the final observation. The old sweep children and polled dispatch path are removed.

The native run checks that the host's Incus project exists with `incus project show`. It then compares the instance names with the task VM rows. Incus lists a project that does not exist as empty, so a missing project fails the host, and the job changes nothing there:

- An **orphaned VM** is an instance named `tvm-<number>` in the project that has no task VM row on that host which is not `destroyed`. The job deletes it with `incus delete --force`.
- A **missing VM** is a `ready` or `parked` task VM row on that host whose instance is gone.
- An **orphaned volume** is a storage volume `tvm-<number>-topology-seed` in the project whose task VM has no row on that host that is not `destroyed`. The job deletes it after the orphaned VMs.

The sweep fails a missing VM only when two distinct host Operations in a row miss it. Replaying one native root or its projected result never counts as a second miss. The first miss is kept for an hour, and a sweep that finds the VM forgets it. On the second miss, the job sets the row to `failed` with `task_vm.vm_missing`. The group asks for assistance with the reason `Task VM failed:`.

Cancel or complete the group of a missing VM to destroy its Node, as for [a VM that dies before its group ends](#a-vm-that-dies-before-its-group-ends).

The job changes only the host's configured Incus project, such as `orbit-tasks`. It reads the other projects only to [report strays](#report-strays).

It retains a base image builder `tvm-image-<build id>` and its smoke VM while the build row on that host is `running`, whatever its age, or is an unclaimed `failed` build updated within the last 24 hours. Admission records retirement of expired nonrunning builds before dispatch, using the same conditional update as resume. The native run deletes only those admitted builders and rechecks running ownership before each effect. A builder without an admitted build row remains for operator inspection.

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
- the [topology seed](#build-the-base-image) `orbit-topology-seed-<12 hex>`, or a seed under construction `orbit-topology-seed-<12 hex>-new`, of an image that the host's project keeps, in its project and pool;
- an entry of the host's `operator_owned` list.

The report lists every project with `incus query /1.0/instances?all-projects=true`, and the custom volumes of every storage pool with `incus query /1.0/storage-pools/<pool>/volumes/custom?all-projects=true`. It reads the images of the host's project only to recognize their topology seeds. Images, networks, and profiles are not part of it. A host whose list fails reports `node.task_vm_inspection_failed`, and the sweep fails that host.

A **stray record** is a `ready` or `parked` task VM row on the host with no group, or whose group has ended, for more than one hour. The hour counts from the later change of the row and its group. Normally the [destroy of ended VMs](#destroy-a-task-vm) removes such a VM within minutes, so a stray record means that destroy is stuck.

A **stray Node** is a Node named `tvm-<number>` that has no task VM row that is not `destroyed`, 15 minutes after the Node was created. Enrollment creates the Node and links it to its row in the same job, so 15 minutes covers a slow enrollment. Doctor reports it in the Node's own `node` family, also while the Node is unreachable. The sweep logs it while task VMs are enabled.

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

Host sweeps use independent Operations, so one failed host does not block another host. The Gateway reads ownership and reports strays. Swamp deletes only the returned owned orphans, through commands that recheck ownership before deletion. Active and recent failed builders remain protected.

The sweep reads volume names before live VM rows, so a claim recorded during the read keeps its clone. Cleanup and resume compete for the same build fence: cleanup blocks resume, and a successful resume prevents a stale sweep from deleting the builder or smoke VM. Two distinct sweep Operations must miss a VM before its row fails; a replay of one observation cannot fail it. Doctor retains its read-only path.

### Incus provider

Swamp runs task VM effects through recorded Operations. Each effect fixes its host, guest identity and command input before execution. The Gateway admits the effect and requires a matching successful command receipt before recording completion. A retry uses the same Operation and effect key. A lost reply does not authorize a second VM or a different target.

The fixed `incus.vm` agent command runs on the Operation's host Node as its managed user. `IncusTaskVmProvider` exposes observations only. It launches the row's `image_fingerprint` as a VM with the row's `name`, the host's `cpus`, `memory`, and `disk`, and `eth0` on the host's `network` with `security.port_isolation=true`. The user-data goes on stdin. The provider never creates a network.

A [medium VM](#size-by-declared-topology) gets `limits.cpu=4`, `limits.memory=8GiB`, and a 40 GiB root disk instead, and a large VM `limits.cpu=6`, `limits.memory=12GiB`, and a 60 GiB root disk. Their config on stdin also sets `raw.qemu.conf` to turn on free page reporting for the balloon device `qemu_balloon`. Incus applies that key only when the VM starts, so the launch sets it.

A medium or large VM holds test Nodes, so it boots with a clone of the [topology seed](#build-the-base-image) of its `image_fingerprint`:

1. When the volume `tvm-<id>-topology-seed` is missing, create copies the seed `orbit-topology-seed-<12 hex>` to that name. On ZFS the copy is a clone. It uses only what the VM writes.
2. A retried create finds the clone and copies nothing.
3. The launch adds the clone as the disk device `topology-seed` in the `devices` of the instance YAML on stdin, beside its `config`.

When the host has no seed for the VM's base image, create fails with `task_vm.topology_seed_missing`, and nothing launches. A normal VM gets no clone, and an UpCloud VM is always normal. The [claim gate](#claims-require-the-current-recipe) needs a promoted base, and promotion needs the seed, so a claimed VM always finds one.

When the launch fails and the project has no `orbit-task-base` alias, create fails with `task_vm.base_image_missing`. [Build the recipe](#build-the-base-image) on the host. Orbit never falls back to the stock image: a VM from it has no `openssh-server`, so it cannot enroll.

Create, start, stop, and delete are idempotent by name. A running VM counts as created and started, a stopped or absent VM counts as stopped, and an absent VM counts as deleted. Stop waits up to 120 seconds for the guest to shut down, and then stops the VM with force.

Create starts a VM that exists but is stopped, and fails with the `incus start` error when it cannot. A launch that reports an error counts only when the VM runs afterwards. Otherwise create fails with the launch error. A delete that reports an error counts when the VM is gone. The claim checks `task_vms.enabled` before it allocates a VM. The jobs of an existing row use the provider whatever `enabled` says, so Orbit still destroys task VMs after you turn them off.

### Incus LTS packages and upgrades

Incus uses Zabbly's signed `lts-7.0` repository for Ubuntu 26.04. Each artifact records its exact Debian `version`, because architecture build timestamps differ. The tool `version` names their shared 7.0.x point release. The recipe pins `incus`, `incus-base`, and `incus-client` for amd64. Zabbly bundles QEMU, qemu-img, virtiofsd, firmware, and the agent. Hosts remove the obsolete separate Ubuntu `incus-agent` package. Rebuilt images start from stock; their stock agent unit runs `/run/incus_agent/incus-agent`, supplied by the outer host. The installer does not replace that unit.

`bin/recipe-artifacts bump` authenticates Sury and Zabbly indexes and updates their complete package sets together. It verifies the pinned signing key digests, `InRelease` signatures, package index hashes, and Incus Debian file digests. The nightly job opens one artifact bump PR under the [shared artifact update policy](#bump-the-php-build). Only changed Incus artifacts are downloaded; merge verification reads signed indexes without downloading Debian files. The existing [artifact mirror](#the-recipe-artifact-mirror) retains the same content-addressed Debian files used by Git and Sury. Managed machines have no rolling Zabbly APT source. Review the [7.0 release notes](https://discuss.linuxcontainers.org/t/incus-7-0-lts-has-been-released/26641) and [7.0.1 fixes](https://discuss.linuxcontainers.org/t/incus-7-0-1-lts-has-been-released/26942) for new requirements. The 7.0 line is supported until June 2031.

The bump tests own fixed Incus versions and fake indexes, with distinct builds per architecture. Nightly artifact and snapshot updates therefore change no expected test result. Tests that decode or send the recipe compare with the document under test.

The `ci-runner` role selects the complete Incus package set. Configured task VM hosts also add that set to their role-selected recipe tools. Other roles do not select Incus merely because it is installed; inventory those hosts separately before planning an upgrade.

Fleet convergence applies every non-Incus change while holding Incus. It records the exact held pins in the Node's `settings.machine_recipe_incus_hold` and raises an alert for each Node and held version, requesting a drain and explicit convergence. A partial convergence records `machine_recipe.partial_version`, never the complete recipe `version`. Later snapshots and PHP security builds continue to converge. A successful convergence clears the hold when Incus needs no further change.

The operator drains the host, inspects `php artisan node:converge-recipe NODE --dry-run`, then explicitly runs `php artisan node:converge-recipe NODE`. An Incus change refuses before package mutation when any project has active instances; the error names them. Live task VM rows also refuse convergence. Zabbly hooks restart the daemon directly despite `policy-rc.d`, starting its one-way database migration. A package downgrade alone is not a rollback.

Incus 7.0 requires Linux 6.12, cgroup v2, QEMU 8.2, nftables 1.0, dnsmasq 2.90, and ZFS 2.1 when using ZFS. Ubuntu 26.04 uses kernel 7.0 and cgroup v2. Incus 6.0.5 already uses nftables; 7.0 removes the alternative xtables backend. Orbit's bridge and ACL setup continues to use nftables. The harness's separate iptables rules use Ubuntu's nft backend; its isolation hook precedes either filter chain. Orbit uses neither storage buckets nor OVN.

Orbit uses complete CLI command names and explicit archive destinations, compatible with 7.0's stricter argument parsing. Its regular image archives do not use the changed file-copy behavior. Existing Ubuntu VMs retain their NVRAM filenames. On hosts only, the installer adds firmware aliases in Zabbly's bundled firmware directory. Incus 7.0.1 searches `INCUS_EDK2_PATH` then `/usr/share/OVMF`, while its AppArmor profile permits only `INCUS_EDK2_PATH` when set ([firmware selection](https://github.com/lxc/incus/blob/v7.0.1/internal/server/device/edk2/driver_edk2.go), [AppArmor](https://github.com/lxc/incus/blob/v7.0.1/internal/server/apparmor/instance.go)). Remove the aliases after an upstream release fixes selection and confinement and a VM created on Ubuntu Incus boots without them. Rebuilt images and new VMs do not need these aliases.

Roll out Shark first, then Beast, then Sabre. Services needs a separate maintenance plan if its inventory contains an Incus server; its current roles do not select Incus. Deploying the Incus 7 recipe does not require pausing task VMs or clearing host settings: fleet convergence holds the Incus change for explicit convergence. Recipe convergence skips Docker CE hosts.

Before deploying the Incus 7 recipe, the operator must complete Shark's first convergence with a Gateway release and recipe that still pin Ubuntu Incus, as a separate maintenance operation approved by Nick. Check the recorded recipe with `php artisan node:converge-recipe shark --status` on that Gateway release before merging. Without a recorded `machine_recipe`, its first convergence may include unrelated changes. Resolve those changes from its dry run, converge, and verify Doctor. The Incus 7 dry run must then isolate the Incus upgrade and deliberate pin removals. Automatic Gateway releases make this a pre-merge prerequisite.

1. Drain Shark with the admission procedure below. Inventory every project with `incus list --all-projects`.
2. Finish live task VM rows through normal lifecycle operations. Save and disable instance autostart with the drain script below, then stop all remaining instances. Take the stopped-state backup below.
3. Restart Incus 6 with guests stopped. Inspect `php artisan node:converge-recipe shark --dry-run`, then run `php artisan node:converge-recipe shark`.
4. Verify client and server 7.0.1. Compare project, VM, ZFS, custom volume, and seed inventories with the backup.
5. Boot an old-image guest and a new disposable guest. Check agent exec, stop/start, bridge isolation, and ACL egress.
6. Run `php artisan task-vms:build-image shark`. Require base and seed verification and promotion before the topology gate.
7. Run one complete topology task group explicitly on Shark. Require seed adoption, Node copies, enrollment, checks, teardown, and clone removal.

After Shark passes, upgrade Beast. Confirm that Beast's Docker CE → `docker.io` migration is applied. Merging its code does not prove host convergence. Rebuild and promote Beast's base and seed. Run the same topology gate.

Upgrade Sabre after both task VM hosts pass. Drain every `orbit-ci` runner container. Run the `hold` step below to save and disable autostart in every project before stopping instances. Back up its stopped `dir` pool and Incus database. Converge explicitly. Start and verify one runner before resuming CI. The ZFS backup below applies only to ZFS pools. Preserve Sabre's `dir` data with a separate offline copy.

Handle any Incus server on Services last, in a separately approved maintenance window with its service owners. Its app and database roles do not select Incus. Ordinary recipe convergence will not upgrade that server.

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

The Gateway compiles image input from the stored machine recipe and the pinned agent delivery. This compilation selects architecture-specific tools, separates PHP and Rust inputs, and keeps empty tool maps as JSON objects. A mismatched agent pin refuses the build before a guest phase starts.

Run `php artisan task-vms:build-recipe` on the Gateway to queue the current recipe on every active host in `settings.task_vms`. It returns build IDs without waiting for remote work. Repeating it for an unchanged recipe reuses the same rows, including failed rows; resume a failure with `task-vms:build-image NODE --resume=ID`, or restart failed builds with `task-vms:build-recipe --retry` after cleanup. `--retry` applies to both Incus builds and UpCloud template attempts.

Use `php artisan task-vms:build-recipe --rebuild` after an image-builder fix, or whenever the current recipe needs a fresh image. It rebuilds the unchanged recipe from stock on every configured Incus host and UpCloud. It cannot be combined with `--retry`. It refuses while a build for the same recipe is running or its UpCloud rebuild request is pending, or while a configured Incus host is building another recipe.

Each candidate runs the normal build, verify, smoke, topology-seed (Incus), and promote path with the desired-recipe and generation fences. A cold rebuild stays inside the current desired generation: it does not resume a halted Node rollout or resolve its incident. If the deployed recipe is not yet desired, reconcile it before requesting a cold rebuild.

A failed candidate leaves the promoted image in service. A newer promoted build for the same host and recipe resolves the failed candidate's image-build incident. Promotion swaps the image only after the candidate passes. Build records and candidate aliases distinguish rebuilds of the same recipe; the recipe identity used by Nodes does not change.

A recipe revert reuses a retained verified base. If pruning removed it, the chain rebuilds that identity from stock. `task-vms:image-builds` (also `task-vms:list-image-builds`) shows Incus recipe identities, hosts, steps, fingerprints, and immutable aliases, plus recent UpCloud attempt IDs, identities, states, times, and template UUIDs. `task-vms:build-recipe` prints the current UpCloud attempt and whether a refresh was requested or a verified template already exists. `task-vms:build-image NODE` builds the current recipe on that host; every recipe starts from stock.

Release handoff queues builds immediately when the deployed recipe identity changes. The scheduler checks each minute to recover missed submissions and pick up new hosts. There is no fixed nightly Incus rebuild. The [nightly snapshot and Ubuntu pin PR](#nightly-recipe-maintenance) supplies the daily recipe change. Unchanged releases do not build images. A host added later builds the current recipe on its next check. The same reconcile path refreshes configured UpCloud templates when their recipe identity differs.

Each host records one `task_vm_image_builds` row and one Swamp Operation per attempt. The row stores the full recipe document, identity, desired generation, and the base image location. Steps launch and boot a stock base builder, install snapshot packages and pinned artifacts, prepare boot, clean, publish, and smoke it, and then build its topology seed. The shared base preserves the source image’s DNS configuration, including link DNS for internal names. Topology Nodes use that same base fingerprint for every role.

Each effect is a keyed child Operation; the parent yields while that child runs. No workspace or Project setup runs and neither package caches nor CI cache refs enter an image.

The agent implements the image phases in Rust and reuses the Node recipe's package, archive, JavaScript, and PHP installers. Image APT uses only its recorded snapshot configuration. The agent installed before the first phase remains the Gateway delivery pin.

Rust toolchain installation reads the verified archive's component manifests and copies their declared files; it executes no installer script from the archive. It assembles the complete toolchain in a temporary directory beside the installed prefix, then replaces that prefix. This removes obsolete files on an upgrade. It records the artifact pins only after publishing the binary links, and repairs those links on repeated execution.

The Gateway records the image recipe identity with `image.recipe-marker`. It appends sanitized observations through `image.recipe-log`, which owns the fixed root-only log directory and validates the profile and phase before writing. Both commands use native file operations; no guest path or executable is supplied by the caller.

Incus and UpCloud use one ordered list of recipe installer phases: `apt-source`, `packages`, `javascript`, `rust`, `agent`, and `pi`. Before any package install, `apt-source` prepares the recipe's Ubuntu snapshot source and refreshes only its indexes. The `packages` phase fails with an `apt-source` diagnostic if that configuration is missing or does not match the recipe. To restart a failed build after a phase is added, use `task-vms:build-recipe --retry`; it clears failed Incus step history and starts a new builder attempt from stock.

Each installer phase runs in a transient systemd service on the builder. Its stable name identifies the recipe, profile, phase, and Incus build. UpCloud uses `upcloud-<build id>` as its execution key; each build gets a fresh server. On the first read after a resume, success advances the chain and an already failed unit restarts. A running unit belongs to the resumed attempt, so a later failure is reported without restarting it. The Gateway sends typed recipe input and observes the result through the agent.

Swamp reconnects to the same service after a lost SSH reply or process restart without starting another installer. Both backends retain their child Operations and native phase identities. No new Node program or inline script implements this supervision.

Before issuing a start, the Gateway records the builder boot ID in the phase state. A missing unit on that boot can start; a changed boot ID fails with a builder-reboot diagnostic naming `task-vms:build-recipe --retry`. Completed services remain loaded. Unreadable status replies have an unknown outcome and share the SSH-loss observation bound.

The native `image.inspect-phase`, `image.start-phase`, and `image.restart-phase` commands own the boot check, systemd state parsing, immutable phase input, and private log tail. The Gateway sends the recipe identity, profile, phase, and execution ID; Rust derives the unit and file names. A restart names the failed invocation already recorded by the build attempt. If another invocation has started, repeating that request only observes it. The service runs compiled agent code with typed recipe data and reports failures with a nonzero process exit.

Pi delivery uses `pi.receive-artifact` to send bounded chunks of the pinned binary. The command stores them in its fixed private cache, accepts a repeated matching chunk, and verifies the complete SHA-256 before making the artifact available. It accepts no destination path. `pi.install-artifact` publishes the verified binary at the managed account's fixed Pi path; the image Pi phase uses that same implementation. A failed transfer leaves the installed binary unchanged. Image cleanup removes complete and partial transfer artifacts under the same Pi resource lock before publication.

Each control delivery shares a 1,440-second budget, including staging and transfers. The Operation retains a fixed admission deadline while the native service keeps its own execution budget. Pi uploads use a 900-second transfer limit within that budget. The receiver retains partial bytes for a resumed transfer and rejects conflicting repeated chunks. It discards a complete artifact whose digest is wrong. Digest verification and atomic rename publish only complete input.

The guest service bounds the whole process group to 1,440 seconds, with 30 seconds to stop its children. This covers the observed eight-minute cold package install with margin. One guest lock prevents two phases from changing the builder at once. The Gateway reports a lost SSH channel with its stderr and an unknown phase outcome, then reconnects. A confirmed systemd timeout is reported as a timeout. Strict package checks remain unchanged.

Image APT installs and full upgrades use the phase's remaining execution budget. Every package command shares that deadline, including exact Debian artifacts and the PHP build. A cold install can keep downloading beyond the generic command limit without gaining a fresh phase budget. Inventory and fetch commands retain their own shorter limits.

The native service writes bounded diagnostics to its private phase log. Observations return a redacted tail when a phase fails. Direct verification returns typed failure details.

Every image package command, including assessment and the shared PHP installer, inherits the same snapshot configuration through `APT_CONFIG`. Verification checks the PHP build and loaded modules without installing or repairing it. Empty APT directory settings disable stock source parts and `/etc/apt/apt.conf.d` hooks. Stock template sources and stale live indexes cannot supply dependencies. Image origin checks use only the selected snapshot's retained indexes and never fetch indexes from another snapshot.

Image installs keep APT strict pinning and refuse Ubuntu downgrades. Every Ubuntu pin equals the newest version in the named snapshot, so the base phase's `full-upgrade` and exact installs select the same versions on Incus and UpCloud. The nightly snapshot bump updates these pins together through the authenticated indexes. `APT::Get::Always-Include-Phased-Updates "true"` makes non-pinned dependencies use the snapshot candidate on every builder, independent of machine-specific rollout percentages.

The configuration stays under `/var/tmp` and image cleanup removes it; authenticated snapshot indexes remain in `/var/lib/apt/lists` for smoke verification. Verification recreates the same configuration temporarily without refreshing indexes. Failure to prepare that temporary configuration reports the `verify` stage and stops before software checks. Git and Composer must report the same nonempty version output as root and as the guest account. This accepts a newer Ubuntu package that passed origin checks while refusing a broken guest executable.

Images live in each host's configured Incus project. The base is a VM image. Its immutable alias uses `<image_alias>-recipe-<first 12 hex characters of the recipe SHA-256>-<profile>`, where the built profile is `base`. The shared alias helper shortens the configured prefix when needed to keep every alias within Incus’s 64-character limit. The database and image properties keep the full recipe identity. Every image records `orbit.recipe` and `orbit.profile` properties and `/etc/orbit-machine-recipe.json`. The build row's `images` field exposes project, alias, fingerprint, and instance type.

#### The topology seed

The `smoke` phase keeps its VM `<builder>-smoke`, which booted the published base. The next phase, `topology-seed`, builds the host's topology seed of that base in it. A medium or large task VM [boots with a clone](#incus-provider) of the seed, and its [topology preparation](#prepare-the-topology) adopts the clone.

The Gateway calls `incus.build-topology-seed` through the agent runner as root on the host. Its typed input names the host project and pool, the smoke VM, the volume size, and the base fingerprint. Rust derives the temporary volume name from the fingerprint and owns the fixed disk device, pool, and template names. The command holds the Incus volume lock and runs these steps:

1. It deletes the volume of an earlier run.
2. It creates the block volume `orbit-topology-seed-<12 hex of the base fingerprint>-new`, 45 GiB, in the host's project and pool. ZFS volumes are thin, so the size costs nothing until Nodes write.
3. It attaches the volume to the smoke VM as the disk `topology-seed`.
4. It exports the base image to `/var/tmp` on the host and pushes the archive into the VM.
5. In the VM, Incus creates the btrfs pool `topology-seed` on `/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_topology--seed`. Incus doubles each `-` of a device name in the disk's serial.
6. In the VM, Incus imports the archive and creates the stopped VM `node-template` from it. The template has no profiles and its own root disk on `topology-seed`.
7. In the VM, Incus deletes the image. The template never boots, so it holds no machine identity.
8. In the VM, it checks that `node-template` is the one stopped VM, and stops Incus, which unmounts the pool.

Every child command reads no standard input. Then the phase deletes the smoke VM and renames the volume to `orbit-topology-seed-<12 hex>`. So a seed with that name is always a finished one. A phase that finds a finished seed builds nothing and only deletes the smoke VM.

The agent creates the `-new` volume before it changes anything in the smoke VM. So when a retry finds a `-new` volume, the smoke VM may hold the Incus state of the failed attempt: the phase deletes that VM and launches a fresh one from the published base. A retry also launches a smoke VM that is gone. Like `smoke` and `publish`, the phase runs even when the build already holds its base image.

The template's root disk keeps the size of the base image's disk. Each Node copies it, and a copy is a btrfs snapshot that uses only what the Node writes.

Only a built and smoke-tested base with its topology seed can promote the host's base alias. Consumers select the immutable base through the succeeded build row. A database write fence serializes promotion with desired-recipe changes, so an older build cannot move the base alias after a newer recipe is desired. A failed build leaves the previous images in place. The previous recipe does not satisfy the current-recipe claim gate.

Failed recipe phases record the last 40 lines of command output in the build step error, with secrets redacted and a size limit. The error names the phase log under `/var/log/orbit/recipe-images/<profile>-<phase>.log` in the retained builder. Logs are root-only. Installer logs include subprocess stdout, stderr, exit status, and Python exceptions. If the builder cannot be reached, the error reports that the log is unavailable. Publishing runs on the Incus host, so a publish failure reports the host command error and host name.

A running older build stops at its next step before that host admits the newest desired recipe. Failed resources remain available for resume for 24 hours; cleanup and resume share a database fence so only one can claim them. A restart gives the builder a name for that attempt, so delayed cleanup of an older attempt cannot delete the new builder or smoke VM.

After promotion, the final chain step prunes old recipe images on that host. It keeps the current and previous promoted base images, images used by existing VMs, and images of other running builds.

Pruning deletes a base image's topology seed, and a seed under construction, right before the image, so a kept base keeps its seed. When VMs still use clones of a deleted seed, Incus moves the seed's dataset to its `deleted` path and destroys it with the last clone, so Orbit tracks no references.

Pruning also removes the unused gateway, app-dev, app-prod, and operator aliases for host-local build records, including current and previous recipes. Images and aliases used by existing VMs stay until unused. Otherwise aliased or retained base images stay; only their unused role aliases are removed. Pruning failures resume without rebuilding. The base-only chain has 15 jobs, including the topology seed, promotion, and pruning.

### Nightly recipe maintenance

#### Nightly Ubuntu rollout policy

Nick's decision of 2026-10-19 is that every Ubuntu pin follows the named snapshot, including `resolute-updates`. The Gateway auto-merges these PRs on green Required checks and converges every eligible Linux Node, including `app-prod`, in its configured window. A failed Node halts convergence and raises an alert; later Nodes stay untouched.

Signed indexes and digests authenticate the inputs. CI verifies every Ubuntu pin, and the merge accepts only the regenerated bump on the checked head. Verified images gate rollout. Ordered Node inventory, health, and Doctor checks stop a faulty rollout. These checks make automation safe without promising that an upstream package cannot regress.

At 03:00 Europe/Amsterdam (01:00 UTC in summer, 02:00 UTC in winter), Swamp requests a recipe PR Operation. Its named `github.recipe-pr` command runs inside the Gateway against `hardimpactdev/orbit` through the existing GitHub App. The App credential stays inside the Gateway; the Operation and Swamp hold only a secret reference.

The Gateway reads main and advances `ubuntu.snapshot` to the current date at UTC midnight. The same bump resolves every `source: ubuntu` pin to the newest Debian version across the snapshot's release, updates, and security pockets. It verifies signed metadata and index digests and requires the same versions on every recipe architecture. Missing packages or differing architecture versions stop the bump without writing. Artifact versions, URLs, and hashes stay pinned; there is no separate Sury snapshot field. An unchanged or newer date creates no PR.

The Swamp maintenance workflow checks every five minutes from 03:00 through the end of the Amsterdam date until both nightly PR Operations succeed. If main has recipe definitions other than the snapshot date that the serving release does not yet carry, it logs a deployment wait without an alert and tries again on the same date. It never decodes that recipe with an older catalog. A date branch makes repeated submissions reuse the same PR. The same workflow opens the authenticated [artifact update](#bump-the-php-build) pull request for newer Sury PHP or Incus LTS builds.

Refresh and merge use one rule to end a nightly PR: close an App `nightly/recipe-*` PR once main’s snapshot has reached its date or a newer-date App PR is open. This runs before Required checks, evidence resolution, or merge attempts, whatever kept the PR open. It covers failed checks, rejected evidence, and refused merges.

The Gateway closes first, then posts a best-effort comment with the reason. A failed close retries after 300 seconds; other PRs can proceed because main’s snapshot also proves supersession after a replacement merges.

For today’s date, Refresh rebuilds a remaining failed PR only if main advanced beyond its commit’s parent. It creates one fresh Gateway App commit on current main, using an expected-head force update so a concurrent push cannot be overwritten.

Recovery is one-shot per PR: any previous force push or close/reopen blocks it. Before writing, it verifies App authorship, the single authenticated snapshot-and-Ubuntu-pin commit, the PR timeline, and body edit history, then rechecks the PR head. Refresh leaves green, pending, or closed PRs unchanged, as well as PRs modified by a person. The shared closing rule still applies to superseded App PRs. Other bots' reviews and edits also block recovery; only cross-references are ignored.

A short recovery comment is best-effort. GitHub recovery errors raise an alert without stopping tonight's snapshot or artifact PR.

A cross-reference from another issue or PR is not a modification and does not block refresh; unknown or truncated history still blocks it.

Keeping a same-date PR preserves its URL and CI history; rerunning CI alone would reuse the stale merge commit. The shared closing rule avoids keeping a superseded PR open beside tonight’s replacement.

Swamp requests retire, refresh and merge Operations for each candidate.

The Gateway checks retirement first on every merge request. A stale candidate is closed before check inspection; a failed retirement defers the merge. It performs a merge only after Required checks pass on the exact head. It verifies the PR files, patch, and one App-authored commit with GitHub’s verified web-flow signature. Date PRs must equal the snapshot bump regenerated from authenticated indexes, including every Ubuntu pin and no other change. Any extra change, commit, or different author blocks the merge and raises the existing alert; the merge uses the expected head SHA.

Successful evidence verification records the head SHA. Retries reuse that result until the head changes, avoiding repeated index downloads after a refused merge. Required checks and the live PR target, author, head, and draft state are still checked before each merge attempt.

Only a rejected recipe evidence check after green Required checks records a blocked head. The Gateway skips its evidence and index checks until the head changes.

GitHub, network, snapshot download, and merge errors remain retryable after a 300-second backoff per head. Retry, merge-refused, evidence-block, and retirement-failure alerts have distinct incident identities, so an earlier temporary failure cannot hide the rejection reason. When GitHub refuses a merge, the alert includes its HTTP status and message.

The shared closing rule applies even to a green PR whose merge was refused. Superseded PRs need no further index downloads or merge attempts. A person does not need to push a commit that would invalidate App-only evidence. This reuses the existing App and PR API without Actions secrets or a second publisher.

The Gateway admits recipe maintenance only for the serving release while task VMs are enabled. Merge and deployment have no fixed time. The existing release handoff and per-minute image reconcile build, smoke-test, and promote the base on each configured host. Maintenance adds no build trigger or composed-topology smoke run. Every host must promote the deployed identity and generation before fleet convergence starts.

A failed build keeps convergence waiting for images; it does not halt the sequence. The Incus image build path alerts once per failed attempt that needs operator recovery and names `task-vms:build-recipe --retry` as recovery. After every host promotes the same identity and generation, convergence starts on the next available tick without a new generation. An unacknowledged from-stock submission restarts on its own without an operator alert. An unacknowledged submission to reactivate a verified image set or resume a failed step alerts and needs `task-vms:build-recipe --retry`. Halts caused by Node visits remain unchanged.

The two image-build workers check `builds` before `builds-optional`, so a waiting Incus chain step takes priority over the optional UpCloud job. A running UpCloud build is not interrupted. UpCloud remains one synchronous job, with no new lock. Failures from the recipe installers and recipe verification store their phase, exit status, and bounded, sanitized stderr before deleting the builder. Other stages, including launch, cloud-init, templatize, and smoke checks outside recipe verification, report their stage error without that structured diagnostic. `php artisan task-vms:image-builds` shows the retained diagnostics. The failed-attempt fence remains; only `php artisan task-vms:build-recipe --retry` admits another attempt. This command also retries failed Incus builds.

On the next available minute, Swamp admits one Node recipe Operation. Later ticks visit the next Node only after its predecessor passes. Order is app-dev, services, app-prod, vpn, Gateway last, with Node ID inside a tier and the latest tier for a multi-role Node.

Only Nodes whose roles are all app-dev start as soon as every configured host promotes, at any hour. Shared services, app-prod, vpn, and Gateway start only from 03:00 inclusive to 06:00 exclusive Europe/Amsterdam, in strict tier order after every app-dev-only Node is done. Outside the window, the sequence stays ready until the next night. An effect already running may finish past 06:00. The Gateway refuses each later run after the window closes, using current roles and its own clock. The serving Gateway also goes last.

Only active managed Linux Nodes with roles participate. The `ci-runner` role joins the overnight service tier. Task VMs, macOS, and roleless Nodes are excluded. Each visit uses [Node recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe), including inventory, health, and Doctor checks. Nodes already verified on that identity need no repeat installation.

The first build row for the current recipe carries `fleet_convergence`, shown by `task-vms:image-builds`: generation, progress, stopping point, per-Node changes, and the alert receipt. This adds no ledger table.

A terminal failed or cancelled Node Operation halts the sequence. An interrupted visit on a restricted Node fails with `fleet.visit_interrupted` instead of resuming effects. Swamp closes its running steps and the Gateway halts the recipe build. App-dev-only visits may recover their keyed receipts. A lost transport reply does not prove that an effect failed.

Release alerts record Activity, file an assistance problem, and send the configured signed webhook. Nodes after the failure stay untouched; earlier changes remain. There is no automatic retry or rollback. Investigate with Doctor and the report, then converge manually or merge a recipe correction. Pinning the previous snapshot through a PR is manual recovery; retained images can be reused. A new desired generation starts a new sequence. Each admission checks the deployed identity so an older release cannot advance it.

### Claims require the current recipe

A new Incus claim requires a succeeded, promoted base image for the deployed recipe on its selected host. Fresh claims use that image's fingerprint. The VM records `recipe_identity` and `image_fingerprint` at allocation; launch uses the fingerprint, so a later alias move cannot change the selected image. Enrollment checks the observed fingerprint and trusts the image's software, as ADR 0206 requires. There is no full-enrollment fallback for stale images.

A host still building, missing, or failing the current recipe cannot serve a new claim. The group's `capacity_wait_reason` says `Task VM: waiting for recipe <12-character digest> images on <host>.` Another host with a ready current base can serve it. Image unavailability never triggers UpCloud overflow. An existing group keeps its VM and recorded recipe across recipe changes, failures, parking, and resume. UpCloud claims also require a verified template for the current recipe.

### Add a task VM host

A second host needs Incus, ZFS, and ufw, and its managed user needs passwordless `sudo`. Then:

1. Set the host Node's [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) with `node:settings` or the Node settings API. Use a unique `cidr` and a new `zfs_dataset`, such as `data/orbit-tasks`.
2. Run `task-vms:prepare-host {node}`. It creates the pool from the new dataset and sets up the host.
3. Run `task-vms:build-image {node}` to build the current recipe locally.

To remove a host, lower its capacity to stop new placements, wait until all its task VMs are destroyed, then clear `settings.task_vms`. Its Incus project, images, topology seeds, bridge, ACL, and pool remain for manual removal.

### Limit fleet traffic on the hub

This filter relies on the [Caddy guard](#refuse-task-vms-in-caddy). Reverb and the private Swamp Route share TCP 443 on the `websocket` Node with every other private Caddy site on that Node, such as `executor.orbit`, so an L4 rule cannot tell the sites apart. Run `task-vms:prepare-hub` only after you configure task VMs and rebuild Caddy on every Node that serves sites.

Run `task-vms:prepare-hub` once, and again when one of the values below changes. The Gateway sends typed endpoints and the reserved range to `firewall.task-vm-hub` on the `vpn` Node. The agent renders and validates the nft table `inet orbit_task_vms`, atomically publishes `/etc/orbit/task-vms/hub.nft` and the oneshot unit `orbit-task-vms-hub.service`, and verifies activation. It refuses foreign files or an unowned table before changes. A failed activation restores the previous files, service state, and live table; a failed restoration reports `task_vm.setup_failed` with the `recovery` stage.

The unit loads the table after `nftables.service` and before `wg-quick@orbit` starts. The tunnel pulls the unit in but does not depend on it, so a failed load never stops the fleet VPN. Repeated preparation converges the same filter under the shared firewall lock.

The command takes every value from Gateway records and settings:

- the reserved range from `task_vms.wireguard_range`;
- the VPN DNS address: the `vpn.dns_server` setting, or else the WireGuard address of the `vpn` Node;
- the Node with the `gateway` role, and the Pi port `orbit.pi.port` (`ORBIT_PI_PORT`, default `3774`);
- Reverb at `reverb.orbit`, which is TCP 443 on the Node with the `websocket` role; the Swamp Route uses that same Node and port;
- the host and port of `task_vms.model_proxy_origin`;
- the router of the dev Cluster.

The table filters only the reserved range. Its first rule passes every packet that has no address in the range, so traffic between other Nodes does not change. The range must be inside the WireGuard subnet, because every Node routes only the subnet through the tunnel. `TaskVmSettings` checks that. The command refuses a range that holds the address of a Node not named `tvm-<id>`. It also refuses a missing dev Cluster or origin, and an origin whose host is not an IPv4 address outside the range.

| Direction | Allowed |
| --- | --- |
| From task VMs | The Gateway API on TCP 443, CLIProxyAPI on TCP 8317, Reverb and the configured Swamp site on TCP 443, and DNS on port 53 of the VPN DNS address |
| To task VMs | The Gateway on TCP 22 and the Pi port, and the dev Cluster router on TCP 80, 443, and 5173 |

The table accepts established traffic and drops all other traffic to or from the range. The WireGuard address allocator skips the range for other Nodes. Only `AllocateTaskVmAction` assigns addresses in it. A task VM Node has no access grants to other Nodes.

### Refuse task VMs in Caddy

TCP 443 on the Gateway Node and on the `websocket` Node is one Caddy listener for every private site on that Node, so the hub table cannot tell `reverb.orbit` from `executor.orbit` or `analytics.orbit`. Caddy closes that gap. On every Node, every site except `gateway.orbit`, `reverb.orbit`, and the optional Swamp site named by `task_vms.swamp_host` aborts a client in `task_vms.wireguard_range`. [Caddy configuration](/reference/caddy-configuration#listener-addresses) shows the guard.

Set `ORBIT_TASK_VMS_SWAMP_HOST=swamp.orbit` for the managed private Swamp Route on the `websocket` Node. The hub already admits TCP 443 on that Node; the setting adds no hub rule. The exception matches exactly one hostname on port 443 and never a public Ingress site or a block with extra hostnames. The other client-range guard still applies. It admits no sibling site, subdomain, or alternate port. An unset or empty value admits no Swamp site.

Caddy renders the guard only after you configure task VMs: you enable them, or you set `dev_cluster_id`, `model_proxy_origin`, `swamp_host`, or a host. Until then, every Caddyfile stays as it was. The guard reads only that condition, `task_vms.wireguard_range`, and `task_vms.swamp_host`. Another invalid task VM value never changes a Caddyfile or blocks a build.

The next Gateway release rebuilds only the Gateway Node's Caddyfile. After you set these values, run `php artisan orbit:caddy-build NODE` for every other Node that serves sites, before the first task VM enrolls. Until then, Doctor reports `role.caddy_build_drift` on each of those Nodes. Changing or clearing `swamp_host` also reports this drift on the Node that serves the hostname until its next build. Unset these values only after every task VM is destroyed, because the next build of each Node then drops the guard.

While task VMs are configured and the range or Swamp hostname is invalid, every Node Caddy build refuses at stage `render` and names the problem, so live Caddyfiles do not change. This includes the Gateway release handoff, which fails with `gateway.release_caddy_failed`. So after [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change), run `php artisan orbit:caddy-build NODE --dry-run` for the Gateway Node. It prints `Build refused:` and the problem when the range is invalid.

### Placement invariant

A task VM workspace is a normal Instance on a normal Node, so generic Instance operations need no sandbox guard. One rule protects the VM: an Instance on a task VM Node must be the workspace its group [owns](/reference/tasks#workspace-ownership), in the group's Project. The rule runs whenever an Instance is saved, so it covers create, clone, transfer, and register. Any other Instance fails with HTTP 409 `task_vm.foreign_instance`. Orbit also refuses an access grant from a task VM Node with HTTP 409 `task_vm.access_refused`. The fleet rollout skips task VM Nodes with the reason `sandbox`.

### Configure task VMs

Global keys live in the Gateway's `config/task_vms.php`. Set them in the Gateway's environment. Per-host configuration lives in the operator-owned [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) on each host Node, not in the environment and not in its roles.

| Key | Variable | Meaning |
| --- | --- | --- |
| `task_vms.enabled` | `ORBIT_TASK_VMS_ENABLED` | Allows new task VMs. Default `false` |
| `task_vms.dev_cluster_id` | `ORBIT_TASK_VMS_DEV_CLUSTER_ID` | The ID of the Cluster that task VM Nodes join |
| `task_vms.wireguard_range` | `ORBIT_TASK_VMS_WIREGUARD_RANGE` | The reserved WireGuard range. Default `10.44.0.128/25`, the upper half of the default VPN subnet `10.44.0.0/24` |
| `task_vms.swamp_host` | `ORBIT_TASK_VMS_SWAMP_HOST` | One DNS hostname for the private Swamp Route, such as `swamp.orbit`, served on TCP 443 by the `websocket` Node. No scheme, port, path, wildcard, or list. Unset or empty admits no Swamp site |
| `task_vms.model_proxy_origin` | `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` | The CLIProxyAPI origin that Pi on the VM uses, such as `http://10.44.0.3:8317`. An `http` or `https` origin with no path, query, or credentials. It must be the CLIProxyAPI URL of the [ai-router extension](/reference/ai-router) |
| `task_vms.pi.artifact_path` | `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` | The absolute path of the pinned Pi executable on the Gateway |
| `task_vms.pi.artifact_sha256` | `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256` | Its lowercase SHA-256 digest. Set both Pi artifact values or neither |
| `task_vms.pi.models` | `ORBIT_TASK_VMS_PI_MODELS` | A JSON list of the models Pi offers. Default `[]` |
| `task_vms.medium_projects` | `ORBIT_TASK_VMS_MEDIUM_PROJECTS` | A JSON list of Project IDs whose groups get a [medium VM](#size-by-declared-topology) when no subtask declares a topology. Default `[]` |
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
| `task_vm.topology_seed_failed` | 502 | `incus.build-topology-seed` refused the build. The error includes its bounded diagnostic |
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
| Stateful snapshots of a running topology | The workspace uses virtiofs, which Incus cannot migrate statefully. Restored memory also carries old addresses and a clock jump |

## UpCloud overflow

Swamp waits for owned image-builder and orphan servers to stop before requesting deletion, then observes their absence. Each Gateway delete primitive verifies the recorded name, label and stopped state, sends one request, and returns without polling. Retained synchronous task VM lifecycle callers keep their existing provider until their parent/child cutover.


When beast and shark have no free slot, a group of an opted-in Project may get its [task VM](#task-vms) at UpCloud. The overflow is off by default. It needs both the Gateway-wide switch `ORBIT_TASK_VMS_UPCLOUD_ENABLED=true` and the Project's ID in `ORBIT_TASK_VMS_UPCLOUD_PROJECTS`. Without both, the group waits with `Task VM: no capacity.` as before.

An UpCloud task VM follows the same path as an Incus one. It is a `task_vms` row with the provider `upcloud` and no host Node. The same jobs create, enroll, park, resume, and destroy it.

It gets the same task VM user-data, enrolls as the `app-dev` Node `tvm-<id>` of the dev Cluster with an address from the reserved WireGuard range, and starts from a verified [UpCloud template](#build-the-upcloud-template). UpCloud uses the same `recipe.json` base-profile installer as Incus.

Its template's `orbit.recipe` storage label records the full recipe identity, and each new claim records the verified template UUID for that identity. Template lookup runs before the admission transaction; admission rechecks the deployed identity and capacity. Provisioning clones the recorded UUID, including after a recipe change. So the hub filter, the Caddy guard, the rollout exclusion, and the placement invariant apply unchanged. Each claim [clones and sets up its workspace](#clone-and-set-up-every-workspace), as on Incus, without a shared host package cache on UpCloud.

### Place a VM at UpCloud

Placement creates a new VM on the Incus host with the most free slots, as in [Place task VMs](#place-task-vms). Only when no host has a free slot does a new VM go to UpCloud, and only while the switch is on, the group's Project is opted in, and fewer than `max_vms` UpCloud VMs are not `destroyed`. Parked and failed UpCloud VMs count. A VM never moves to another provider.

### Create and reach an UpCloud server

The server is named `tvm-<id>` in its title and hostname, and carries the label `orbit-task-vm=<id>`. Orbit records its UUID. A create whose answer was lost is found again by its label, so Orbit never sends a second create. Before each create, Orbit counts the account's servers with the `orbit-task-vm` label and refuses at `max_vms` with `task_vm.upcloud_budget_full`. A server that lost its row counts too.

The server clones the newest verified template for the claim's recorded recipe onto a disk with the storage tier of its plan, such as `standard` for a Starter plan. The default plan includes 30 GB of that storage, so the default disk costs nothing extra. It has one public IPv4 interface, and no utility or private network, so it reaches no private address of the account. Fleet traffic goes only through WireGuard and the hub filter. Console access is off. The UpCloud firewall stays off: it is stateless, so reply traffic needs source-port rules that open other ports too.

The image builder calls `image.firewall` through the guest agent runner before identity cleanup. The command denies incoming traffic by default, allows public SSH on port 22 with the `orbit:public-ssh-recovery` comment, enables UFW, and verifies that it is active. A failed command or inactive firewall stops publication with `task_vm.image_check_failed`, mapped to the build failure at the Gateway. Repeated calls preserve the same fixed policy under the firewall lock.

The server boots with ufw on, from the template. ufw denies all incoming traffic except public SSH on port 22, so Caddy and the other services in the image are not public before enrollment. The template holds the exact rule `orbit:public-ssh-recovery` that enrollment opens on a new Node. Enrollment finds it in place and changes nothing, then the `app-dev` role adds WireGuard trust and closes public SSH, as on any public `app-dev` Node.

UpCloud has no trusted host that can read the guest's SSH host key, as Incus does. So the Gateway generates an ed25519 host key for each server, adds it to the user-data, and pins its fingerprint. No connection trusts a key on first use. Root login stays off. The Gateway reads cloud-init over SSH as `orbit` at the public address, and enrollment needs no jump Node.

A park stops the server cleanly, and a hard stop follows after 150 seconds. UpCloud bills a stopped server for its storage only. A destroy stops the server, deletes it with its disk, and waits until it is gone.

Swamp orders UpCloud image cleanup and orphan sweeps. Each Gateway primitive submits one fixed API action. These workflows stop a server before deletion and confirm absence afterward. Task VM provisioning, enrollment, resume, destruction and runtime preparation retain their synchronous jobs until the owning Node and Process child composition is installed. Their new admission actions refuse with `operation.kind_unavailable` while that composition is missing. A registered owning kind alone does not enable a parent that has no matching child declaration.

### Build the UpCloud template

`php artisan task-vms:build-upcloud-image` builds the template on the Gateway. It creates billed servers. The build prints each stage with its time:

| Stage | What it does |
| --- | --- |
| `sweep` | Deletes the build servers and unverified templates that an interrupted build left |
| `launch` | Boots a builder server from the stock Ubuntu 26.04 template with the base image builder user-data |
| `cloud-init`, `orbit-user` | Waits for cloud-init over SSH, and checks the pinned `orbit` uid and gid |
| `apt-source`, `packages`, `javascript`, `rust`, `agent`, `pi` | Prepares Ubuntu snapshot indexes, then installs the base profile through the shared recipe installer with exact versions and integrity pins |
| `recipe-marker`, `verify`, `masks` | Writes the recipe identity and profile marker, verifies the recipe software, and masks the first-boot and apt timers |
| `firewall` | Turns ufw on: incoming traffic denied, except the public SSH rule `orbit:public-ssh-recovery` on port 22 |
| `clean` | Runs the native `image.clean` command |
| `templatize`, `delete-builder` | Stops the builder, turns its disk into the template `orbit-task-base-<time>-unverified`, and deletes the builder |
| `smoke` | Boots a server from the template with the task VM user-data, and checks cloud-init, ufw, and the key, uid, and gid of `orbit` |
| `promote`, `prune` | Records the `orbit.recipe` storage label, renames the template to `orbit-task-base-<time>`, and deletes older verified templates except those pinned by live VMs awaiting server creation |

A failed build deletes its servers and its unverified template, and keeps the previous template. Without a verified template for the claim's recipe, a create fails with `task_vm.base_image_missing`.

One build runs at a time. A build marks its time stamp as running for two hours, and no sweep deletes the servers or the template of a marked build.

### Refresh the UpCloud template on recipe changes

The per-minute recipe reconcile and release handoff queue `RefreshUpCloudTaskVmBaseImage` on the `builds-optional` queue when UpCloud is configured and no verified template matches the deployed recipe. The [Gateway queue](/reference/gateway-queue#queues) gives waiting required builds priority. There is no separate nightly schedule.

The job checks the identity again before building. A database ledger records each attempt before remote work, including failed and interrupted builds. Reconcile does not dispatch that identity again unless `task-vms:build-recipe --retry` explicitly requests it. A new identity can build automatically. A queued build that finds an earlier attempt refuses with `task_vm.image_build_superseded`; use the explicit retry command instead of redispatching it. The manual `task-vms:build-upcloud-image` command also starts an explicit attempt.

Reconcile raises a recipe-maintenance alert when the current identity has a failed attempt, or a running attempt at least two hours old, and no verified template. The alert names the identity and attempt and includes `php artisan task-vms:build-recipe --retry`. Repeated alerts for an attempt are limited to one per hour. The build command also reports this fenced state. Reconcile reports only the latest attempt and stops alerting once a verified template exists. `--retry` also retries failed Incus builds.

The build keeps the two newest verified templates and any template pinned by a live VM awaiting server creation. Pruning ignores the source template reference once the VM has a server UUID. It also runs with overflow off without enabling claims. A failed build keeps the previous template, but a stale template cannot serve a new claim. Reconcile reports UpCloud token, API, or refresh-dispatch errors and continues Incus submission recovery and image scheduling.

The build takes about 20 minutes and occupies one `builds` worker. When it runs past its 1700 seconds, the worker stops it, and the sweep deletes its servers two hours after its start.

### Sweep UpCloud

While the token file is set, also with the switch or task VMs off, the [host sweep](#sweep-task-vm-hosts) lists the servers with the `orbit-task-vm` label. So no server that Orbit created keeps costing money. The sweep deletes each server named `tvm-<label value>` without a row that is not `destroyed`. A `ready` or `parked` UpCloud row whose server is gone on two sweeps in a row becomes `failed`, and its group asks for assistance. It also deletes build servers and unverified templates older than two hours, which a killed build leaves, except those of a build that is still marked as running.

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

- List it in `task_vms.medium_projects`, so its groups get a [medium VM](#size-by-declared-topology) with 4 vCPU. Its check runs the test suites in parallel.
- Add the setup step [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) after the install steps.

Without the `@orbit/ci-caches` step, Pint and PHPStan start cold in every task VM workspace. With it, a fresh workspace gets the Pint and PHPStan caches that main CI published for its baseline.

The setup and teardown steps run on the VM as `orbit`. The `seed-dependencies` step does nothing there, because a task VM workspace has no `ORBIT_SEED_PATH`: the install steps build fresh dependency trees using private caches seeded from the read-only host cache.

### Declare a topology

A subtask declares the test nodes it needs in its `topology` field, for example `["app-dev"]` or `["app-dev", "app-prod+ingress"]`. Each entry names one node, `app-dev`, `app-prod`, or `app-prod-2`, at most once. After a `+` it may add the role `ingress`. `app-prod-2` needs `app-prod` in the same list. A role that conflicts with the node's base role, such as `app-dev+ingress`, is refused, by the [role compatibility](/reference/node-provisioning#role-compatibility) rules. The format takes more roles once one is proven to converge on a test node. Subtask creation and group creation keep the declaration. Only a subtask that has not started can change it.

The subtasks of a group declare at most two test nodes together, as many as a large VM holds. In the spike, a 12 GiB VM was full with two, so a third would run it out of memory. A group that declares more is refused with `tasks.invalid_topology`.

The Gateway validates a declaration once, when a request or `tasks:render-prompt` gives it. It trusts a stored declaration. So a node or role that leaves the format later never fails a tick or a response for a subtask that declares it; the harness refuses that node when the topology is prepared.

The group's topology is the union of its subtasks that are not cancelled: each node once, with every role any subtask adds to it. It decides the [VM size](#size-by-declared-topology) at the claim.

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
