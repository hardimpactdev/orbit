# Tool Catalog: `php-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PHP CLI toolchain's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `php-cli` |
| Label | PHP CLI |
| Backend | Orbit-owned static PHP binaries built with static-php-cli 2.8.5 |
| Support model | Installable and updatable by Orbit on app-dev/app-prod nodes |
| Category | `runtime` |

## Variants

`php-cli` is one tool slug. The selected runtime family is stored on the node
tool row as `NodeTool.config.variant`:

| Variant | Selected by | PCOV |
| --- | --- | --- |
| `coverage` | `app-dev` role baseline | Statically linked; `pcov.enabled=1` by default; no `pcov.directory` hardcoding |
| `standard` | `app-prod` role baseline | Omitted entirely |

`app-dev` and `app-prod` conflict as simultaneous owners of the host toolchain
contract, so there is no dual-role ambiguity. Manual `tool:install php-cli`
without an explicit variant keeps any stored variant, otherwise resolves the
role-derived variant, and always persists the resolved value. Invalid explicit
variants are rejected. `tool:update php-cli` applies role-owned variant
authority through `PhpCliVariantResolver` (so stale stored coverage on
`app-prod` or stale standard on `app-dev` is corrected and persisted) before
running the update script.

## Capabilities

`php-cli` supports `tool:install`, `tool:update`, and safe doctor adopt.

`tool:install php-cli` downloads Orbit-owned statically linked PHP CLI artifacts
for every supported minor (8.3, 8.4, 8.5) at pinned patch releases. Artifacts
include the full Laravel-oriented extension set. Coverage artifacts additionally
include statically linked PCOV. Every artifact pins official SQLite 3.44.6 and
is rejected unless both `SQLite3::version()` and `select sqlite_version()` report
that fixed release (or an explicitly accepted later fixed release).

Orbit keeps two catalog files:

| File | Role |
| --- | --- |
| `packages/core/resources/php-cli/artifact-catalog.json` | **Runtime consumer** used by install/update/E2E |
| `packages/core/resources/php-cli/artifact-catalog.build.json` | **Build/handoff matrix** used by the builder and CI only |

Until the fleet-scoped 9-cell matrix is published, the runtime catalog uses
`install_contract=compatibility`: PHP 8.5 from the currently published Orbit
SQLite-safe objects (`php-<patch>-cli-<os>-<arch>.tar.gz` with real checksums)
and PHP 8.4/8.3 from the **intentional** historical bulk static-php.dev path
(those bulk downloads are unchecksummed until matrix cutover). Install and
update keep working on that retained path. Role `variant` is still persisted
as the *desired* variant. After promotion to `install_contract=matrix`,
**matrix mode** fails closed on missing cell checksums (no bulk fallback and
no invented digests). Compatibility does not apply a global fail-closed rule
to every unpublished checksum.

During `install_contract=compatibility`, doctor classifies against the
**effective** standard compatibility runtime (the binaries actually installed),
while still exposing `desired_variant`, `effective_variant`,
`install_contract`, and `matrix_cutover_pending` in probe evidence. A healthy
standard compatibility runtime on an `app-dev` node that desires coverage is
**not** permanent `tool.php_cli_coverage_missing` drift and does not trigger a
reinstall loop. After promotion to `install_contract=matrix`, doctor enforces
coverage/PCOV normally against the effective coverage runtime.

After object-storage publication of all 9 fleet matrix artifacts, CI promotes
the runtime catalog to `install_contract=matrix`. Matrix artifact names include
the variant:

`php-<patch>-cli-<variant>-<os>-<arch>.tar.gz`

The production matrix is **fleet-scoped**, not a full OS/arch cross-product.
For each pinned patch (`8.3.31`, `8.4.21`, `8.5.8`) Orbit publishes exactly:

| Variant | Platform | Fleet use |
| --- | --- | --- |
| `coverage` | `linux-x86_64` | Ubuntu app-dev (beast) |
| `coverage` | `macos-aarch64` | macOS ARM app-dev (mini, NMBP) |
| `standard` | `linux-x86_64` | Ubuntu app-prod (main1) |

There are no production `linux-aarch64`, `macos-x86_64`, or standard macOS
artifacts (9 artifacts total). Role variant authority is unchanged: `app-dev`
desires coverage, `app-prod` desires standard.

