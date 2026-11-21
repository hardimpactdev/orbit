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

A Database connection is a Gateway record that describes one database: its driver, where it lives, and how to log in. The Gateway stores passwords encrypted. You can register an existing database, create one on a [Database server](/reference/database-servers), inspect it, and attach it to an Instance. The registry never starts or stops a database server. [`database`](/cli/database) lists the commands.

## Drivers and fields

Each record has a unique slug: lowercase words joined by hyphens, at most 63 characters. Each driver takes its own fields.

| Driver | Required | Optional | Default port |
| --- | --- | --- | --- |
| `mysql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `3306` |
| `pgsql` | `host`, `database`, `username`, `password` | `node_id`, `port` | `5432` |
| `sqlite` | `path` | `node_id`, `username`, `password` | none |
| `redis` | `host` | `node_id`, `port`, `database`, `username`, `password` | `6379` |

`host` is a hostname or an IP address without a user or port. `port` is 1 through 65535. `path` is an absolute Unix path of at most 1,024 characters. A redis `database` is an integer database index from 0 through 999. The Gateway returns `validation.failed` for any field that the driver does not accept, such as `path` on `mysql` or `host` on `sqlite`.

A redis `username` names an ACL user on the server. Without it, clients log in as the `default` user. One connection holds one username and one password for every client that uses it. To give one client its own ACL user, create a second connection on a [Valkey Database server](/reference/database-servers#create-an-acl-user-on-a-valkey-server) instead of changing a shared connection, because every Process that [follows it](/reference/ai-router#follow-the-cache-connection) moves to the new user too. After setup, the [Gateway queue](/reference/gateway-queue#acl-users) uses `gateway-queue` this way.

`node_id` links the record to a Node. The Node needs no `database` role. SQLite inspection requires this link, and it lets an Instance on the same Node reach a Docker Process locally.

Responses return `id`, `slug`, `driver`, `node_id`, `host`, `lan_host`, `port`, `database`, `path`, `username`, `has_password`, `server`, `owner_instance_id`, `test_database`, `locale`, and `test_processes`. Show also returns `users_count`. `server` is the slug of the [Database server](/reference/database-servers) that holds the database, or `null`. `owner_instance_id` names the Instance that owns the database, or `null`. No response, Activity entry, or error ever contains a password. To share credentials with people, the Gateway can [publish MySQL and PostgreSQL connections to Vaultwarden](/reference/vaultwarden).

## API

Registry routes require an access grant to the Gateway Node. A duplicate slug returns `database.slug_conflict` (409), and an unknown slug returns 404.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/database-connections` | List records, ordered by slug. |
| `POST` | `/api/v1/database-connections` | Register an external database (201), or start an Operation to create one on a `server` (202). |
| `GET` | `/api/v1/database-connections/{slug}` | Show one record. |
| `PATCH` | `/api/v1/database-connections/{slug}` | Update the given fields. Returns a `database.update` Operation (202). Setting `node_id` to `null` removes the Node link. |
| `DELETE` | `/api/v1/database-connections/{slug}` | Delete the record: 200 for an external database, or an Operation (202) to drop a server-managed database or Valkey ACL user. |
| `GET` | `/api/v1/database-connections/{slug}/users` | List users that Orbit created for the connection. |
| `POST` | `/api/v1/database-connections/{slug}/users` | Add or update a user through the connection's [server](/reference/database-servers#add-a-user) (202). |
| `POST` | `/api/v1/database-connections/{slug}/query` | Run one SQL statement (202). |
| `POST` | `/api/v1/database-connections/{slug}/import` | Load a plain or gzipped SQL dump into an empty server-managed database (202). |
| `GET` | `/api/v1/database-connections/{slug}/tables` | List the tables (202). |
| `GET` | `/api/v1/database-connections/{slug}/schema` | List the columns of every table (202). |
| `GET` | `/api/v1/database-connections/{slug}/describe/{table}` | List the columns of one table (202). |

