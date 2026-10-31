---
title: "Database connections"
description: "The Gateway-owned registry of mysql, pgsql, sqlite, and redis connections, the databases that Instances own, their test databases, and how an operator attaches a connection to an Instance."
covers:
  - apps/gateway/app/Actions/DatabaseConnections/**
  - apps/gateway/app/Domain/DatabaseConnections/**
  - apps/gateway/app/Infrastructure/DatabaseConnections/**
  - apps/gateway/app/Http/Controllers/Api/{DatabaseConnectionsController,DatabaseConnectionAttachmentsController}.php
  - apps/gateway/app/Http/Requests/DatabaseConnections/**
  - apps/gateway/{app/Models/{DatabaseConnection,DatabaseConnectionTarget}.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/gateway/app/Domain/Instances/DatabaseClone/**
---

# Database connections

A Database connection is a Gateway record that describes one database: its driver, where it is, and how to log in. The Gateway stores the password encrypted. You can register an existing database, create one on a [Database server](/reference/database-servers), inspect it, and attach it to an Instance. The registry never starts or stops a database. [`database`](/cli/database) lists the commands.

## Execution

Database writes that change a Node return one [Operation](/reference/operations) with HTTP 202. The Gateway validates ownership and records intent. `StartSwampRun` starts one native DAG. Swamp runs the system commands on each admitted Node with that Node's configured user and sudo. The agent observes health and drift; it does not execute Database actions. The CLI follows the Operation; use `--idempotency-key` after a lost start response.

Each direct declaration names a workflow and a small result projection. There are no executor claims, child Operations, step receipts or polled Database schedules. An unavailable native kind returns `operation.kind_unavailable`.

Passwords and SQL use the shared private-material API for the exact root run and admitted Node custody. They remain inside the effect callback. They do not enter Operation public input, results, native JSON resources or logs. The Gateway deletes encrypted intent after the Operation and its 24-hour retry window finish. Public Operation history remains.

Create reserves names and credentials without probing a server. A matching retry reuses them. Swamp checks the live owner and grants before creating or adopting existing names. A MySQL exact grant escapes underscores. Destruction refuses system names and prefixes, attached connections and foreign owners. PostgreSQL destruction fences admission and restores it after a refused or failed drop. Create and user admission refuse while removal targets the same server; removal refuses while creation is unfinished.

## Instance database steps

Lifecycle composes Database actions as placed jobs in its own native DAG. Grouped removal records one aggregate database intent for the admitted Instance set, with one entry per SQL server. Each server Node receives only its own passwords through the fixed private-material binding. It does not admit Database child Operations or wait in the Gateway. Managed MySQL clone resets and copies in one replayable native step. Swamp verifies the target's recorded owner and grants before reset. SQLite clone, seed and abandon use safe immutable source and target descriptors and exact Node placement.

A SQLite source writes a private snapshot into a native file artifact. The target reads that exact version and verifies the size and digest before installation. Database bytes stay out of JSON resources and public history. Native retention owns the file. The current remote native reader buffers a snapshot in worker memory, so this path does not claim bounded memory for large files. Cleanup runs as native dependencies.

Clone steps refresh admitted cancellation before effects. Cancellation after export still cleans its source snapshot. Final projection waits for cleanup and uses the saved copy outcome; missing native outcomes record failure without attaching the target. Abandon derives only the owning recorded development-transfer attempt.

Successful projections make bounded record changes: attach a created or cloned connection, mark its clone complete, or remove the admitted connection records. They do not re-run SQL, inspect a Node, check receipts or validate native execution.

## Drivers and fields

Each record has a unique slug: lowercase words joined by hyphens, at most 63 characters. Each driver takes its own fields.

| Driver | Required | Optional | Default port |
| --- | --- | --- | --- |
| `mysql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `3306` |
| `pgsql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `5432` |
| `sqlite` | `path` | `node_id`, `username`, `password` | none |
| `redis` | `host` | `node_id`, `port`, `database`, `username`, `password` | `6379` |

`host` is a hostname or an IP address, without a user or a port. `port` is 1 through 65535. `path` is an absolute Unix path of at most 1,024 characters. A redis `database` is the numeric database index. The Gateway returns `validation.failed` for a field that the driver does not take, such as `path` on mysql or `host` on sqlite.

A redis `username` names an ACL user on the Redis server. Without it, the Gateway and the Processes that use the connection log in as the `default` user. One connection holds one username and one password for every client that uses it. To give one client its own ACL user, create a second connection on a [Valkey Database server](/reference/database-servers#create-an-acl-user-on-a-valkey-server). Do not change the shared connection, because the Processes that [follow it](/reference/ai-router#follow-the-cache-connection) move to the new user too. After setup, the [Gateway queue](/reference/gateway-queue#acl-users) uses `gateway-queue` this way.

`node_id` links the record to a Node. The Node needs no `database` role. SQLite inspection needs this link, and it lets an Instance on the same Node reach a Docker Process locally.

Responses contain `id`, `slug`, `driver`, `node_id`, `host`, `port`, `database`, `path`, `username`, `has_password`, `server`, `owner_instance_id`, and `test_database`. Show also returns `users_count`. `server` is the slug of the [Database server](/reference/database-servers) that holds the database, or null. `owner_instance_id` names the Instance that owns the database, or null. No response, Activity entry, or error contains a password. To give people the credentials, the Gateway can [publish MySQL and PostgreSQL connections to Vaultwarden](/reference/vaultwarden).

## API

The registry routes need an access grant to the Gateway Node. A duplicate slug returns `database.slug_conflict` (409), and an unknown slug returns 404.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/database-connections` | List the records, ordered by slug. |
| `POST` | `/api/v1/database-connections` | Register an existing database, or create one on a server with `server`. |
| `GET` | `/api/v1/database-connections/{slug}` | Show one record. |
| `PATCH` | `/api/v1/database-connections/{slug}` | Change the given fields. A `node_id` of null removes the Node link. |
| `DELETE` | `/api/v1/database-connections/{slug}` | Delete the record. `database.connection_attached` (409) while an Instance still uses it. A database on a server is dropped with its record, and an ACL user on a Valkey server is deleted. |
| `GET` | `/api/v1/database-connections/{slug}/users` | List the users that Orbit created for the connection. |
| `POST` | `/api/v1/database-connections/{slug}/users` | Add or update a user through the connection's [server](/reference/database-servers#add-a-user). |
| `POST` | `/api/v1/database-connections/{slug}/query` | Run one SQL statement. |
| `GET` | `/api/v1/database-connections/{slug}/tables` | List the tables. |
| `GET` | `/api/v1/database-connections/{slug}/schema` | List the columns of every table. |
| `GET` | `/api/v1/database-connections/{slug}/describe/{table}` | List the columns of one table. |

User creation takes `username`, `password`, optional `read_only`, and optional `replication` (strict booleans, default false). List returns the recorded `replication` attribute without passwords. Create returns an Operation and updates the recorded user after its command succeeds. [Logical replication users](/reference/database-servers#logical-replication-users) defines PostgreSQL grants, WAL requirements, and publication ownership.

`database_user.create` starts its Swamp workflow directly after admission. The Node worker runs the fixed native SQL action with sudo. PostgreSQL WAL and foreign-role checks run there before changing the login. Those failures appear on the Operation; request validation still rejects unsupported drivers, unsafe names and a username already owned by a connection.

SQL and passwords stay in the encrypted database intent and reach only the shared run-bound private-material callback. Public intent contains the driver, container, username, admitted digest and validation flags. The Gateway projects the recorded user after native success, without a command relay or execution receipt. This direct-only kind has no polled fallback; rollback reverts its cutover PR.

Native resume retries a failed terminal projection from its saved safe outcome without repeating successful SQL. PostgreSQL checks and creates a new login in one atomic block. If SQL succeeds before its native outcome is saved, replay accepts only the exact password verifier recorded in that intent; it refuses a foreign role. Grant replacement can then run again. This recovery does not promise exactly-once SQL.

Deletion returns `database_connection.required_by_ai_router` (409) while [AI router setup](/reference/ai-router#follow-the-cache-connection) uses the connection. This guard stays active when the extension switch is off; teardown releases it.

A change to a record does not change the keys that an earlier Instance attachment stored. Attach the connection again to update them. A connection update returns a `database.update` Operation. Proposed attributes remain encrypted until native validation succeeds; failure leaves the record unchanged. Valkey index limits are checked on the server Node.

An update to the configured cache for AI router returns `operation.kind_unavailable` until the AI-router workflow can compose native reconvergence. This also applies when the extension switch is off but configured state remains. The Gateway does not update the record and silently leave the collector with old credentials.

## Inspect a registered connection

Query, tables, schema, and describe accept only a registered slug. The request never supplies its own host, path, user, or password. Every inspection returns HTTP 202 with one `database.read` Operation. The CLI follows that Operation and displays its native result. `--json` returns the terminal Operation with the inspection under `result`.

A query takes `sql`, at most 16,384 characters, and `write`. A stacked statement returns `database.sql_multiple_statements`. Without `write: true`, statements classified as writes return `database.write_required`. MySQL and PostgreSQL reads run inside a read-only transaction on the Gateway Node's Swamp worker. SQLite opens the existing file read-only, enables query-only mode, and denies mutation, attachment, and extension functions through its authorizer. A read cannot create a missing SQLite file.

Network reads accept one SELECT, WITH, SHOW, EXPLAIN or DESCRIBE statement and a fixed set of pure functions. They refuse comments, backslash escapes, dollar quoting, file export clauses, transaction controls and other function calls. Use explicit write permission for statements outside this read subset.

`write: true` starts a `database.write` Operation whose result holds the affected `row_count` and `truncated`, without result rows. Read queries return `columns`, at most 500 `rows`, `row_count`, and `truncated` when rows or bytes exceed the native output bound. For a read, `row_count` counts returned rows. The private callback removes the stored password and credential-shaped text before saving any result resource.

| Driver | Where it runs |
| --- | --- |
| `mysql`, `pgsql` | A fixed PDO program on the exact Gateway Node worker, with SQL and credentials on protected stdin. |
| `sqlite` | A fixed Python SQLite program on the linked Node worker, as the admitted file owner. |
| `redis` | Not supported. Admission returns `database.driver_unsupported` (422). |

The SQLite Node must be active and have a WireGuard address. A record without a Node returns `database.sqlite_node_required`. SQLite inspection uses the owning production Instance user, or the production Instance user whose home contains the registered path on that Node. Development inspection uses the Node user. Swamp selects that identity before opening the file and does not change file or home permissions. No inspection calls an agent action or runs PDO in the Gateway request.

Unknown tables fail the Operation with `database.table_missing`. Inaccessible SQLite files fail with `database.sqlite_unreadable`. Other execution failures use `database.query_failed`. Failure results omit SQL, credentials, and native diagnostics. Table and schema metadata also use the native output bound.

## Create a database on a server

`POST /api/v1/database-connections` with `slug`, `server`, and an optional `instance_id` creates a MySQL or PostgreSQL database and owner on that [Database server](/reference/database-servers#create-a-database-on-a-server). `server` excludes `driver`, `node_id`, `host`, `port`, `database`, `username`, `password`, and `path`, and `instance_id` needs `server`. The Gateway returns 201 with the new record.

On a `redis` server, the same request with `patterns`, `allow`, and an optional numeric `database` creates a [Valkey ACL user](/reference/database-servers#create-an-acl-user-on-a-valkey-server) named after the slug and records a `redis` connection that logs in as that user. `patterns` and `allow` are lists of strings. A MySQL or PostgreSQL server refuses them with `validation.failed`, and a `redis` server refuses `instance_id` with `database.driver_unsupported`.

A managed Valkey connection's driver, Node, address, port, username, and password cannot change through `database:update`. It returns `database.server_connection_managed` (409). The database index can change. Create another connection to move clients to a different user.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_in_use` | 409 | Another managed database removal holds a server in the planned set. Retry when it finishes. |
| `database.in_use` | 409 | A session or dependency blocks managed database removal. Nothing is dropped. |
| `database.server_missing` | 404 | No server has that slug. |
| `database.server_inactive` | 409 | The server is not active yet. |
| `database.slug_conflict` | 409 | Another connection has the slug. |
| `database.name_conflict` | 409 | The server already has a database or user with the derived name. |
| `database.driver_unsupported` | 422 | `instance_id` names an Instance, but the server is a `redis` server. |
| `database.acl_rules_invalid` | 422 | Valkey refused a key pattern or a rule. |
| `database.server_command_failed` | 502 | The server could not create the database or user. |

## Owned databases

An Instance owns a database that Orbit created for it: by `database:create --server --instance`, by [`instance:create --database-server`](/domains/applications#database-on-a-server), or by the [clone](/domains/applications#database-clone) that `instance:create` runs. The record keeps the owner in `owner_instance_id`. A clone that cannot run returns `instance.database_clone_unsupported`, and a copy that fails returns `instance.database_clone_failed`. [Database clone](/domains/applications#database-clone) describes both.

A [production clone with a SQLite snapshot](/reference/instance-cloning#sqlite-seed) also registers an owned connection named `<project>-<instance>` on the target Node. Its path is `<production_home>/database.sqlite`. Orbit attaches it under `DB` and synchronizes the production `.env` before the clone completes. An interrupted clone reuses the connection and attachment when it resumes; the candidate's `DB_*` values do not copy.

[`instance:destroy`](/reference/instance-removal#owned-databases) drops each database the Instance owns, with its test databases and user, and deletes the record. Deleting the record of a database on a server drops the database the same way. Before any drop, Orbit checks the complete [removal set](/reference/database-servers#remove-a-database). A blocker returns `database.in_use` (409) without changing databases or records. A server failure keeps the record for retry. Orbit never drops a database that it only registered.

Development SQLite copies run directly in Swamp. A same-Node copy stays on that Node. A cross-Node copy writes a native file artifact; the target reads its exact version and verifies its size and digest before installation. Native tests cover WAL consistency, writer locks, file modes and temporary-file cleanup. Gateway tests cover admission and bounded record changes.

## Test databases

An owned database created or copied for development has a test database of the same kind, named in `test_database`. A production clone's SQLite snapshot connection has no test database.

| Driver | Test database |
| --- | --- |
| `mysql` | `<name>_test` on the same server. The user also gets every database whose name starts with `<name>_test`, so Laravel's parallel testing can create `<name>_test_test_1` and the rest. |
| `pgsql` | `<name>_test` and a recorded pool of `<name>_test_test_1..N`, owned by the Instance role without `CREATEDB`. [Create the pool](/reference/database-servers#create-a-database-on-a-server) with `--test-processes=N` (default 8, range 1–32). |
| `sqlite` | `:memory:` |

For an Instance that owns its `DB` database, [synchronization](/reference/environment-variables#synchronize) also sets the `DB_*` keys in `.env.testing` to the test database, with the connection's host and port. A missing file is created from the same values as `.env`, with `APP_ENV=testing`. In an existing untracked file, other lines stay. Orbit never writes a `.env.testing` that Git tracks; it records the test database name in the activity instead. Laravel loads `.env.testing` when `APP_ENV` is `testing`, which a Laravel `phpunit.xml` sets. A `phpunit.xml` entry with `force="true"` for a `DB_*` key still overrides it, so remove such entries to use the test database.

For PostgreSQL, synchronize the Instance environment, clear any cached Laravel configuration, then run `php artisan test --parallel --processes=4` with a process count no larger than the recorded pool. Plain `--parallel` uses the machine CPU count, so it is safe only when that count fits the pool. Leave `--recreate-databases` and `--drop-databases` off. Do not use `--without-databases`, which skips Laravel's worker switching.

Laravel 13's `Illuminate\Testing\Concerns\TestDatabases::ensureTestDatabaseExists` connects to `<DB_DATABASE>_test_<token>` and queries for a table before attempting creation. An existing reachable worker database needs no creation. A missing or unreachable worker makes Laravel drop that worker before attempting creation, which the managed role cannot do. A transient connection failure, including server connection exhaustion, can therefore remove a worker. Stop the test run, correct the connection or budget, and [restore missing members](/reference/database-servers#repair-a-postgresql-test-pool) instead of adding `CREATEDB`. Use Laravel's normal migration traits, such as `RefreshDatabase`, to prepare the schema; the empty pool does not run migrations itself.

A pool of N workers needs roughly N × (connections per worker) free server connections. Count connections to each database and extra application connections that a worker opens. Leave room for idle applications, other test runs, and admin connections when sizing PostgreSQL `max_connections`; the recorded pool size reserves databases, not connections. Reduce parallelism when the free budget is smaller. Doctor checks pool membership, not connection headroom.

## Add a connection on an Instance

`PUT /api/v1/instances/{instance}/database-connections/{slug}` writes the connection into the Instance's stored environment configuration. `{instance}` is an Instance ID or an exact Route domain. The body can hold `prefix`, an uppercase name of at most 32 characters that starts with a letter. The default is `DB`. The optional `replace` field is a strict boolean and defaults to false. The route needs an access grant to the Instance's Node.

| Driver | Keys with prefix `DB` |
| --- | --- |
| `mysql`, `pgsql` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| `redis` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, and `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` when stored |
| `sqlite` | `DB_CONNECTION`, `DB_DATABASE` as the file path, and `DB_USERNAME`, `DB_PASSWORD` when stored |

A SQLite connection can only be attached to an Instance on the connection's registered Node. A different or absent Node returns `database.node_mismatch` before any environment values or attachment mappings change.

The Gateway removes the other keys of the prefix, for example `DB_HOST` when a sqlite connection replaces a mysql one. An add that would change or remove an existing stored key returns `database.environment_conflict` (409) and leaves the keys and mapping unchanged. Pass `replace: true` in the API or `--replace` in the CLI to allow that change. An unchanged add is idempotent. This applies to every prefix, including the active `DB` connection.

The Gateway writes the stored host and port by default. A database on a [Database server](/reference/database-servers) stores the Node's WireGuard address and the published port. The response also exposes `lan_host` for a managed connection whose server Node has a LAN address. External records keep their explicit host.

The result names the Instance, slug, prefix, written keys, host, port, whether stored configuration changed, and the key count. It never holds a value.

`DELETE /api/v1/instances/{instance}/database-connections/{slug}` removes the mapping and the six keys of its prefix. Other keys stay. An unknown mapping returns `database.attachment_missing` (404).

Add and remove change stored configuration only. Run [`env:sync`](/reference/environment-variables#synchronize) to write the Instance's `.env`.

## Choose LAN for an Instance

`instance:database:add <slug> --instance=<instance> --network=lan` selects the managed server’s LAN address for that attachment. Both Nodes must be active and each must have an IPv4 LAN address. No peer list or Cluster membership is required. Orbit returns `database.lan_unavailable` before writing keys if either Node fails that check. `--network=wireguard` is the default; reattach with that option to switch back.

Before selecting LAN on an existing server, retry `database:server:create` to publish its LAN address and confirm the Process bindings. The attachment stores its network choice. Environment projection, `.env.testing`, and Doctor use that same choice. The connection's default host and Gateway inspection remain WireGuard. External connections do not derive LAN endpoints: record their intended host explicitly. `env:sync` is still required after reattachment.

## Inspect attachments with Doctor

[Doctor](/cli/doctor) checks database connections in its `database_connection` family. It compares each record and mapping with the Instance's stored keys, and changes nothing.

| Code | Kind | Meaning |
| --- | --- | --- |
| `database_connection.missing` | Drift | A mapping names a record that does not exist. |
| `database_connection.unhealthy` | Drift | A record lacks a required field or names a Node that is gone. |
| `database_connection.env_mismatch` | Drift | A mapping's stored keys are missing, left over, or different. |
| `database_connection.inspection_failed` | Unverifiable | Doctor could not read the password or the stored configuration. |

To repair an `env_mismatch`, add the connection again with the same prefix, with `--replace`, then synchronize.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A registry, not a database manager

The registry records how to reach a database, so a connection works for an external host too. Orbit changes data only for a database it created on a [Database server](/reference/database-servers): it creates that database, clones into it, and drops it with its owner. Deleting the record of a registered database never touches data.

### Tests get their own database

A test run with `RefreshDatabase` empties the database it uses. A separate test database of the same kind keeps an Instance's data safe, and `.env.testing` lets Laravel use it without a change in each repository. Making each repository read a `DB_TEST_*` key was rejected, because every repository would need that change first.

### One result shape for every SQL driver

Swamp runs fixed PDO programs for MySQL and PostgreSQL on the Gateway Node worker. SQLite uses a fixed native program on the file’s Node under its admitted owner. Both return the same bounded result shape. The Gateway records owner selection and write authorization at admission; Swamp owns execution and result redaction.

### Secrets stay off the command line

SQL and credentials travel through the run-bound private-material callback and protected standard input. They do not appear in command arguments, native output or public Operation history. Each job runs only on its admitted Node worker.

## Direct SQLite cleanup

SQLite snapshot cleanup uses only the direct `operation-database-cleanup` workflow. Its declaration is the only dispatcher. There is no polled database path. Rollback reverts the kind change.

The shared `StartSwampRun` helper starts the workflow after commit with `ORBIT_SWAMP_RUN_TOKEN`. The Gateway exposes only the admitted cleanup fields and projects the bounded result. It has no cleanup primitive, claim, lease, target hold, keyed receipt or Node lock.

The action runs on the admitted Node. Every Node uses its matching `orbit-node-<node_id>` worker and string Node label. Missing placement stays queued under native Swamp defaults.

The worker runs as the Node's configured user. Clone cleanup removes only its owned private snapshot directory. Seed cleanup runs the fixed native program through the Node user's existing passwordless sudo and validates the recorded source, target and snapshot paths. Neither action accepts a caller-supplied command or execution identity.

### Direct SQL writes

`database:query --write` starts `operation-database-write` directly. SQLite runs the fixed native SQLite program as its admitted owner. MySQL and PostgreSQL use a fixed local PDO program on the Gateway Node. SQL and connection credentials stay in the private-material callback and protected stdin. Each step targets only its admitted `orbit-node-ID` worker and matching Node label. Neither path invokes an agent action or requires an agent pin. Native projection resumes from its saved row count without repeating SQL.

SQL writes are limited to 16,384 characters. MySQL refuses multiple statements in one call. A failure before the SQL effect is invoked projects failed with `database.write_outcome_unknown`; an exception after invocation requires inspection before another effect. Read-only inspection uses its own native database.read Operation.

SQLite writes have an engine attachment limit of zero. `ATTACH` and `VACUUM INTO` cannot create another database file, including through an SQL expression. The same limit refuses ordinary `VACUUM`, which needs an internal attachment. These refusals return `database.query_failed` and leave the registered file unchanged.

### Instance-owned removal

`database.destroy_owned` records the owned set once. One native Swamp DAG removes its databases on their admitted Nodes and its SQLite files on the Instance Node. Instance removal composes the same lower actions inside its parent run. Each server is a placed workflow step. The Gateway creates no child Operations and performs no remote SQL or file removal. It deletes the owned connection records only after the whole native DAG succeeds. Attached databases that the Instance does not own stay. Each placed step reads its admitted public descriptor and current cancellation state. A resumed projection uses saved outcomes without repeating removals.

SQLite export, seed and abandon use native source and target steps in one run. Only their admitted source and target Nodes may read the public descriptors; only the root Node may record the result. Cancellation stops new work. Cleanup runs after started work, before the terminal result, and a missing native outcome records failure.

### Native SQLite scratch cleanup

`database.sqlite_cleanup` submits its complete non-secret snapshot descriptor to
`operation-database-cleanup`. Separate placed command steps handle private clone
scratch and source seed state owned by root. The admitted variant selects one step.
Neither step calls the Gateway or uses an execution vault. Cleanup never selects
the installed database as its deletion target.

Clone cleanup requires a scratch directory owned by the worker with mode 0700.
It refuses symlinks or unrelated entries. Seed cleanup checks its root-owned mode-0700 state,
mode-0600 metadata, admitted source identity and saved snapshot inode. Missing
owned scratch is already clean; a foreign or replaced file is a refusal. Native
dependencies order each cleanup and its result. The shared terminal subscriber projects the
bounded stdout result, and result delivery can resume without repeating a
completed cleanup.
