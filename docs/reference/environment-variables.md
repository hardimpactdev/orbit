---
title: "Instance environment variables"
description: "How the Gateway imports, stores, updates, and synchronizes an Instance .env file without exposing its values."
covers:
  - apps/gateway/app/Domain/Instances/Environment/**
  - apps/gateway/app/Actions/*/{Start,Update,Synchronize}*Environment*Action.php
  - apps/gateway/app/Http/Controllers/Api/InstanceEnvironment*Controller.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteInstanceEnvironmentAccess,NativeInstanceEnvironmentOperationLock}.php
  - apps/gateway/{app/Models/{Instance,InstanceEnvironmentValue}.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/cli/app/Commands/Environment/**
  - packages/php-sdk/src/{Requests,Responses}/Environment/**
  - swamp/{workflows/workflow-operation-instance-env-import.yaml,extensions/behaviors/operations/instance-env-import/**}
---

# Instance environment variables

Orbit owns the environment configuration of every Instance. The Gateway stores each key and value encrypted and writes the Instance's `.env` file only when you synchronize or deploy. Values never appear in API responses, Activity entries, errors, or logs.

The common workflow is to import an existing `.env` file, update individual keys, and synchronize the changes to the Node. See [`env`](/cli/env) for CLI flags and [Gateway environment](/reference/gateway-environment) for the Gateway's own `.env` settings.

```bash
orbit env:import --instance=42
orbit env:update --instance=42 --key=CACHE_STORE --value=redis
orbit env:sync --instance=42
```

## Operations

All three environment endpoints accept either a positive numeric Instance ID or an exact Route domain in `{instance}`. A selector that matches no Instance returns HTTP 404, and a Route domain shared by multiple Instances returns HTTP 409 with `env.target_ambiguous`. Orbit resolves the target Node from the Instance's recorded `node_id`, so an Instance without a Route can still synchronize unless a stored value uses `{{instance.domain}}`.

| Operation | Request | Body | Effect |
| --- | --- | --- | --- |
| Import | `POST /api/v1/instances/{instance}/environment/import` | `{}` or `{"replace": true}` | Reads the Instance's `.env` into stored configuration. |
| Update | `PUT /api/v1/instances/{instance}/environment/{key}` | `{"value": "..."}` | Adds or replaces one stored value. |
| Synchronize | `POST /api/v1/instances/{instance}/environment/sync` | `{}` or `{"force": true}` | Writes stored configuration to the Instance's `.env`. |

Import and update change stored configuration only; the `.env` file on the Node stays unchanged until you synchronize. Unknown, duplicate, or wrongly typed JSON fields fail with HTTP 422 before changing anything.

Update completes synchronously and returns `instance_id`, `operation`, `changed`, and `key_count`. Because update does not contact the Node, it works even while the Node is unreachable.

Import and synchronization require an active Node and return HTTP 202 with an asynchronous [Operation](/reference/operations). An import Operation finishes with `instance_id`, `operation`, `changed`, and `key_count`. A synchronization Operation finishes with `instance_id`, `changed`, `environment_changed`, `key_count`, the synchronized key names, testing status, and runtime refresh status.

Every environment operation requires an access grant to the Instance's owning Node, an active Instance with complete placement, and exactly one active `app-dev` or `app-prod` role on that Node (`instance.placement_unavailable`, 409 otherwise). Changing a Project's [task workspace routing setting](/reference/projects#task-workspace-routing) does not change an existing Instance's environment target.

## Where the file lives

Orbit derives the `.env` file path and Unix user from the Instance's placement; callers cannot choose the Node, user, directory, or file name.

| Placement | File | User |
| --- | --- | --- |
| `app-dev` container | `.env` in the workspace's application directory | `orbit` (group `work`) |
| `app-dev` checkout | `.env` in the checkout's application directory | The Node's managed user |
| `app-prod` release container | `.env` inside the serving release container's application directory | `orbit` |
| `app-prod` legacy host home | `.env` in the production home, linked from each release | The Instance's production user |

For Laravel Projects, the [application directory](/reference/projects#application-directory) is the effective web root without its trailing `/public`. With root `apps/site/public`, development uses `<checkout>/apps/site/.env` (with `.env.testing` beside it), and a legacy production home links `<home>/releases/<name>/apps/site/.env` to `<home>/.env`.

On an `app-prod` release container, import and synchronization target the currently serving container and verify its exact commit on the Node before applying changes; a release switch requires a new operation. Values that name a host-only path (such as a binary under `/opt/orbit/`) are mapped to their exact container equivalent or refused with `deployment.host_path_unsupported`; see [Host paths](/reference/production-containers#host-paths). A SQLite `DB_DATABASE` must be empty or use the managed path (`/srv/shared/database.sqlite` or the legacy home path) or synchronization refuses with `deployment.sqlite_location_unsupported` (409).

On an `app-dev` container Instance, `env:import` refuses with `operation.kind_unavailable`. `env:sync` never re-renders the whole `.env` file; it updates only attached database/service connection keys and keys stored with `env:update` since the last sync, leaving all other lines intact. A container clone inherits its default's `.env` from the seed snapshot, keeps keys of [shared connections](/reference/database-connections#share-a-connection-with-clones), and rewrites values pointing at the default's Route host to its own; see [The environment of a clone](/reference/development-containers#the-environment-of-a-clone) and [`env:sync` on a container Instance](/cli/env/sync#on-a-container-instance).

An Instance's [Vite+ runtime environment](/reference/tools#project-environments-in-the-vite-store) (`vp_environment`), [monorepo dependency scans](/reference/instance-dependency-contracts#separate-monorepo-dependency-sources), and [shared paths](/reference/deployments#shared-paths) are independent of `.env` and never import, synchronize, or rewrite it.

## Import

Import reads the Instance's live `.env` file (never `.env.example`), verifies the file type, owner, size (at most 1 MiB), and SHA-256 digest, and stores the parsed keys and values encrypted on the Gateway. A missing, symlinked, wrong-owner, or oversized file fails the Operation with `env.import_source_missing`. A size or digest mismatch fails with `env.import_unconfirmed` and stores nothing. See the [`env:import` chart](/cli/env/import).

The parser accepts blank lines, comments, quoted and escaped values, multiline quoted values, and expansion of keys defined in the same file. It does not read the Gateway's own environment. A duplicate key, unresolved expansion, syntax error, or file over 1 MiB rejects the entire import. Orbit stores keys and values only, without comments or formatting.

Without `replace`, any file key that is already stored fails the Operation with `env.import_conflict` and changes nothing. With `{"replace": true}`, matching keys take the file's value, new keys are added, and stored keys absent from the file remain stored.

For a Laravel Instance, import stores `APP_URL` as `https://{{instance.domain}}` so it follows the Route. A non-empty `APP_KEY` is kept as is. When the file contains an empty `APP_KEY`, import reuses the Instance's existing non-empty stored key, or generates a cryptographically random 32-byte `base64:` key if none is stored. A missing `APP_KEY` stays missing, and imports for other Project types never generate keys.

## Update

Update stores a single string value for one key. `""`, `"false"`, and `"0"` are stored as distinct strings. For a Laravel Instance, `APP_URL` accepts only `https://{{instance.domain}}`.

On an `app-dev` container Instance, update records the key as pending so the next `env:sync` writes it in place. Calling update while an `env:sync` is already running on that container refuses with `instance.lifecycle_busy` (409).

## Limits

Orbit validates the complete configuration before saving any part of an import or update. A violation returns `env.configuration_invalid` (HTTP 422 for update, or on the Operation for import). Error details name the key, failed rule, and any invalid placeholder token, never a value.

| Item | Limit |
| --- | --- |
| Key | 1 to 255 characters. Starts with `A-Z`, `a-z`, or `_`, followed by `A-Z`, `a-z`, `0-9`, or `_`. |
| Value | Valid UTF-8, at most 65,536 bytes, without NUL bytes. |
| Keys | At most 1,024 keys per Instance. |
| File | At most 1 MiB after rendering. |
| Placeholders | `{{instance.domain}}` and `{{instance.environment}}`, alone or inside a longer value. Any other `{{...}}` fails. |

## Synchronize

Synchronization takes a consistent snapshot of the Instance, its authoritative Route (when present), and its complete stored configuration. It resolves `{{instance.domain}}` to the Route's domain and `{{instance.environment}}` to `development` on `app-dev` or `production` on `app-prod`. A Route is required only when a stored value uses `{{instance.domain}}`; a missing Route, incomplete Route transition, or leftover `{{`/`}}` stops synchronization with `env.reference_unavailable` (409) before changing any file.

Orbit renders every stored key in sorted order with double-quoted values, preserving literal whitespace, newlines, quotes, dollar signs, backslashes, and empty strings. The file is written as the Instance user with mode `0640` and group `orbit-env` (allowing the Node agent to observe key names without reading values), then verified by SHA-256. Stored configuration is the sole source: manual edits to stored keys are overwritten. An identical file stays untouched and reports `changed: false`.

Only one operation can hold an Instance at a time; a competing request returns `operation.target_busy` (409). If stored values change between admission and execution, the Operation fails with `env.configuration_changed`. The owned-key list updates only after synchronization succeeds.

### Unmanaged keys and `--force`

Before replacing `.env`, Orbit checks whether the live file contains keys that are neither in stored configuration nor in Orbit's last written key set (or keys removed by a detach since the last sync).

- On host checkouts and legacy production homes, admission reads `env_keys` from the [Node agent](/reference/node-agent#environment-key-names). Unmanaged keys refuse with `env.sync_would_drop_keys` (409), and unobserved files refuse with `env.keys_not_observed` (409).
- On production release containers, the sync checks the actual live file inside the serving container before writing.
- Pass `--force` (`{"force": true}`) to skip both checks and replace `.env`. On a host checkout without an agent report, a forced sync skips `.env.testing` and reports `skipped_unobserved`.

To preserve unmanaged keys without losing them, store them with `env:update` or import them with `env:import --replace` before synchronizing. A Route transition on a production Instance skips the unmanaged-key check because deploys already own that file.

### Testing environment (`.env.testing`)

When an Instance owns its MySQL or PostgreSQL `DB` database, synchronization also renders and writes `.env.testing` in the same step and mode. Because Laravel loads `.env.testing` instead of `.env` when `APP_ENV=testing`, Orbit writes every key from `.env` with `APP_ENV=testing` and the [test database](/reference/database-connections#test-databases) `DB_*` keys.

Orbit never merges into `.env.testing` and never deletes it (even after the Instance stops owning its `DB` database). When Git tracks `.env.testing`, Orbit leaves the file untouched. The Operation result records the test database name and `testing_status`: `written`, `unchanged`, `skipped_tracked`, or `skipped_unobserved`.

### Production runtime refresh

Development synchronization writes files only. Production synchronization refreshes the runtime on every request, even when `.env` is unchanged:

1. If the current release contains `bootstrap/cache/config.php`, Orbit runs `php artisan config:cache` as the Instance user. Uncached applications stay uncached, and a missing current release skips this step.
2. Orbit reloads the Instance's PHP runtime (resetting OPcache).
3. Orbit restarts active Instance Processes whose desired state is `running` and whose `restart_on_deploy` setting is `true`.

If any refresh step fails, the Operation fails with `env.runtime_refresh_failed` and the written `.env` files remain in place.

### Database and Node service endpoints

Synchronization preserves each attached database's explicit network choice (WireGuard by default, or [LAN](/reference/database-connections#choose-lan-for-an-instance) when configured). Transfer and clone reattachment reset the choice to WireGuard, while a container clone's copy of a shared attachment keeps its default's choice.

[Node service target records](/reference/processes-and-schedules#node-service-target-records) reserve explicit host and port key names per Instance without modifying stored values or `.env`. During container create and migration, attached local Node services are reached at their `.env` host and port via a `127.0.0.1:<port>` proxy. Remote Docker Node Process targets use their private WireGuard address and published TCP port, adding only that host to the container's managed `NO_PROXY`/`no_proxy` (removed on detach). [Migration](/cli/instance/migrate) preflight admits a selected Node service on loopback (`127.0.0.1` or `localhost`) or a Node address only when its endpoint matches an attached target (including the exact recorded host key, port key, and published port for loopback).

## Laravel mode

`APP_ENV` and `APP_DEBUG` are ordinary stored keys. The Node role (`app-dev` or `app-prod`), not these keys, determines isolation, Unix user, release layout, and PHP runtime. When `APP_ENV` is absent or uses `{{instance.environment}}`, Orbit treats it as `development` on `app-dev` and `production` on `app-prod`.

A [clone](/reference/instance-cloning) onto `app-prod` copies the candidate's stored configuration except `DB_*` keys and [Reverb app](/reference/websocket-apps#add-an-app-on-an-instance) keys, then sets `APP_ENV=production` and `APP_DEBUG=false` (or `APP_ENV=prod` and `APP_DEBUG=0` for `symfony-app`). You can update both afterward. A transfer keeps every stored key.

When a production clone seeds a SQLite snapshot, Orbit registers an owned SQLite connection on the target Node, attaches it under `DB`, and synchronizes `DB_CONNECTION=sqlite` and `DB_DATABASE=<production_home>/database.sqlite` into `.env`. While the clone runs, its own operation writes `.env` with `APP_URL` from the pending preview Route.

## Other writers

Several Orbit operations update stored environment keys or write `.env` as part of their workflow:

- **Database and WebSocket attachments:** [`instance:database:add` / `remove`](/reference/database-connections#add-a-connection-on-an-instance) and [`instance:websocket:add` / `remove`](/reference/websocket-apps#add-an-app-on-an-instance) update stored keys for one prefix without writing `.env` directly.
- **Instance creation:** Seeds encrypted values from the Project template, sets `APP_URL`, replaces owned `DB_*` and [clone Valkey](/reference/development-containers#a-clones-valkey-user) keys, and renders `.env.testing`.
- **Template `${KEY}` references:** Development creates leave captured references like `VITE_APP_NAME="${APP_NAME}"` unescaped for dotenv expansion; mixed or uncaptured references stay escaped.
- **Deployments:** A production [deployment](/reference/deployments) synchronizes stored configuration before running deploy steps. A development default deploys in place and does not run synchronization.
- **Domain changes and renames:** Production Route domain changes rewrite `.env` against the new Route (restoring on pre-cutover failure), and [web-root Routes](/reference/routes#serve-several-web-roots) update `APP_URL` in their own `.env`.
- **Project slug changes:** Before cutover, Orbit synchronizes `.env` for development Instances with stored config (skipping others with `env.configuration_missing`) and updates `APP_URL`. See [Update lifecycle](/reference/projects#update-lifecycle).

Note that a `VITE_` key referencing a secret exposes that secret to the browser, and PHP keeps undefined `${KEY}` literal while Vite expands it to an empty string. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not read `.env` files or framework caches; synchronization and deployment own configuration validation.

## Development mail

For an eligible new development container, the renderer projects a parsed `MAIL_MAILER=log` value (unquoted, single-quoted, or double-quoted) to `MAIL_MAILER="smtp"` in the written file while keeping the stored value unchanged. Existing files use [`instance:mail:update`](/cli/instance/mail-update), which matches `MAIL_MAILER=log` exactly, preserves quote style, and changes only `log` to `smtp`; see [Development mail](/reference/development-containers#development-mail).

## Storage and recovery

The Gateway encrypts every stored value, including placeholders, using its own `APP_KEY`. Restoring a Gateway backup requires that key.

Orbit generates a Laravel `APP_KEY` only during create or when import sees an empty `APP_KEY` with no non-empty stored key. Synchronization never generates, rotates, or deletes a stored `APP_KEY`. See [Laravel application URL](/domains/applications#laravel-application-url).

## Gateway and tooling settings

The Gateway's own `.env` file and local contributor tooling variables are completely separate from Instance environment variables; never set them in an Instance's `.env`. See [Gateway environment](/reference/gateway-environment) for the full Gateway variable reference, and each feature page for feature-specific Gateway settings ([Tasks](/reference/tasks#configuration), [Gateway queue](/reference/gateway-queue), [Gateway recovery](/reference/gateway-recovery), [Self-update](/reference/self-update#how-the-gateway-resolves-it), [Proof run](/cli/proof/run#the-execution-switch), [Project Documents](/reference/project-documents#restore-time-cleanup-gate), [Browser access](/reference/browser-access#web-app-development-server), and [Contributor guide](/contributor-guide#checks-that-need-no-network)).

For Gateway self-releases, `ORBIT_GATEWAY_RELEASES_KEEP` defaults to `3` (minimum `2`), always retaining the current release, previous release, and the build served by `web/current`. Each release build requires 1 GiB plus a copy of the database above a floor of 2 GiB and 10% free disk, retains the two newest [database snapshots](/reference/gateway-recovery#migrations-and-the-snapshot) (plus any paused snapshot), and verifies `/up` and Gateway status at `https://gateway.orbit` using `/etc/caddy/orbit-cert-current/root-ca.pem`.

## Errors

Update and the admission of import or synchronization return these codes with the HTTP status shown. Asynchronous failures record their code in the Operation's `error_code` field (`Operation` below). Error responses never include environment values.

| Code | HTTP | Cause |
| --- | --- | --- |
| `env.target_ambiguous` | 409 | The Route domain targets more than one Instance. |
| `env.owner_unavailable` | 409 | The Instance is not active or fully placed, or lacks a single healthy Route when required. |
| `instance.placement_unavailable` | 409 | The owning Node does not have exactly one active `app-dev` or `app-prod` role. |
| `instance.lifecycle_busy` | 409 | A container `env:sync` is running; retry `env:update` after it finishes. |
| `env.configuration_invalid` | 422 | A key, value, placeholder, count, size, or Laravel `APP_URL` rule failed (recorded on the Operation for import). |
| `env.configuration_missing` | 409 | Synchronization found no stored configuration. Import or store values first. |
| `env.reference_unavailable` | 409 | A placeholder remained unresolved after rendering, or a required Route is missing or transitioning. |
| `env.sync_would_drop_keys` | 409 | The live file has unmanaged keys (`details.keys` names them). Import them or pass `--force`. |
| `env.keys_not_observed` | 409 | The Node agent has not observed the file's key names. Run `env:import` then `env:sync --force` (or `--force` alone for a missing file). |
| `operation.target_busy` | 409 | Another operation holds the Instance. |
| `operation.kind_unavailable` | 409 | The operation is not supported for this Instance (such as `env:import` on a container Instance). |
| `deployment.selection_unconfirmed` | 409 | The Instance's serving production container release is not yet confirmed. |
| `deployment.host_path_unsupported` | 409 | A value on a production container names a host-only path with no container equivalent. |
| `deployment.sqlite_location_unsupported` | 409 | `DB_DATABASE` on a production container points outside the Instance's managed SQLite path. |
| `env.import_conflict` | Operation | Import without `replace` found a key that is already stored. |
| `env.import_source_missing` | Operation | The `.env` file is missing, a symlink, owned by another user, or over 1 MiB. |
| `env.import_unconfirmed` | Operation | The transferred file did not match the reported size and SHA-256. Nothing was stored. |
| `env.configuration_changed` | Operation | Stored values changed between admission and execution. Repeat synchronization. |
| `env.sync_unconfirmed` | Operation | The written file could not be confirmed or its SHA-256 differed from the rendered file. Repeat synchronization. |
| `env.runtime_refresh_failed` | Operation | Config caching, PHP reload, or Process restart failed. The written `.env` files remain in place. |
| `env.testing_tracking_unknown` | Operation | Git could not report whether `.env.testing` is tracked. `.env` was written; `.env.testing` stayed unchanged. |

## Why it works this way

These reasons explain the design. Check them before proposing a change.

### The Gateway holds the configuration

A `.env` file on a Node holds values tied to one placement, such as its domain and paths, so another placement cannot rebuild it from the Node alone. Storing the configuration on the Gateway lets synchronization render it accurately for any destination. Treating the Node's file as the source of truth is a rejected alternative.

### Empty Laravel keys are initialized before synchronization

A fresh Laravel checkout often has an empty `APP_KEY`. Storing an empty value would cause synchronization to erase a key generated on the Node and break the app after a deploy or clone. Import therefore initializes an empty key before synchronization and reuses any existing stored key to avoid invalidating sessions or encrypted data. It never runs application code or uses the Gateway's own key; applications using a non-default cipher must supply their own compatible key.

### Every value is encrypted

Orbit does not try to classify values as secret or non-secret, because a single missed classification would store a credential in plain text. Encrypting every value removes that risk.

### Synchronization never merges local edits

Pulling ad-hoc edits from a Node during a write would overwrite stored intent with unrequested changes. Synchronization is strictly one-way (Gateway to Node), and import is the explicit operation for reading a file back.

### Synchronization runs no framework

Development synchronization must work before dependencies are installed, so it only writes files. Production PHP disables OPcache timestamp validation, so writing `.env` alone cannot activate a cached Laravel config. Production synchronization therefore rebuilds any existing config cache before reloading PHP and restarting opted-in Processes.

### The Node role decides isolation

An operator may set `APP_ENV` to `local` or `staging` on a production Instance. Letting `APP_ENV` control isolation or the Unix user would disable production safeguards whenever that value changed. The Node role therefore owns isolation and layout, and `APP_ENV` stays an application setting.