Writes that change a Node return an [Operation](/reference/operations) with HTTP 202. The CLI follows the Operation automatically; pass `--idempotency-key` to retry safely after a lost start response. Server-targeted actions refuse a Node without a stored SSH host key (`swamp.ssh_target_unavailable`) before recording anything. Create and user creation refuse while removal targets the same server, and removal refuses while creation is unfinished.

User creation takes `username`, `password`, optional `read_only`, and optional `replication` (strict booleans, default `false`). List responses include `replication` without passwords. [Add a user](/reference/database-servers#add-a-user) and [Logical replication users](/reference/database-servers#logical-replication-users) describe the SQL grants and WAL requirements.

Deletion returns `database.connection_attached` (409) while an Instance still uses the connection, and `database_connection.required_by_ai_router` (409) while [AI router setup](/reference/ai-router#follow-the-cache-connection) uses it. The AI router guard stays active even when the extension switch is off; teardown releases it.

Updating a record does not change the keys an earlier Instance attachment wrote; attach the connection again to update them. Updating a SQLite connection's `node_id` while it is attached to an Instance on another Node returns `database.node_mismatch` (422). Only changing the database index on a managed Valkey server contacts the Node to verify the index is below the server's database count (`database.index_invalid` otherwise). Every other record update finishes immediately. Updating the configured AI router cache returns `operation.kind_unavailable` so the collector is never left with stale credentials.

## Create a database on a server

`POST /api/v1/database-connections` with `slug` and `server` creates a database and owner on that [Database server](/reference/database-servers#create-a-database-on-a-server) and returns HTTP 202 with an Operation. Passing `server` excludes `driver`, `node_id`, `host`, `port`, `database`, `username`, `password`, and `path`.

For MySQL and PostgreSQL servers, the request also accepts `instance_id` (requires `server`), `test_processes` (1 through 32, default 8, requires `instance_id`), and `locale` (`C.UTF-8` or `C` on `pgsql`). Orbit reserves names and credentials before contacting the server, refuses an existing database owned by another user (`database.name_conflict`), and marks the connection active only after the new owner logs in with its own password (`database.owner_login_failed` otherwise).

For a `redis` server, the same request takes `patterns` (list of strings), `allow` (list of strings), and an optional integer `database` (0 through 999) to create a [Valkey ACL user](/reference/database-servers#create-an-acl-user-on-a-valkey-server) named after the slug. MySQL and PostgreSQL servers refuse `patterns` and `allow` with `validation.failed`, and a `redis` server refuses `instance_id` with `database.driver_unsupported`.

A managed connection's driver, Node, host, port, database name, username, and password cannot change through `database:update` (`database.server_connection_managed`, 409). On a managed Valkey connection, only the `database` index can change; create another connection to move clients to a different ACL user.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_in_use` | 409 | Another managed database removal holds a server in the planned set. Retry when it finishes. |
| `database.in_use` | 409 | A session or dependency blocks managed database removal. Nothing is dropped. |
| `database.server_missing` | 404 | No server has that slug. |
| `database.server_inactive` | 409 | The server is not active yet. |
| `database.slug_conflict` | 409 | Another connection has the slug. |
| `database.name_conflict` | 409 | The server already has a database or user with the derived name. |
| `database.user_reserved` | 422 | The derived user name is the server's admin login or a system role, such as `root` or `postgres`. |
| `database.driver_unsupported` | 422 | `instance_id` names an Instance, but the server is a `redis` server. |
| `database.acl_rules_invalid` | 422 | Valkey refused a key pattern or rule. |
| `database.server_command_failed` | 502 | The server could not create the database or user. |

## Inspect a registered connection

Query, tables, schema, and describe accept only a registered slug. The caller never supplies a host, path, user, or password. Every inspection returns HTTP 202 with a `database.read` Operation. The CLI follows the Operation and prints its result; `--json` returns the terminal Operation with the inspection under `result`.

A query takes `sql` (at most 16,384 characters) and optional `write`. Stacked statements return `database.sql_multiple_statements`. Without `write: true`, write statements return `database.write_required`. MySQL and PostgreSQL reads run inside a `READ ONLY` transaction. SQLite runs `sqlite3 -readonly -safe`, which refuses writes, attachments, file functions, and shell commands, and cannot create a missing file.

MySQL and PostgreSQL reads accept one `SELECT`, `WITH`, `SHOW`, `EXPLAIN`, or `DESCRIBE` statement and a fixed set of pure functions. Comments, backslash escapes, dollar quoting, file export clauses, transaction controls, and other function calls return `database.query_refused` (422) before anything runs. MySQL DDL commits a read-only transaction on its own, so this check guards against it. Pass `write: true` for statements outside this read subset.

Read queries return `columns`, up to 500 `rows`, `row_count`, and `truncated` when rows or bytes hit the 64 KiB output bound. Values are text, and SQL NULL is `null`. A MySQL query with no rows returns no `columns`. Each query has a 30-second timeout, and the Gateway strips the stored password and credential-shaped text before saving the result.

| Connection | Where it runs |
| --- | --- |
| `mysql`, `pgsql` on a [Database server](/reference/database-servers) | `docker exec -i` into the server's container on its Node, logged in as the connection's user. |
| `mysql`, `pgsql` registered without a server | The `mysql` or `psql` client on the executor host. |
| `sqlite` | `sqlite3` on the linked Node as the file's owner. |
| `redis` | Not supported. Returns `database.driver_unsupported` (422). |

For SQLite, the linked Node must be active and have a WireGuard address; a record without a Node returns `database.sqlite_node_required`. Production reads run as the owning production Instance user (or the user whose home holds the path), and development reads run as the Node user.

Unknown tables fail the Operation with `database.table_missing`. Other execution errors, timeouts, or unreadable SQLite files fail with `database.query_failed`. Failure results omit SQL, credentials, and raw client diagnostics.

### Direct SQL writes

`database:query --write` starts a `database.write` Operation in the same location and client as a read. Writes are limited to 16,384 bytes and one statement. SQLite runs `sqlite3 -safe`, so `ATTACH`, `VACUUM`, file functions, and shell commands fail with `database.query_failed` and leave the file unchanged.

The result holds the affected `row_count` (`ROW_COUNT()` on MySQL, the command tag on PostgreSQL, `changes()` on SQLite) and `truncated`, without result rows. A failed or timed-out statement returns `database.query_failed`; unreadable client output returns `database.write_outcome_unknown`.

### Dump imports

[`database:import`](/cli/database/import) loads a plain or gzipped SQL dump into an empty database on a MySQL or PostgreSQL server. It opens the admitted file once as the Node's SSH user without `sudo` (the file must be regular and owned by that user), then runs a throwaway container of the server's image on the server's Docker network. The client logs in as the database owner over TCP so the server's grants confine the dump to that owner's databases.

To block client commands inside caller dumps, `psql` reads an Orbit-generated `\restrict <random key>` before the dump (dropping any outer `\restrict`/`\unrestrict` pair produced by `pg_dump`), and `mysql` runs with `--skip-commands --skip-system-command`. The Operation records `bytes_read`, `exit_code`, and `tables`, plus `database_state` and the client's error code and line on failure.

### Client commands

The `psql` and `mysql` clients have meta-commands of their own, such as `psql`'s `\c`, `\!`, and `:VAR` expansion, or `mysql`'s `system` and `source`. Because the PostgreSQL container trusts loopback connections for every user, a `\c` or shell command inside caller SQL would escalate privileges or run shell commands on the host.

Orbit prevents the client from interpreting caller SQL:

- `psql` receives the statement via `-c` so backslash commands and variables are never expanded. Reads run `BEGIN READ ONLY`, the timeout, the statement, and `ROLLBACK` as separate `-c` arguments.
- `mysql` reads statements on standard input with `--skip-commands --skip-system-command`. A MySQL client older than 8.4.6 lacks these flags and fails with `database.query_failed`.

Passwords always travel on the first line of standard input into the client's environment and never enter command arguments, files, or logs.

## Owned databases

An Instance owns every database that Orbit created for it through `database:create --server --instance` or [`instance:create --database-server`](/domains/applications#database-on-a-server). The connection stores the owner in `owner_instance_id`.

During development creation, Orbit copies a SQLite or MySQL [database clone](/domains/applications#database-clone) directly, or creates a new database on `--database-server`. Cloning a container default also clones a PostgreSQL database with a [template copy](/reference/database-servers#clone-a-default-database-for-a-container-clone) on the default's server, and gives the clone its [own Valkey user](/reference/development-containers#a-clones-valkey-user) when the default has a Valkey target. A production clone or transfer with a SQLite source returns `operation.kind_unavailable` (503) before any change.

[`instance:destroy`](/reference/instance-removal#native-order) drops each database the Instance owns, along with its test databases and unshared users, and deletes the records only after the whole removal succeeds. Deleting the record of a database on a server drops that database the same way. Before any drop, Orbit checks the complete [removal set](/reference/database-servers#remove-a-database), refuses system names and attached connections, and returns `database.in_use` (409) without mutating anything when a blocker exists. Orbit never drops a database that was only registered.

## Test databases

An owned database created for development includes a test database of the same kind, named in `test_database`.

| Driver | Test database |
| --- | --- |
| `mysql` | `<name>_test` and a recorded pool of `<name>_test_test_1..N` on the same server. The user also gets grants for any database starting with `<name>_test`. Set the pool size with `--test-processes=N` (default 8, range 1–32). |
| `pgsql` | `<name>_test` and a recorded pool of `<name>_test_test_1..N`, owned by the Instance role without `CREATEDB`. Set the pool size with `--test-processes=N` (default 8, range 1–32). |
| `sqlite` | `:memory:` |

When an Instance owns its `DB` database, [environment synchronization](/reference/environment-variables#synchronize) writes the `DB_*` keys in `.env.testing` to point at the test database using the connection's host and port. A missing file is created from `.env` with `APP_ENV=testing`; in an existing untracked file, other lines stay untouched. Orbit never writes a `.env.testing` tracked by Git, and records the test database name in Activity instead. Remove any `force="true"` overrides for `DB_*` in `phpunit.xml` so Laravel reads `.env.testing`.

For PostgreSQL parallel tests, synchronize the environment, clear any cached Laravel config, and run `php artisan test --parallel --processes=4` with a process count no larger than the recorded pool. Plain `--parallel` uses the host CPU count, which only works when that count fits the pool. Leave `--recreate-databases`, `--drop-databases`, and `--without-databases` off.

Laravel connects to `<DB_DATABASE>_test_<token>` and reuses it when reachable. If a worker database is missing or unreachable (for example during server connection exhaustion), Laravel tries to drop and recreate it, which fails because the managed role lacks `CREATEDB`. Stop the run, check your connection budget, and [restore missing members](/reference/database-servers#repair-a-postgresql-test-pool) instead of granting `CREATEDB`. Size PostgreSQL `max_connections` to cover `N × (connections per worker)` plus idle apps and admin sessions, because the pool reserves databases rather than connections.

## Add a connection on an Instance

`PUT /api/v1/instances/{instance}/database-connections/{slug}` writes a connection into the Instance's stored environment. `{instance}` is an Instance ID or exact Route domain, and the route requires an access grant to the Instance's Node.

Optional body fields:

- `prefix`: uppercase name starting with a letter, at most 32 characters (default `DB`).
- `replace`: strict boolean (default `false`).
- `shared`: strict boolean; see [Share a connection with clones](#share-a-connection-with-clones).

| Driver | Keys with prefix `DB` |
| --- | --- |
| `mysql`, `pgsql` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| `redis` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, plus `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` when stored |
| `sqlite` | `DB_CONNECTION`, `DB_DATABASE` (file path), plus `DB_USERNAME`, `DB_PASSWORD` when stored |

A SQLite connection can only attach to an Instance on its registered Node; a different or missing Node returns `database.node_mismatch` before any change.

The Gateway removes unused keys of the prefix (for example `DB_HOST` when `sqlite` replaces `mysql`). Any add that would change or remove an existing stored key returns `database.environment_conflict` (409) unless you pass `replace: true` (`--replace` in the CLI). An unchanged add is idempotent.

By default, Orbit writes the stored host and port. Managed databases store the server Node's WireGuard address and published port, and expose `lan_host` when the server Node has a LAN address. The response names the Instance, slug, prefix, written keys, host, port, whether stored configuration changed, and the key count, without exposing values.

`DELETE /api/v1/instances/{instance}/database-connections/{slug}` removes the mapping and the six keys of its prefix, leaving other keys untouched (`database.attachment_missing`, 404, if unknown). Both add and remove update stored configuration only; run [`env:sync`](/reference/environment-variables#synchronize) to write `.env`.

## Share a connection with clones

A container clone gets its own database and Valkey user and does not copy the default Instance's other connections. To share a connection such as a read-only catalogue across clones, mark it shared on the default's attachment with `shared: true` (`--shared` in the CLI). Pass `shared: false` (`--no-shared`) to remove the mark. Omitting the field leaves an existing attachment's mark unchanged.

Only `mysql` and `pgsql` connections can be shared; Valkey and SQLite return `database.shared_driver_unsupported` (422). An Instance's own database (an attachment under `DB`, the connection attached under `DB`, or a database owned by the Instance) returns `database.shared_own_database` (422), because each clone gets its own copy instead.

A container clone of a default with a shared attachment receives:

- The same connection attached under the same prefix and network, also marked shared.
- The connection's keys in its `.env`, listed under `environment.shared` in the Operation result ([details](/reference/development-containers#the-environment-of-a-clone)).
- The container's network path to the connection's host and port.

Destroying the clone removes only its attachment and leaves the shared database intact. Migrating an Instance preserves attachments and their shared marks. Host `instance:clone` does not use the shared mark.

## Choose LAN for an Instance

`instance:database:add <slug> --instance=<instance> --network=lan` selects the managed server's LAN address for that attachment. Both Nodes must be active and have an IPv4 LAN address; no Cluster membership is required. If either Node fails that check, Orbit returns `database.lan_unavailable` before writing keys. `--network=wireguard` is the default; reattach with `--network=wireguard --replace` to switch back.

Before selecting LAN on an existing server, repeat `database:server:create` to publish its LAN binding. The attachment stores its network choice, and both `.env` and `.env.testing` use it, while the connection's default host and Gateway inspection stay on WireGuard. External connections do not derive LAN endpoints; record their intended host directly. Run `env:sync` after reattaching.

## Inspect attachments with Doctor

Connection admission and database Operations validate database intent directly. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat attachment, environment, or credential checks.

## Why it works this way

### A registry, not a database manager

Recording how to reach a database lets Orbit work with external hosts as well as managed servers. Orbit only creates, clones, and drops databases on a [Database server](/reference/database-servers); deleting a registered external record never touches data.

### Tests get their own database

A test run with `RefreshDatabase` wipes the database it uses. Giving each development Instance its own test database and writing `.env.testing` protects Instance data without requiring every repository to support custom `DB_TEST_*` variables.

### One result shape for every SQL driver

Each client prints rows in its native structured format (XML for MySQL, CSV with a per-read NULL marker for PostgreSQL and SQLite), and the Gateway shapes all three into the same bounded, redacted result.

### Secrets stay off the command line

Passwords travel through protected standard input into the client's environment and never appear in command arguments, Operation history, or logs. Passing SQL via `psql -c` keeps caller text out of `psql`'s meta-command parser; see [Client commands](#client-commands).
