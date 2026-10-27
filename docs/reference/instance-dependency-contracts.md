---
title: "Dependency contracts"
description: "The graph values, lockfile reader rules, collection limits, stored tables, and response shapes behind Instance dependency inventory."
covers:
  - apps/gateway/app/Domain/Instances/Dependencies/**
  - apps/gateway/app/Actions/Instances/Dependencies/**
  - apps/agent/src/commands/{dependencies,dependencies_tests}.rs
  - apps/gateway/app/Models/{DependencyPackage,InstanceDependencyObservation,InstanceDependencyResolution,InstanceDependencyEdge,InstanceDependencyScanAttempt}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - packages/php-sdk/src/Support/{DependencyInventoryDecoder,InstanceResolutionDecoder}.php
  - packages/php-sdk/src/Requests/Instances/{Show,Scan,Update}InstanceDependenciesRequest.php
  - packages/php-sdk/src/Requests/Instances/{ResolveInstanceRequest,ResolveDirectoryInstanceRequest}.php
---

# Dependency contracts

This page holds the details behind [Instance dependencies](/reference/instance-dependencies): how the readers build a graph, how collection reads files, what the Gateway stores, and what the API returns. The code lives in `App\Domain\Instances\Dependencies` and `App\Actions\Instances\Dependencies`.

## Graph values

Each reader returns one `DependencyGraph` per ecosystem.

| Value | Contract |
| --- | --- |
| `DependencyIdentity` | Ecosystem, `composer` or `npm`, and the canonical package name. |
| `DependencyResolution` | A graph-local ID, the identity, the locked version as an opaque string, separate `regular` and `development` flags, and an optional source reference and integrity hash. |
| `DependencyRequirement` | Source (null for the root), target (null when unresolved), declared name and constraint, kind (`dependency` or `peer`), scope, and `optional`. |
| `DependencyGraph` | The resolutions and requirements. IDs are unique, and every endpoint points into the same graph. |

The resolution ID keeps installation paths and peer contexts apart, so one version can appear twice. Reachability comes from actual paths from the root. A package's own development requirements install nothing. A null target never claims that a package is present.

## Reader rules

Every reader parses data in memory. None reads files, runs a package manager or script, or contacts a registry. Errors carry a stable code and a short `error_detail` naming a file or failed check, never input text, URLs, credentials, or raw parser messages. The detail is stored with the attempt and returned by scan and show, including when the previous successful snapshot is retained. Older attempts without a stored detail receive a generic diagnostic for their error code on read. Versions stay opaque, and URLs and credentials never reach the result.

This table sums up each reader.

| Reader | Reads | Resolution ID | Mismatch with `package.json` | Invalid data |
| --- | --- | --- | --- | --- |
| Composer | `packages` and `packages-dev` | Package name; `path:<package-name>` for a locked path distribution | Not checked | `dependencies.invalid_composer_input` |
| npm | `lockfileVersion` 2 and 3, `packages` map | Installation path | `dependencies.stale_npm_lockfile` | `dependencies.invalid_npm_input` |
| pnpm | `lockfileVersion` 9.0, importer `.` | Snapshot key | `dependencies.stale_pnpm_lockfile` | `dependencies.invalid_pnpm_input` |
| Bun | Text `bun.lock`, `lockfileVersion` 1 or 2, workspace `""` | Package path | `dependencies.stale_bun_lockfile` | `dependencies.invalid_bun_input` |

A root `workspaces` layout, several importers, and JavaScript local links return `dependencies.unsupported_layout`. The npm reader ignores `workspaces` metadata on transitive package records. An unsupported lockfile version, Bun version 3, and binary `bun.lockb` return `dependencies.unsupported_format`.

### Composer

Root `require` and `require-dev` set the two reachability paths. Platform requirements such as `php` and `ext-*` stay unresolved edges. A unique locked provider or replacement satisfies a virtual requirement. Aliases point at the locked resolution. Repository URLs are dropped, and source revisions stay. Path repository declarations are accepted; a declaration alone creates no resolution. A locked `dist.type: path` package uses `path:<canonical-package-name>` as its graph-local resolution ID. Every incoming and outgoing requirement uses that same ID. The package identity remains its canonical Composer name, so regular and development reachability, virtual providers, replacements, aliases, and cycles use the normal Composer rules.

### npm

The caller picks `npm-shrinkwrap.json` before `package-lock.json`. Lookup walks from the nearest package location to the root, as npm does. An alias edge keeps its declared name. Its range must match the locked version under npm's loose semver rules. A dist-tag alias needs a registry tarball. Bundled dependency names without a lock entry stay optional edges with no target. An empty `packages` map is a valid empty graph when the manifest declares nothing.

### pnpm

Snapshot keys keep peer contexts apart. A remote tarball key becomes `pnpm:sha256:<digest>`, so no URL is kept. The importer must match the manifest's specifiers and scopes.

### Bun

Comments and trailing commas are allowed. Git and remote tarball references become `bun:sha256:<digest>`. `optionalPeers` marks optional peer edges, and an empty specifier means any version.

### Local packages

Composer path distributions carry the name, version, requirements, and optional revision needed to build their inventory in `composer.lock`. Orbit records this locked metadata without following, resolving, or persisting the path URL. This includes paths outside the checkout: no read is authorized by the lockfile. The inventory does not prove that local source exists or matches its recorded revision. A missing lockfile, missing locked requirement, or malformed package still fails normally.

A JavaScript `file:` or `link:` dependency returns `dependencies.unsupported_layout`. Support would need a second read of files at lock-supplied paths, with the same path safety, in the same source observation.

## Package manager selection

The JavaScript manager comes from `packageManager` or `devEngines.packageManager`, then lockfiles and configuration files. A single declared manager takes precedence over stray lockfiles and configuration signals; conflicting declarations fail. A declared manager without its own lockfile fails rather than falling back to a different manager. Without a declaration, conflicting manager families still fail. Configuration files are only presence signals. They are never evaluated.

| Case | Result |
| --- | --- |
| Conflicting declarations, or two manager families without a declaration | `dependencies.ambiguous_manager` |
| Any Yarn signal | `dependencies.unsupported_format` |
| `workspaces` or `pnpm-workspace.yaml` | `dependencies.unsupported_layout` |
| Duplicate keys in `package.json` | `dependencies.invalid_manifest` |
| A manager without its lockfile | `dependencies.incomplete_source` |

No signal at all means pnpm, which still needs `pnpm-lock.yaml`.

## Collection

`CollectInstanceDependencyFilesAction` reads the recorded checkout, or `current` of a production Instance, through the [Node agent](/reference/node-agent#commands). `instance.read-dependency-files` reads a checkout as the managed account. `instance.read-release-dependency-files` reads a production home's `current` release as root, because only the production user may enter that home; neither runs project code. Non-monorepo types retain their existing single-directory behavior. When `source_is_laravel` is true or the Project type is web-serving (`laravel-app` or `symfony-app`), collection reads manifests, lockfiles, and manager signals only in the [application directory](/reference/projects#application-directory) derived from the effective web root. Root `apps/site/public` selects `apps/site` inside the checkout or selected release, not the repository-root manifests. Other non-monorepo Instances keep repository-root collection.

### Monorepo discovery

For Project type `monorepo`, collection inspects only direct child directories of the repository's top-level `apps/` and `packages/`. It does not recurse below those candidates or discover projects elsewhere. The repository is the development checkout or the pinned production release. Discovery starts at that root even when `source_is_laravel` is true and the configured web root is nested, such as `apps/gateway/public`. The application-directory suffix selects the fallback source only when neither top-level directory exists.

A candidate qualifies as a web project if it contains `composer.json`, or if it contains `package.json` and at least one of `package-lock.json`, `npm-shrinkwrap.json`, `pnpm-lock.yaml`, or `bun.lock`. These are file-presence tests, not successful parsing tests. `composer.json` without `composer.lock` qualifies and then fails with `dependencies.incomplete_source`. A candidate qualifying through Composer can still have a failing JavaScript ecosystem. A JavaScript-only candidate with no listed lockfile does not qualify, even if it has a Yarn lockfile or `bun.lockb`.

Collection skips candidates that do not qualify and records nothing for them. If neither top-level directory exists, collection uses the existing single-directory behavior. The presence of either directory enables candidate discovery even when no candidate qualifies; skipped directories are not reported as absent ecosystems.

Each qualifying candidate is read as a separate dependency source. Its inventory and attempts are scoped by the Instance, sub-project identity (its repository-relative path, such as `apps/gateway`), and ecosystem. Resolution IDs are local to each source's graph. Collection does not combine manifests or rewrite locks into a workspace graph. The reader and manager-selection rules above apply unchanged within each candidate, including refusals for unsupported layouts and formats.

Discovery and file reads are batched for the Instance, not performed with a new SSH request per candidate. The existing collection and source-confirmation flow is retained; discovery and reads stay in the one agent command of the Instance. The scan remains read-only and runs no package manager, project script, or registry request.

### Limits and source safety

These limits apply to single-directory scans and monorepo collection alike.

| Limit | Value |
| --- | --- |
| A manifest or configuration file | 1 MiB |
| A lockfile | 8 MiB |
| All files | 32 MiB |
| One read | 120 seconds |

Symlinks, non-regular files, and paths that are not canonical fail. Production collection validates and pins the selected release before opening dependency directories. Monorepo discovery and candidate reads obey the same canonical-path, regular-file, symlink, per-file size, total-size, and time limits; discovery does not authorize reads outside the recorded checkout or pinned release. The agent reads twice and compares the repository or release identity, dependency directories, file metadata, and hashes. A difference returns `dependencies.source_changed`.

`ScanInstanceDependenciesAction` holds the Instance operation lock, and for development the Node source lock, from collection through publication. It checks the Instance again inside each publication transaction. Its source snapshot retains the effective root and Laravel classification, so directory selection does not fall back to the repository root when the Instance is copied into a snapshot.

An update inspects each ecosystem with `instance.probe-dependency-update` and runs the package manager with `instance.update-dependencies`, both as the managed account. Composer runs itself; npm, pnpm, and Bun run through a verified Vite+. The package manager runs in its own session with its output discarded.

Development updates do not iterate over discovered monorepo candidates: `instance:dependencies:update` stays repository-root-only for monorepos. Non-monorepo updates still use their existing dependency directory for manager-presence inspection, source-safety checks, package commands, and the final scan. Collection receipt validation accepts only the selected single dependency directory or discovered qualifying monorepo directories within the recorded checkout or selected release, not an arbitrary caller-supplied path. Git identity and CLI directory-to-Instance resolution still use the whole repository. Reader rules, package-manager refusals, and collection limits stay unchanged; neither a nested Laravel app nor monorepo discovery enables workspace or multi-importer support.

## Stored inventory

The Gateway stores package identities separately from Instance observations, resolutions, requirements, and scan attempts. For a monorepo, observations and attempts belong to one sub-project and ecosystem within the Instance, not to a shared graph for the repository. The root inventory keeps its existing single-directory scope. The `sub_project` column scopes observations and attempts; an empty string identifies the root. The Instance's `dependency_sub_projects` list records the last confirmed discovery, including an empty list when no candidate qualifies.

| Table | Contents |
| --- | --- |
| `dependency_packages` | One row per ecosystem and name, shared by all Instances. |
| `instance_dependency_observations` | The last successful observation per Instance, dependency source (root or monorepo sub-project), and ecosystem: time, presence, project root, source reference, format, and file hashes. No row means unknown. |
| `instance_dependency_resolutions` | The resolutions of one observation. |
| `instance_dependency_edges` | The requirements of one observation. |
| `instance_dependency_scan_attempts` | Every attempt for the Instance, dependency source, and ecosystem, with its time, error code, and safe error detail; both error fields are null on success. |

`PublishInstanceDependencyScanAction` replaces one dependency source's ecosystem observation, resolutions, edges, and attempt in one transaction. A failure records only that source's ecosystem attempt and keeps its stored observation. It does not replace a sibling sub-project's data or mark the sibling as failed. Each sub-project can resolve a different version of the same shared package identity. A database failure records `dependencies.persistence_failed`. An Instance in removal gets `dependencies.instance_unavailable`. Removing an Instance deletes its rows but keeps shared package rows. Rollback deletes each sub-project's observations and attempts before removing the scope columns. Root observations and attempt history remain unchanged.

## Responses

A scan or read keeps the root `data` fields `instance_id`, `succeeded`, `composer`, and `javascript` for existing clients. The root ecosystem fields keep the single-directory contract and are not a union of sub-project graphs. For a single-directory inventory, `succeeded` is null until both ecosystems have attempts.

For a monorepo, the API, SDK, and CLI (including `instance:dependencies:show`) also return a separate inventory for each qualifying sub-project, identified by its repository-relative path. The additive `sub_projects` map uses paths such as `apps/gateway` as keys. Each value has `instance_id`, `succeeded`, `composer`, and `javascript`, with its own states, attempts, error codes, snapshots, and graphs using the ecosystem shape below. An empty map is serialized as `[]`. This field is omitted for single-directory scans.

Discovery leaves root observations unchanged; root ecosystems without an observation stay unknown. With discovery, overall `succeeded` describes the discovered sources, not old root attempts, and is true when no candidate qualifies.

A failure keeps the last successful snapshot for that sub-project and ecosystem and marks it stale under the existing rules; an unscanned ecosystem stays unknown. Successful siblings still publish their observations. The overall scan is unsuccessful if any scanned sub-project ecosystem fails, but errors remain attached to the source that failed. This is an additive inventory contract, not a Project-level rollup.

Each ecosystem has `ecosystem`, `state`, `succeeded`, `attempted_at`, `error_code`, `error_detail`, and `snapshot`. `error_detail` is null on success and is limited to 255 printable ASCII characters. It uses fixed diagnostics and collected file names, not values from those files. The SDK also accepts older responses without this field.

The state is `unknown` before the first success, and stale after a later failure. A snapshot has `observed_at`, a `source` with `project_root`, `reference`, `file_hashes`, and `format`. `project_root` is the absolute directory whose manifests were read; for Laravel root `apps/site/public`, it ends in `/apps/site`. For a monorepo sub-project identified as `apps/gateway`, it ends in `/apps/gateway` within the checkout or selected release. The production `reference` still identifies the selected release. The snapshot also has a `graph`. A null graph means the ecosystem is absent. An empty graph means a project with no packages.

An update returns `instance_id`, `succeeded`, `error_code`, `may_have_mutated`, a `composer` and a `javascript` step, and `inventory`. A step has `ecosystem`, `status` (`succeeded`, `absent`, `failed`, or `not_run`), `may_have_mutated`, and `error_code`. A refused update has both steps `not_run` and no inventory.

| Update code | Cause |
| --- | --- |
| `dependencies.production_update_forbidden` | The target is a production Instance. |
| `dependencies.unsafe_source` | The recorded identity or layout is not safe to update. |
| `dependencies.unsupported_delegation` | Vite+ is missing or not a verified version. |
| `dependencies.update_failed` | A package command failed. |
| `dependencies.update_timeout` | The command passed its 600-second deadline. The agent then stops its process group. |

The SDK rejects a whole response that breaks the shape. It accepts at most 32 MiB of JSON, 50,000 resolutions and 200,000 requirements per ecosystem, 64 file hashes, and 16 KiB per text field. It checks collection bounds before scanning the JSON for duplicate object keys, so oversized inventories do not pay for a full token scan. Accepted responses still require unique keys, including equivalent escaped names.

## Target resolution

`GET /api/v1/instances/resolve?domain=DOMAIN` finds the one Instance behind an active Route domain. It returns `domain`, `instance_id`, `project_id`, `node_id`, and `environment`. A missing or inaccessible domain returns `dependencies.target_not_found`. A Route with more than one target returns `dependencies.target_ambiguous`. The SDK accepts at most 4,096 bytes of response.

`GET /api/v1/instances/resolve-directory?directory=PATH` finds the Instance whose checkout or production home holds a canonical path on the caller's Node. It returns `instance_id`, `project_id`, `node_id`, and `environment`. It never looks at other Nodes. Nested matches return `dependencies.target_ambiguous`; the resolver never picks the longest prefix.

Both return `dependencies.instance_unavailable` for an Instance that is not active or is in removal. Later operations check access and state again.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Opaque versions

A branch reference or alias is not a release version. Readers keep the locked value as it is and never solve constraints, so the graph shows what the lockfile says.

### Composer path packages from locked metadata

A path repository declaration is a source selection rule, not a reason to reject a complete Composer lockfile. Marking locked path packages through their opaque resolution IDs records local origin within the existing graph and response shape. Reading the referenced directory would expand the source observation and is unnecessary for a lockfile inventory. Unsupported JavaScript local layouts and real scan errors keep their failure behavior; no fleet failure is hidden.

### Hashed source references

Tarball and Git URLs can carry credentials or tokens. A digest keeps two sources apart without storing the URL.

### Separate monorepo dependency sources

Apps and packages in one checkout can resolve different versions of the same package. One repository-wide observation would replace or mix those results and hide which source failed. Scoping observations and attempts to the sub-project preserves both the graph and its last successful scan. Restricting discovery to direct children of `apps/` and `packages/` avoids treating every nested manifest as an independently managed project. The root response fields remain available for existing clients, without turning separate sources into one graph. Updates remain root-only because scanning more sources does not authorize package commands in those sources.

Scanning only the root misses independently locked trees. Failing every source when one lockfile is invalid prevents healthy siblings from refreshing. Recursive discovery, merged graphs, and automatic workspace or per-source update support are not substitutes for these source boundaries.

### Fail instead of an empty graph

An unsupported format that returned an empty graph would look like a project with no dependencies. So every unsupported case fails and keeps the last observation.