Binaries install to `/opt/orbit/php/<minor>/bin/php` with per-version symlinks at
`/usr/local/bin/php<minor>`. PHP 8.5 is the default `php` at
`/usr/local/bin/php`.

Install and update are staged: every requested minor is downloaded, checksummed,
and verified in a temporary directory first. Only after all minors pass does
Orbit replace installed binaries and relink. A download, checksum, version,
SQLite, PCOV, or static-linkage failure leaves previously installed runtimes
unchanged.

`tool:update php-cli` re-downloads, verifies, and re-links all pinned binaries
for the role-resolved variant (persisting any correction first). It uses the
same staged logic as install and is safe to re-run.

## Pest TIA

Coverage PHP enables Pest 5 Test Impact Analysis on app-dev nodes:

```bash
vendor/bin/pest --tia --fresh
```

Requirements on the active `php` binary:

- `extension_loaded('pcov') === true`
- `function_exists('pcov\\start') === true`
- `ini_get('pcov.enabled')` is truthy (`1`)
- `php --ri pcov` succeeds

Orbit does not hardcode `pcov.directory`; Pest and operators choose the project
tree. Standard app-prod PHP must not load PCOV.

## Credentials

`php-cli` does not support `tool:credentials`.

## Orbit Notes

`php-cli` is separate from `php`. `php-cli` describes the **host** PHP CLI
toolchain installed on app-dev and app-prod nodes; it runs deploy steps and
native host PHP/Artisan workloads. `php` owns PHP image capability evidence for
containerised app and workspace web serving.

All three supported minor versions (8.3, 8.4, 8.5) are installed side-by-side at
pinned patch releases. The default `php` binary resolves to the 8.5 binary.

Linux artifacts are fully static. Builder verification retains `ldd` evidence
that the binary is non-dynamic.

## Artifact build and release

Orbit builds artifacts with `bin/orbit-build-php-cli-runtime`:

```bash
bin/orbit-build-php-cli-runtime \
  --php-version 8.5.8 \
  --variant coverage \
  --output-directory dist/php-cli
```

Coverage builds pin PCOV **1.0.12** from the versioned PECL archive
`https://pecl.php.net/get/pcov-1.0.12.tgz` (checksum recorded in the shared
catalog as `pcov.archive_sha256`). The builder downloads that exact tarball,
verifies the SHA-256, and passes it to static-php-cli as
`--custom-url=pcov:file://...` so SPC never follows the moving
`https://pecl.php.net/get/pcov` URL from its `source.json`.

The builder also checksum-fetches official static-php-cli 2.8.5
`config/ext.json` and `config/source.json` into the build working directory
(digests pinned as `static_php_cli_ext_json_sha256` and
`static_php_cli_source_json_sha256`). Coverage builds then apply two Orbit
patches and validate both programmatically:

1. `static-php-cli-2.8.5-pcov-static.patch` — `ext.json` `pcov.target` from
   `shared` to `static`
2. `static-php-cli-2.8.5-pcov-source-path.patch` — add
   `source.json` `pcov.path = "php-src/ext/pcov"` (stock SPC 2.8.5 omits
   this; without it PCOV extracts to `source/pcov`, buildconf ignores
   `config.m4`, and configure reports `WARNING: unrecognized options:
   --enable-pcov`)

Because `spc download` only stages archives (`source/` is created during
`spc build`), the builder does **not** patch in-tree after download. Instead,
after verifying the pinned upstream PCOV archive SHA-256 and contents, it
extracts that tarball to a temp dir, applies
`pcov-1.0.12-config-m4-php-version.patch` to `pcov-1.0.12/config.m4`,
fail-closed validates the patched file, repacks a **separate** temporary
archive as `tar -czf … -C extract pcov-<version>` (members
`pcov-<version>/…`, never `./pcov-<version>/…` from archiving `.`, which SPC
nests incorrectly so buildconf misses `config.m4`), and points
`--custom-url=pcov:file://...` at the patched archive. The original upstream
tarball remains unmodified and keeps the catalog pin. Stock
PCOV 1.0.12 does `PHP_VERSION=$($PHP_CONFIG --vernum)`; in static in-tree
builds `PHP_CONFIG` is empty, which clobbers PHP's global `PHP_VERSION` and
breaks Swoole (`the PHP_VERSION variable must be defined`). The patch uses a
PCOV-local `PCOV_PHP_VERSION` (`$PHP_CONFIG --vernum` when set, otherwise
`$PHP_VERSION_ID`) and rewrites comparisons.

