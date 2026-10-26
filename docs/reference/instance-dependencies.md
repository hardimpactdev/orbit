---
title: "Instance dependencies"
description: "Record the resolved Composer and JavaScript dependencies of each Instance, and update a development Instance within its declared constraints."
covers:
  - apps/gateway/app/Actions/Instances/Dependencies/{ScanInstanceDependenciesAction,UpdateInstanceDependenciesAction,AccessInstanceDependenciesAction}.php
  - apps/gateway/app/Http/Controllers/Api/{InstanceDependenciesController,ResolveDependencyInstanceController,ResolveDirectoryInstanceController}.php
  - apps/cli/app/Commands/Dependencies/**
  - apps/cli/app/Services/DependencyInstanceSelector.php
---

# Instance dependencies

The Gateway keeps an inventory of the resolved dependencies of each Instance. It reads manifests and lockfiles, so the inventory works while dependencies are [pruned](/reference/app-dev-runtime-hibernation#dependency-prune). For a `monorepo` Project, the scan records a separate inventory for each qualifying sub-project under `apps/` and `packages/`. On a development Instance, Orbit can also update dependencies within their declared version constraints. [Dependency contracts](/reference/instance-dependency-contracts) holds the reader rules, stored tables, and response shapes.

## What the inventory holds

The inventory belongs to one Instance. Two Instances of a Project can resolve different versions.

Non-monorepo Instances keep their existing dependency directory selection. An Instance whose source is classified as Laravel, or whose Project type is `laravel-app` or `symfony-app`, reads its [application directory](/reference/projects#application-directory), not its web root. With root `apps/site/public`, a development scan reads `<checkout>/apps/site`, and a production scan reads `<home>/releases/<selected-release>/apps/site`. Root `public` keeps the checkout or release root. Other non-monorepo Instances read the repository root. Each non-monorepo scan still reads one directory.

A non-monorepo scan reads only that directory; it does not combine manifests from the repository root or sibling apps. Each inventory's `source.project_root` names the directory actually read. When the CLI selects by directory, it identifies the Instance, not an arbitrary dependency tree within it. Orbit reads only these files:

| Ecosystem | Files | Supported format |
| --- | --- | --- |
| Composer | `composer.json`, `composer.lock` | Composer 1 and 2 locks. |
| npm | `package.json`, `package-lock.json` or `npm-shrinkwrap.json` | `lockfileVersion` 2 and 3. Shrinkwrap wins. |
| pnpm | `package.json`, `pnpm-lock.yaml` | `lockfileVersion` 9.0 with one root importer. |
| Bun | `package.json`, `bun.lock` | Text lock, `lockfileVersion` 1 or 2. |

npm, pnpm, and Bun all record packages in the `npm` ecosystem. Yarn, binary `bun.lockb`, workspace or multi-importer layouts, and local `file:` or `link:` packages are unsupported and fail the scan.

For each package, the inventory records every resolved version and how the root reaches it: direct or transitive, regular or development, and peer requirements. It records what the lockfile says. It does not prove that packages are installed or safe.

A scan reads files only. It runs no package manager, project script, or registry request. A failed scan includes a short `error_detail` beside its stable error code. The detail names a file or failed check without showing file contents or secrets. `instance:dependencies:show`, scan responses, and CLI scan output retain that detail with the latest attempt. A declared JavaScript manager takes precedence over stray lockfiles; conflicting declarations or undeclared competing managers still fail. See [Dependency contracts](/reference/instance-dependency-contracts#package-manager-selection) for the selection rules.

### Monorepo sub-projects

For an Instance whose Project type is `monorepo`, Orbit looks only in the repository's top-level `apps/` and `packages/` directories. Each direct child directory, such as `apps/gateway` or `packages/php-sdk`, is a candidate. Orbit does not search deeper directories or other top-level directories.

A candidate is a web project when it contains either:

- `composer.json`, whether or not `composer.lock` is present.
- `package.json` together with at least one supported lockfile name: `package-lock.json`, `npm-shrinkwrap.json`, `pnpm-lock.yaml`, or `bun.lock`.

Orbit skips other candidates and records no inventory or scan attempt for them. A directory with only `package.json`, or with only a Yarn lockfile or binary `bun.lockb`, does not qualify through the JavaScript test. Qualification is not validation: a qualifying project with a missing Composer lockfile, an unsupported lockfile version, or another reader error still gets its own failed attempt. A Composer project also has its JavaScript ecosystem checked under the existing rules.

Each qualifying sub-project has its own inventory, identified by its repository-relative path, such as `apps/gateway`. Two sub-projects can resolve different versions of the same package without replacing each other's observations. Errors and last successful observations also belong to that sub-project and ecosystem. A failure in one sub-project does not stop or fail scans of the others.

If neither `apps/` nor `packages/` exists, the monorepo scan keeps the existing single-directory behavior. If either directory exists but no candidate qualifies, skipped directories do not become absent or empty inventories. Non-monorepo types do not use this discovery rule. Finding sub-projects does not enable workspace, multi-importer, or local-link support in the readers.

`instance:dependencies:show`, scan output, the CLI, and the API expose these separate inventories and their errors. Existing root fields remain available for clients using the single-directory contract; they are not a merged graph of the sub-projects. See [the collection and response contracts](/reference/instance-dependency-contracts#collection) and [the source-scoping rationale](/reference/instance-dependency-contracts#separate-monorepo-dependency-sources).

## Scan

Select one Instance by the current directory or by its full Route domain, or scan every Instance you can reach.

```bash
orbit instance:dependencies:scan
orbit instance:dependencies:scan --project=orbit.test
orbit instance:dependencies:scan --all
```

| Selector | Target |
| --- | --- |
| none | The registered Instance whose checkout or production home holds the current directory, on the caller's Node. |
| `--project=DOMAIN` | The one Instance behind that Route domain. It takes precedence over the directory. |
| `--all` | Every Instance that `instance:list` returns, scanned one by one in list order. |

No match or more than one match fails before any work, with `dependencies.target_not_found` or `dependencies.target_ambiguous`. `--all` with `--project` returns `dependencies.target_conflict`. An `--all` scan continues after a failed Instance, and Ctrl-C marks the rest as skipped.

Human output shows each ecosystem's state, counts, and times, separately for each monorepo sub-project. An unknown count shows as an em dash, and verified absence shows zero. `--json` returns the typed inventory, or for `--all`, a document with `succeeded`, `summary`, `instances`, and `request_id`. The exit status is zero only when every scanned ecosystem succeeds, including those in each qualifying monorepo sub-project. A sub-project failure makes the overall scan unsuccessful without marking successful siblings as failed.

## Refresh rules

Each ecosystem keeps its last successful observation and its latest attempt, separately. For monorepos, this rule applies separately within each qualifying sub-project.

| Condition | Result |
| --- | --- |
| Manifest and lockfile are valid | Replace the observation. |
| Both files are missing | Record that the ecosystem is absent. |
| One of the two files is missing | Fail with `dependencies.incomplete_source`. |
| The lockfile root differs from `package.json` | Fail with `dependencies.stale_npm_lockfile`, `dependencies.stale_pnpm_lockfile`, or `dependencies.stale_bun_lockfile`. Human output adds a fix, such as `Run bun install in the dependency directory and commit bun.lock.` |
| The format or layout is unsupported, or a file is invalid | Fail with the matching code. |
| A production Instance has no selected release | Fail with `dependencies.source_unavailable`. |
| The source changes during the scan | Fail both ecosystems with `dependencies.source_changed`. Retry. |

A failure keeps the last observation and marks it stale. An Instance that was never scanned has unknown inventory. One ecosystem can succeed while the other fails.

Scans share the Instance's operation lock with updates, deployments, rollbacks, environment changes, and removal. A busy Instance returns `dependencies.operation_busy`. Removing an Instance removes its inventory.

## Update a development Instance

Update one development Instance with the same selectors, except `--all`:

```bash
orbit instance:dependencies:update
orbit instance:dependencies:update --project=orbit.test
```

The update command does not iterate over monorepo sub-projects. For a monorepo it remains repository-root-only; it does not update manifests under `apps/` or `packages/`. Per-sub-project updates are out of scope.

For non-monorepo types, the Gateway runs these steps as the Node's user in the same dependency directory used by scans. For a Laravel root of `apps/site/public`, that is `<checkout>/apps/site`; presence checks, Composer, Vite+, and the final scan all use that directory. These Laravel updates do not update repository-root or sibling manifests. Unlike dependency updates, repository-owned setup, deploy, and task-check commands keep their [repository-root scope](/reference/projects#application-directory):

1. It inspects both ecosystems. A refusal stops the whole update before any package command. See the refusals below.
2. It runs `composer update --no-interaction --no-ansi --no-progress --no-audit` for a Composer project. Regular and development packages move within their constraints.
3. It runs `vp update --no-save` for a JavaScript project. Vite+ picks npm, pnpm, or Bun. For Bun, Orbit adds `-- --lockfile-only --save-text-lockfile`.
4. It scans the result, also after a failed or cancelled step.

The inspection refuses a Yarn project, an unsupported layout, two package managers, and an unverified Vite+.

The Gateway selects the ecosystem and enforces development-only updates. Typed [Node agent commands](/reference/node-agent#commands) inspect the source, run the constrained package update as its owner, and return the result. Each package step has a 600-second deadline and owns its process group. A failed step stops the steps after it. Orbit claims no rollback. The update succeeds only when every package step and the final scan succeed.

Orbit uses the first Vite+ it finds at `/opt/orbit/vite-plus/bin/vp`, `~/.vite-plus/bin/vp`, `~/.vite-plus/current/bin/vp`, `~/.local/share/vite-plus/bin/vp`, or `~/.local/share/vite-plus/current/bin/vp`. It also accepts the launcher `/usr/local/bin/vp`. Only Vite+ 0.3.0 is verified. Another version fails with `dependencies.unsupported_delegation`.

A production Instance returns `dependencies.production_update_forbidden` before any command. `--all` and `--latest` are refused before any request. Declared constraints never change: an update that needs a new constraint is an upgrade, which Orbit does not do.

To bring an update to production, test it, commit the manifests and lockfiles, and push them to the production deployment branch. Then [deploy](/reference/deployments) with a step that installs from the lockfiles.

## API

The CLI uses these Gateway routes. Each needs an [access grant](/cli/node) to the Instance's Node.

| Route | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/dependencies` | The stored inventory, without SSH. |
| `POST /api/v1/instances/{instance}/dependencies/scan` | Scan both ecosystems. |
| `POST /api/v1/instances/{instance}/dependencies/update` | Update a development Instance. |
| `GET /api/v1/instances/resolve?domain=DOMAIN` | Find the Instance behind a Route domain. |
| `GET /api/v1/instances/resolve-directory?directory=PATH` | Find the Instance that holds a directory on the caller's Node. |

`POST` takes an empty JSON object. HTTP 200 means the operation ran, not that it succeeded. Check `succeeded`, each step's `status`, and each ecosystem's state.

## Nightly scan

Create one Node [Schedule](/reference/schedules) on the Gateway host. Each run finds the current set of Instances, so you need no Schedule per Instance.

```bash
orbit schedule:create dependency-nightly-scan \
  --node=GATEWAY_NODE_ID \
  --calendar='*-*-* 03:15:00 Europe/Amsterdam' \
  --command='orbit instance:dependencies:scan --all --json --no-interaction' \
  --timeout=7200
```

Put the timezone in the calendar expression. Size the timeout for the fleet. A failed Instance makes the run fail, and the Schedule logs keep the JSON result. A nightly scan checks no advisories and updates no packages.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Inventory per Instance

Instances of one Project can run different source. One version per Project or per package identity was rejected. Shared package identities still allow fleet-wide queries.

### Lockfiles, not installed files

Hibernation deletes `vendor` and `node_modules` on idle Instances. Lockfiles stay, so the inventory reads them.

### No updates in production

Resolving versions in production skips the tested source and the release flow. So updates run only in development, and production installs the committed lockfiles through a deployment.

### No Yarn

Orbit supports npm, pnpm, and Bun, the managers that Vite+ drives with a verified constrained update. Yarn has no such update, so a Yarn project fails clearly. It never becomes an empty inventory or another manager's result.

### Nightly scans as well as updates

Dependencies also change outside Orbit. A scan after each Orbit update would miss those changes, so one Schedule scans the fleet every night.
