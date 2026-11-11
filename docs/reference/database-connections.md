---
title: "Database connections"
description: "The Gateway-owned registry of mysql, pgsql, sqlite, and redis connections, the databases that Instances own, their test databases, and how an operator attaches a connection to an Instance."
covers:
  - swamp/workflows/workflow-operation-database-{read,write}.yaml
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

Both database actions refuse a server Node without a stored SSH host key (`swamp.ssh_target_unavailable`) before they record anything. Create and user admission refuse while removal targets the same server; removal refuses while creation is unfinished.

- Create reserves names and credentials without probing a server. A matching retry reuses them.
- Swamp refuses a database that exists for another owner (`database.name_conflict`) before any change. A MySQL exact grant escapes underscores.
- The connection becomes active only after the owner logs in with its own password (`database.owner_login_failed` otherwise).
- Destruction refuses system names and prefixes, names that are not plain identifiers, and attached connections. It refuses blockers before any drop.

## Instance database steps

Instance runs compose Database actions as steps of their own workflow. Grouped removal records one aggregate database intent for the admitted Instance set, with one entry per SQL server. Each server Node receives only its own passwords. Successful projections attach a created connection or remove the admitted connection records; they do not re-run SQL or inspect a Node.

A development create copies a SQLite or MySQL [database clone](/domains/applications#database-clone) natively, and creates its own database on `--database-server` with the `database:create` child. A clone of a container default also clones a PostgreSQL database, with a [template copy](/reference/database-servers#clone-a-default-database-for-a-container-clone) on the default's server. A production clone or transfer with a SQLite source returns `operation.kind_unavailable` (503) before any change.

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

`database_user.create` starts its Swamp run right after admission. [Add a user](/reference/database-servers#add-a-user) describes its SQL batch and the user's login check. Request validation rejects unsupported drivers, unsafe names, and a username that a connection already owns.

Deletion returns `database_connection.required_by_ai_router` (409) while [AI router setup](/reference/ai-router#follow-the-cache-connection) uses the connection. This guard stays active when the extension switch is off; teardown releases it.

A change to a record does not change the keys that an earlier Instance attachment stored. Attach the connection again to update them. A connection update returns a `database.update` Operation. Only a new index on a managed Valkey server starts a run: one `valkey-cli` call on the server Node reads the database count, with `AUTH` from the vault on standard input. The Gateway applies the index only when it is below that count (`database.index_invalid` otherwise). Every other change is record-only: the Gateway writes it at once, and the Operation has already succeeded.

The workflow receives the captured endpoint, proposed index and SSH identity as public intent. A placed step on Node 209 uses official SSH and the official Valkey CLI to read the server limit. Its admin password comes from a named execution copy in Swamp vaults. An update that only changes the record skips SSH and secret resolution. The shared terminal subscriber applies a successful patch once.

An update to the configured cache for AI router returns `operation.kind_unavailable` until the AI-router workflow can compose native reconvergence. This also applies when the extension switch is off but configured state remains. The Gateway does not update the record and silently leave the collector with old credentials.

## Inspect a registered connection

Query, tables, schema, and describe accept only a registered slug. The request never supplies its own host, path, user, or password. Every inspection returns HTTP 202 with one `database.read` Operation. The CLI follows that Operation and displays its native result. `--json` returns the terminal Operation with the inspection under `result`.

A query takes `sql`, at most 16,384 characters, and `write`. A stacked statement returns `database.sql_multiple_statements`. Without `write: true`, statements classified as writes return `database.write_required`. MySQL and PostgreSQL reads run inside a `READ ONLY` transaction. SQLite runs `sqlite3 -readonly -safe`, which refuses writes, attachments, file functions, and shell commands. A read cannot create a missing SQLite file.

MySQL and PostgreSQL reads accept one SELECT, WITH, SHOW, EXPLAIN or DESCRIBE statement and a fixed set of pure functions. They refuse comments, backslash escapes, dollar quoting, file export clauses, transaction controls and other function calls with `database.query_refused` (422) before any run. MySQL DDL commits a read-only transaction itself, so this check is the guard. Use explicit write permission for statements outside this read subset.

`write: true` starts a `database.write` Operation whose result holds the affected `row_count` and `truncated`, without result rows. Read queries return `columns`, at most 500 `rows`, `row_count`, and `truncated` when rows or bytes exceed the output bound. Values are text, and SQL NULL is `null`. A query without rows on MySQL returns no `columns`. For a read, `row_count` counts returned rows. The Gateway removes the stored password and credential-shaped text before it saves the result.

One `@swamp/ssh` step from Node 209 runs one client. The client reads the password from the first stdin line and the statement after it, never from argv or a file. Its output stops at 64 KiB, and it has 30 seconds.

| Connection | Where it runs |
| --- | --- |
| `mysql`, `pgsql` on a [Database server](/reference/database-servers) | `docker exec -i` into the server's container on its Node. The client in the image logs in as the connection's user. |
| `mysql`, `pgsql` registered without a server | The `mysql` or `psql` client on Node 209, which the `swamp` role installs. |
| `sqlite` | `sqlite3` on the linked Node, as the file's owner. The app roles install it. |
| `redis` | Not supported. Admission returns `database.driver_unsupported` (422). |

The SQLite Node must be active and have a WireGuard address. A record without a Node returns `database.sqlite_node_required`. SQLite inspection uses the owning production Instance user, or the production Instance user whose home contains the registered path on that Node. Development inspection uses the Node user. The read runs as that user through `sudo` and does not change file or home permissions. No inspection calls an agent action or connects from the Gateway request.

Unknown tables fail the Operation with `database.table_missing`. Every other execution failure, such as an SQL error, a timeout or an unreadable SQLite file, uses `database.query_failed`. Failure results omit SQL, credentials, and native diagnostics. Table and schema metadata also use the native output bound.

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

An Instance owns a database that Orbit created for it: by `database:create --server --instance`, by [`instance:create --database-server`](/domains/applications#database-on-a-server). The record keeps the owner in `owner_instance_id`.

[`instance:destroy`](/reference/instance-removal#native-order) drops each database the Instance owns, with its test databases and user, and deletes the record. Deleting the record of a database on a server drops the database the same way. Before any drop, Orbit checks the complete [removal set](/reference/database-servers#remove-a-database). A blocker returns `database.in_use` (409) without changing databases or records. A server failure keeps the record for retry. Admission records each database's Node and its stored SSH host pin, so a retry reaches the same host. Orbit never drops a database that it only registered.

## Test databases

An owned database created for development has a test database of the same kind, named in `test_database`.

| Driver | Test database |
| --- | --- |
| `mysql` | `<name>_test` and a recorded pool of `<name>_test_test_1..N` on the same server. The user also gets every database whose name starts with `<name>_test`. [Create the pool](/reference/database-servers#create-a-database-on-a-server) with `--test-processes=N` (default 8, range 1–32). |
| `pgsql` | `<name>_test` and a recorded pool of `<name>_test_test_1..N`, owned by the Instance role without `CREATEDB`. [Create the pool](/reference/database-servers#create-a-database-on-a-server) with `--test-processes=N` (default 8, range 1–32). |
| `sqlite` | `:memory:` |

For an Instance that owns its `DB` database, [synchronization](/reference/environment-variables#synchronize) also sets the `DB_*` keys in `.env.testing` to the test database, with the connection's host and port. A missing file is created from the same values as `.env`, with `APP_ENV=testing`. In an existing untracked file, other lines stay. Orbit never writes a `.env.testing` that Git tracks; it records the test database name in the activity instead. Laravel loads `.env.testing` when `APP_ENV` is `testing`, which a Laravel `phpunit.xml` sets. A `phpunit.xml` entry with `force="true"` for a `DB_*` key still overrides it, so remove such entries to use the test database.

For PostgreSQL, synchronize the Instance environment, clear any cached Laravel configuration, then run `php artisan test --parallel --processes=4` with a process count no larger than the recorded pool. Plain `--parallel` uses the machine CPU count, so it is safe only when that count fits the pool. Leave `--recreate-databases` and `--drop-databases` off. Do not use `--without-databases`, which skips Laravel's worker switching.

Laravel 13's `Illuminate\Testing\Concerns\TestDatabases::ensureTestDatabaseExists` connects to `<DB_DATABASE>_test_<token>` and queries for a table before attempting creation. An existing reachable worker database needs no creation. A missing or unreachable worker makes Laravel drop that worker before attempting creation, which the managed role cannot do. A transient connection failure, including server connection exhaustion, can therefore remove a worker. Stop the test run, correct the connection or budget, and [restore missing members](/reference/database-servers#repair-a-postgresql-test-pool) instead of adding `CREATEDB`. Use Laravel's normal migration traits, such as `RefreshDatabase`, to prepare the schema; the empty pool does not run migrations itself.

A pool of N workers needs roughly N × (connections per worker) free server connections. Count connections to each database and extra application connections that a worker opens. Leave room for idle applications, other test runs, and admin connections when sizing PostgreSQL `max_connections`; the recorded pool size reserves databases, not connections. Reduce parallelism when the free budget is smaller. Native database operations validate pool membership, not connection headroom.

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

`instance:database:add <slug> --instance=<instance> --network=lan` selects the managed server’s LAN address for that attachment. Both Nodes must be active and each must have an IPv4 LAN address. No peer list or Cluster membership is required. Orbit returns `database.lan_unavailable` before writing keys if either Node fails that check. `--network=wireguard` is the default; add the connection again with `--network=wireguard --replace` to switch back.

Before selecting LAN on an existing server, retry `database:server:create` to publish its LAN address and confirm the Process bindings. The attachment stores its network choice. Environment projection and `.env.testing` use that same choice. The connection's default host and Gateway inspection remain WireGuard. External connections do not derive LAN endpoints: record their intended host explicitly. `env:sync` is still required after reattachment.

## Inspect attachments with Doctor

Connection admission and the owning native database operations validate database intent. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat attachment, environment or credential checks.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A registry, not a database manager

The registry records how to reach a database, so a connection works for an external host too. Orbit changes data only for a database it created on a [Database server](/reference/database-servers): it creates that database, clones into it, and drops it with its owner. Deleting the record of a registered database never touches data.

### Tests get their own database

A test run with `RefreshDatabase` empties the database it uses. A separate test database of the same kind keeps an Instance's data safe, and `.env.testing` lets Laravel use it without a change in each repository. Making each repository read a `DB_TEST_*` key was rejected, because every repository would need that change first.

### One result shape for every SQL driver

Each driver's own client prints the rows: XML for MySQL, which marks NULL, and CSV for PostgreSQL and SQLite, with a per-read marker for NULL. The Gateway shapes all three into the same bounded result and redacts it. It checks the statement and selects the owner at admission; Swamp runs the one client.

### Secrets stay off the command line

SQL and credentials travel through the run-bound private-material callback and protected standard input. They do not appear in command arguments, native output or public Operation history. Each job runs only on its admitted Node worker.

## Direct runs

### Direct SQL writes

`database:query --write` starts `operation-database-write`. A write runs where a [read](#inspect-a-registered-connection) runs, with the same client: in the Database server's container, on Node 209 for a registered external database, or with `sqlite3` on the SQLite file's Node as its owner. The client reads the password from the first stdin line and the statement after it, from one vault value. No step calls the Gateway or runs a shipped script.

After the statement, the client prints the affected row count: `ROW_COUNT()` on MySQL, the command tag on PostgreSQL and `changes()` on SQLite. The shared terminal subscriber records it as `row_count`, with `truncated` when the output reached its 64 KiB bound. A failed or timed-out statement returns `database.query_failed`. Output the Gateway cannot read returns `database.write_outcome_unknown`.

SQL writes are limited to 16,384 bytes and one statement, which admission checks. SQLite runs `sqlite3 -safe`: `ATTACH`, `VACUUM INTO` and ordinary `VACUUM`, which needs an internal attachment, fail with `database.query_failed` and leave the registered file unchanged. The same mode refuses file functions and shell commands.

### Instance-owned removal

`instance:destroy` removes the databases its Instances own inside its own run; there is no separate owned-removal Operation. The Gateway records the owned set once at admission. For each Database server, the run calls the `operation-database-destroy` workflow that `database:destroy` uses, with the server's admin login from its own vault key. The official `@swamp/cfgmgmt/file` behavior removes each owned SQLite file under the Node account; the Gateway admits only paths inside the Instance checkout.

The Gateway deletes the owned connection records only after the whole Instance removal succeeds. Failure or cancellation keeps them. Attached databases outside the owned set still block admission.
