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
  - apps/agent/src/commands/sqlite_query*.rs
  - apps/gateway/app/{Actions/Instances/CloneInstanceDatabaseAction.php,Domain/Instances/DatabaseClone/**,Infrastructure/Instances/RemoteInstanceSqliteCloner.php}
---

# Database connections

A Database connection is a Gateway record that describes one database: its driver, where it is, and how to log in. The Gateway stores the password encrypted. You can register an existing database, create one on a [Database server](/reference/database-servers), inspect it, and attach it to an Instance. The registry never starts or stops a database. [`database`](/cli/database) lists the commands.

## Execution

Database writes that change a Node return an [Operation](/reference/operations) with HTTP 202. The Gateway validates the request and records intent. Swamp runs the steps and reports proven results. The CLI follows the Operation; use `--idempotency-key` to retry the same start after a lost response. List, show, tables, schema, describe, and read-only queries stay in the Gateway.

The Gateway resolves stored database credentials only when it invokes an authorized named command. Operations and Swamp receipts contain references, never passwords. Removal deletes the managed record only after the Node confirms the effect.

## Instance lifecycle children

The database family owns `PrepareDatabaseChildAction` in `apps/gateway/app/Actions/DatabaseConnections/`. Lifecycle calls this Gateway admission action inside its transaction. It records protected intent and returns `{key, kind, node_id, input}`. Preparation performs no Node writes. After activation, lifecycle submits the returned descriptor through Kit v2 child admission with a stable `key`, then waits for the child to succeed.

| Gateway admission signature | Child kind | Execution Node |
| --- | --- | --- |
| `create(Instance $target, DatabaseServer $server, Operation $parent): array` | `database.create` | The explicit server's Node. |
| `clone(Instance $target, Instance $source, Operation $parent): array` | `database.clone` | The source MySQL server's Node, or the target SQLite Instance's Node. |
| `destroyOwned(Instance $target, Operation $parent): array` | `database.destroy_owned` | The Instance's Node; managed drops run as children on their servers. |
| `sqliteSeed(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath, Operation $parent): array` | `database.sqlite_seed` | The seed target's Node. |
| `sqliteSeedAbandon(Instance $target, int $seedIntentId, Operation $parent): array` | `database.sqlite_seed_abandon` | The target Node recorded by that seed intent. |

Every lifecycle database child requires `target_type: instance` and `target_id` equal to its owning Instance, including nested source export and cleanup children. A multi-Instance destroy prepares one descriptor per member; child admission must preserve each descriptor's recorded target. Its execution Node can differ. Public database commands keep their own database resource target. The generated `input` includes `intent_id` and `owner_instance_id`; callers preserve the complete input instead of constructing it from Instance IDs. Passwords and rendered SQL remain encrypted Gateway intent. Kit v2 records `parent_operation_id` and `child_key` through its children endpoint; neither is a caller input field.

Create requires an active MySQL server and records an Instance-owned database, owner user, and test database. Clone selects the source Instance's `DB` attachment and supports managed MySQL or SQLite inside its checkout. Successful create and clone attach the result under `DB` in Gateway state. Lifecycle synchronizes the resulting environment through its own workflow after the child succeeds.

Removal selects only connections whose `owner_instance_id` matches the Instance. It preflights the full managed set before preparing drops, preserves shared users and foreign connections, and removes local owned SQLite files before completing. An Instance with no owned connections still completes through a proven `database.owned-ready` command. Lifecycle waits for removal before deleting the Instance row or its checkout.

Cross-node SQLite clone and seed use database-owned `database.sqlite_export` and `database.sqlite_cleanup` children. Lifecycle admits only the top-level descriptor. Snapshot bytes stay out of Operations and Swamp. The seed placements record Instance ID, environment, base path, execution user, Node, and optional transfer Operation ID; a development transfer keeps one Instance ID with different source and target placements.

Transfer rollback prepares `sqliteSeedAbandon` from the seed intent ID stored in the transfer attempt. The target placement's `operationId` identifies that attempt and must not be empty. Abandon derives both cleanup commands and Nodes from that intent, reuses one prepared descriptor, and requires the target's `cleaned` receipt and a successful receipt for the source's fixed cleanup command. It does not accept a new attempt identity, path or Node from Swamp. Target cleanup runs first; source cleanup remains eligible after a target refusal. Both named Node cleanup commands tolerate a repeat and preserve unrelated files.

`database.sqlite_seed_abandon` requires `OperationKinds::supports('compensations')`, in addition to children and receipts. A cancelled transfer admits it through the kit's declared compensation boundary, directly or inside its owning rollback compensation child. The cancelled parent performs no rollback effects itself.

Kind contracts, including runs, child Node relations, completion proof, and redacted results, live in `apps/gateway/app/Domain/DatabaseServers/operation-kinds.php`. Database workflow execution lives under `extensions/behaviors/operations/database/` in orbit-swamp.

Database execution requires the kit's children and receipt capabilities. Until both are available, admission returns `operation.kind_unavailable`; the Gateway still loads other families and serves database reads. Child admission uses the recorded database intent's kind, target, Node and exact input. Swamp supplies only its key.

A failed create can reuse its reserved credentials for 24 hours. Every retry repeats the name check. PostgreSQL permits existing names only when the reserved role owns them and repeats that guard in SQL before grants. MySQL uses strict `CREATE DATABASE`, so an existing database refuses creation, including a partially completed create without a successful receipt. Recovery of the same Operation uses its keyed receipt. 

Removal checks PostgreSQL ownership inside its admission fence before every drop. MySQL removal and clone reset require a durable successful create receipt for each database name; a reserved row alone is insufficient. MySQL create records each database in a separate keyed step before credentials and grants. A preflight name refusal with no started effect forgets the reserved connection. Removal holds the database lock across its final login check and Node dispatch, and connection reservations take that same lock.

Every lifecycle preparation records the parent Operation on the intent before the child is admitted. The Gateway retains pending intent while that parent is active. The Gateway deletes protected execution intents after their Operations and ancestors finish and the 24-hour retry window closes. Operation records and redacted receipts remain available. A retry after that window must resolve the reserved connection explicitly; it cannot recover expired credential SQL.

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

Deletion returns `database_connection.required_by_ai_router` (409) while [AI router setup](/reference/ai-router#follow-the-cache-connection) uses the connection. This guard stays active when the extension switch is off; teardown releases it.

A change to a record does not change the keys that an earlier Instance attachment stored. Attach the connection again to update them. Processes that explicitly depend on a connection follow a separate contract: a connection update rebuilds their connection-derived environment and reconverges them in place, keeping the Process ID and desired state. The [ai-router collector](/reference/ai-router#follow-the-cache-connection) follows its configured cache connection this way; it needs no manual reattachment.

A validated connection change is stored before dependent runtimes converge and is not rolled back on a runtime failure. Retrying an identical update still checks and repairs stale dependents, even when no connection field changes. See [Connection persistence and retry recovery](/reference/ai-router#connection-persistence-and-retry-recovery) for lost responses, crashes, partial completion, and retry limits.

## Inspect a registered connection

Query, tables, schema, and describe run only against a registered slug. The request never takes its own host, path, user, or password.

A query takes `sql`, at most 16,384 characters, and `write`. It runs one statement; a stacked statement returns `database.sql_multiple_statements`.

Without `write: true`, the Gateway refuses a statement whose leading verb writes, such as `INSERT`, `UPDATE`, `DELETE`, `CREATE`, or `DROP`, with `database.write_required`. That check reads only the leading verb. For mysql and pgsql the statement then runs in a normal session, so a statement with side effects that does not start with a write verb, such as `SET` or a `SELECT` that calls a function, runs without `write`. Only SQLite opens the file read-only.

`write: true` starts an Operation whose result holds the affected `row_count` and `truncated: false`, without result rows. It does not replay uncertain SQL until the target receipt proves the outcome. Read queries return `columns`, at most 500 `rows`, `row_count`, and `truncated` when more rows exist. For a read, `row_count` is the number of returned rows. The Gateway replaces the stored password with `[REDACTED]` wherever it appears in a result. See [text-redaction failures](/cli/activity#text-redaction-failures) for the shared failure rule.

| Driver | Where it runs |
| --- | --- |
| `mysql`, `pgsql` | Reads use PDO on the Gateway. Swamp invokes the named PDO write program on the Gateway Node for writes. Credentials resolve inside the Gateway before dispatch. |
| `sqlite` | On the linked Node, through the native `database.query-sqlite` or `database.write-sqlite` agent command. |
| `redis` | Not supported. `database.driver_unsupported` (422). |

For SQLite, the Gateway sends the selected owner, path, and SQL through `AgentCommandRunner` on protected standard input. The query command opens an existing file read-only. The write command opens an existing file for writing and runs under the agent's mutation supervisor and a lock for that path. Neither command creates a missing database. Both return at most 500 rows and preserve column order and scalar values.

The Node must be active and have a WireGuard address. A record without a Node returns `database.sqlite_node_required`. SQLite inspection runs as the owning production Instance user, or as the production Instance user whose home contains the registered path on that Node. Development inspection runs as the Node user. The agent drops to that user's identity before opening the file and does not change home or file permissions. Inaccessible files or an unavailable owner return `database.sqlite_unreadable`. SQL failures return `database.query_failed`, without database contents or native diagnostics.

An unknown table returns `database.table_missing` (404). A failed query on the database or the Node returns `database.query_failed` (502).

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

Development SQLite copies run through the [Node agent’s](/reference/node-agent#commands) `instance.copy-sqlite` command. A same-Node copy stays on that Node. A cross-Node copy exports a snapshot, streams it through the Gateway with `instance.read-sqlite-snapshot` and `instance.receive-sqlite-snapshot`, and verifies its size and digest before installation. Rust tests own WAL consistency, writer locks, file modes, and temporary-file cleanup; Gateway tests cover the requested operations and public errors.

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

The Gateway reaches mysql and pgsql through PDO. A SQLite file exists only on its Node, so the agent uses its embedded SQLite library and returns the same result shape. The Gateway retains owner selection, write authorization, and redaction. This removes the hidden CLI execution path and its PHP dependency. A `sqlite3` subprocess would add another output format and parser. Opening the Node file from the Gateway is impossible.

### Secrets stay off the command line

The SQL, the path, and the token travel on protected standard input, so they never show in a process list. SSH from the Gateway is the authorization boundary for the hidden command, and WireGuard membership is the boundary for the fleet. The token does not add protection by default.