Coverage builds inject `pcov.enabled=1` via SPC `--with-hardcoded-ini` and
refuse to distribute `pcov.so`. Standard builds omit PCOV.

GitHub Actions workflow `.github/workflows/orbit-php-cli-runtime.yml` is an
**artifact release lane**, not default feature CI. Ordinary PR/push validation
is static Pest coverage of the workflow YAML, builder script, catalogs, and
install contracts. The fleet-scoped 9-cell matrix (long builds on
`ubuntu-24.04` and `macos-15` only) runs only on explicit `workflow_dispatch`
when intentionally producing release artifacts.

Each matrix cell installs a pinned **host** PHP 8.5 via
`shivammathur/setup-php@v2` (same pattern as `orbit-cli-binary` /
`orbit-release`) before platform build dependencies and
`bin/orbit-build-php-cli-runtime`. That host PHP only runs builder tooling
(catalog JSON via `php -r`); it is not the static `php-cli` artifact. Matrix
`php_version` still selects the pinned patch built into each tarball. macOS
GitHub-hosted images may omit system PHP, so the workflow must not assume a
preinstalled `php` binary.

Object-storage publication is a separate opt-in on that same dispatch:
`publish_to_object_storage=true`. It requires repository secrets
`ORBIT_ARTIFACTS_ACCESS_KEY`, `ORBIT_ARTIFACTS_SECRET_KEY`,
`ORBIT_ARTIFACTS_BUCKET`, `ORBIT_ARTIFACTS_ENDPOINT`, and optional
`ORBIT_ARTIFACTS_REGION`. The publish job requires the runner-provided
`aws` CLI (`aws --version` prerequisite; no `pip install --user awscli`).
Fixed version/variant/platform object keys under the immutable prefix
`orbit/runtimes/php-cli/sqlite-3.44.6/` are never overwritten with different
bytes. For each object the publish job:

1. runs `s3api head-object` and captures stderr/status
2. **definite absence only** (`404` / `NotFound` / `NoSuchKey`) → `aws s3 cp`
   once, then verifies the public consumer URL
3. **present** and object metadata `sha256` and/or public
   `${artifact_base_url}/${filename}` SHA equals the local build → **skip**
   (no `s3 cp`)
4. **present** and hash differs → **exit before any `s3 cp`**, require a new
   versioned prefix/pin
5. **any other head failure** (auth, network, 5xx, `AccessDenied`, unknown)
   → **fail closed before upload** — never treat ambiguous head errors as
   absence (that would open an overwrite path)

`aws s3 cp` runs only on the definite-absence branch. That public URL is what
install/update consumes after promotion. Only after every public URL verifies
does publish run:

```bash
bin/orbit-php-cli-catalog-handoff \
  --manifest-dir assembled/manifests \
  --catalog packages/core/resources/php-cli/artifact-catalog.build.json \
  --promote-runtime
```

Handoff manifests must declare `tool=php-cli`, a non-empty `filename` exactly
equal to the expected cell name
(`php-<patch>-cli-<variant>-<platform>.tar.gz` — missing or empty filenames are
rejected, never defaulted), and unique cells. Runtime promotion requires the
build catalog's `artifact_base_url` (no hardcoded fallback). Commit the
resulting catalog files to land production cutover. There is no moving
unauthenticated `latest` contract.

## Doctor Relationship

`doctor --family=tool` probes every supported minor. Desired variant comes from
role ownership / stored config; effective variant is `standard` under
`install_contract=compatibility` and the desired variant under
`install_contract=matrix`.

| Observation | Code |
| --- | --- |
| A required minor binary is absent, or the probe is incomplete | `tool.capability_missing` |
| Effective coverage runtime lacks working PCOV (matrix contract only) | `tool.php_cli_coverage_missing` |
| Patch or standard-variant contract mismatches against the effective runtime | `tool.version_mismatch` |

Restore reinstalls `php-cli` with the role-resolved variant so production nodes
always get standard and development nodes get coverage (install still follows
the active install contract).
