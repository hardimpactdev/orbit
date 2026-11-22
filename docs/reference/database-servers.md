---
title: "Database servers"
description: "How Orbit runs a MySQL, PostgreSQL or Valkey server as a Docker Node Process, keeps its admin password only in the Gateway, and creates databases and ACL users on it."
covers:
  - apps/gateway/app/Actions/DatabaseServers/**
  - apps/gateway/app/Actions/DatabaseConnections/{CreateServerDatabaseAction,CreateDatabaseUserAction,DropServerDatabaseAction}.php
  - apps/gateway/app/Domain/DatabaseServers/**
  - apps/gateway/app/Http/{Controllers/Api/DatabaseServersController.php,Requests/DatabaseServers/**}
  - apps/gateway/{app/Models/DatabaseServer.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,database/migrations/2026_12_30_000000_add_test_processes_to_database_connections.php}
  - apps/cli/app/Commands/Database/{CreateDatabaseServerCommand,ListDatabaseServersCommand,ShowDatabaseServerCommand,DestroyDatabaseServerCommand,CreateDatabaseUserCommand}.php
  - packages/php-sdk/src/{Requests,Responses}/DatabaseServers/**
  - swamp/workflows/workflow-operation-database-{server-create,server-destroy,server-close,create,destroy,user-create}.yaml
---

# Database servers

A Database server is a MySQL, PostgreSQL, or Valkey server that Orbit runs and administers as a Docker [Node Process](/reference/processes-and-schedules#owners). The Gateway stores its admin password encrypted and uses it to create databases, manage ACL users, and [clone](/domains/applications#database-clone) default Instance databases. [`database:server`](/cli/database/server-create) lists the commands.

A server's `driver` selects the engine: `mysql` runs MySQL, `pgsql` runs PostgreSQL 18, and `redis` runs Valkey. Clients of a Valkey server use the [`redis` driver](/reference/database-connections#drivers-and-fields), so the server uses the same name.

## Create a server

`database:server:create` creates the server record and its Docker Process in one command, returning HTTP 202 with an [Operation](/reference/operations). It refuses a server with an unfinished removal (`database.server_removing`) and a Node without a stored SSH host key (`swamp.ssh_target_unavailable`).

```bash
orbit database:server:create beast-mysql --node=beast
orbit database:server:create beast-valkey --node=beast --driver=redis
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--node=NODE` | required | Numeric Node ID or Node name. Must be an active Linux Node with Docker. |
| `--driver=DRIVER` | `mysql` | `mysql`, `pgsql`, or `redis`. |
| `--tag=TAG` | `8.4` (`mysql`), `18` (`pgsql`), `8.1` (`redis`) | Image tag: `mysql:<tag>`, `postgres:<tag>`, or `valkey/valkey:<tag>`. |
| `--port=PORT` | `3306` (`mysql`), `5432` (`pgsql`), `6379` (`redis`) | Port published on the Node's WireGuard address and its LAN address when present. |

Every server creation follows the [create flow](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/database/server-create.mmd). Orbit pulls the image, writes the ACL file (Valkey only), starts the container, and waits up to 60 seconds for Docker's TCP health check. It then verifies the admin login over TCP and recreates MySQL or PostgreSQL containers without the first-start password variable. Passwords travel only on standard input and never enter command arguments, workflow inputs, or files on disk.

### A MySQL server

Orbit creates a MySQL server in four steps:

1. Generates a random root password and stores it encrypted on the server record.
2. Creates a Docker Node Process named `<slug>` with image `mysql:<tag>`, port published to container port `3306`, and volume `orbit-<slug>-data` at `/var/lib/mysql`.
3. Starts the container with `MYSQL_ROOT_PASSWORD` passed on standard input via `--env-file /dev/stdin` and waits until MySQL accepts a root login over TCP.
4. Removes the password variable and recreates the container. The data volume keeps the root password.

Waiting for a TCP root login ensures initialization has finished before recreating the container. After step 4, the root password does not appear in the Process record, container environment, or `docker inspect`.

### A PostgreSQL server

Create PostgreSQL 18 on a free port:

```bash
orbit database:server:create services-postgres --node=services --driver=pgsql --port=5434
```

PostgreSQL follows the same steps with `postgres:<tag>`, command `postgres`, container port `5432`, volume `/var/lib/postgresql` (PostgreSQL 18 stores data in `/var/lib/postgresql/18/docker`), and first-start variables `POSTGRES_PASSWORD` and `POSTGRES_INITDB_ARGS=--auth-local=peer --auth-host=scram-sha-256`. Accepted tags are `18`, `18.N`, and variant suffixes of those versions; other majors and `latest` return `validation.failed`.

Each managed PostgreSQL database belongs to a `LOGIN` owner role without superuser or `CREATEDB` rights. Orbit revokes `PUBLIC` database access and `PUBLIC` schema `CREATE`. Additional users get `CONNECT` and `public` schema `USAGE`; read-only users get `SELECT` on current and future owner tables, and write users also get data-write and sequence privileges. Passwords must be printable ASCII, and Orbit sends SCRAM verifiers so plaintext passwords never enter server SQL logs.

Create application objects as the managed owner in schema `public` (custom schemas and hand-written grants are unsupported). To migrate an existing database onto a managed PostgreSQL server, dump with `pg_dump -Fc` and restore with `pg_restore --exit-on-error --no-owner --no-acl --role=<owner>`.

### Private publication

Managed Database servers bind only to private IPv4 addresses: the Node's WireGuard address and, when present, its RFC 1918 LAN address. Orbit never binds `0.0.0.0`, an IPv6 wildcard, or a Node's public address.

The private networks are the trust boundary. Ports published by Docker bypass UFW, so Orbit adds no per-port firewall rules, iptables chains, or `pg_hba.conf` IP filters; keep those private addresses unreachable from the public internet. Every managed Linux Node enables non-local binds through its [kernel policy](/reference/node-provisioning#kernel-policy), so containers starting at boot bind cleanly even before WireGuard or LAN interfaces come up.

On PostgreSQL servers created by Orbit, local sockets use `peer` authentication and every TCP connection (including loopback) requires SCRAM password authentication. Orbit's own admin commands always connect over TCP with a password, and client queries never execute client meta-commands ([Client commands](/reference/database-connections#client-commands)). MySQL servers require a client of MySQL 8.4.6 or later for queries and imports.

The stored connection host remains WireGuard; [LAN attachments](/reference/database-connections#choose-lan-for-an-instance) opt individual Instances into the LAN address when both Nodes have IPv4 LAN addresses. Repeating `database:server:create` with the original arguments repairs publication drift if a Node's address changes, and leaves an active server active if repair fails.

### A Valkey server

Orbit creates a Valkey server in four steps:

1. Generates a random admin password and stores it encrypted on the server record.
2. Writes `/data/users.acl` (mode `0600`) into volume `orbit-<slug>-data` with `user default on #<sha256 of admin password> ~* &* +@all`.
3. Creates and starts a Docker Node Process named `<slug>` with `valkey/valkey:<tag>`, volume `/data`, no environment variables, and command `valkey-server --appendonly yes --dir /data --aclfile /data/users.acl`.
4. Verifies that `default` can log in with the admin password.

The ACL file stores only SHA-256 hashes, never plaintext passwords. Valkey refuses to start if the ACL file is missing or malformed, so a damaged server stays closed. Append-only persistence keeps data across restarts.

### Retry and response

Repeating `database:server:create` with the same `--idempotency-key` returns the original Operation. After a failed Operation, a new start reuses the server record, encrypted password, Process, and volume. Passing a different Node, driver, tag, or port returns `database.server_slug_conflict`. Repeating the command on an active Valkey server [repairs it](#repair-a-valkey-server).

List and show return `id`, `slug`, `driver`, `node_id`, `process_id`, `tag`, `port`, `status`, `default_open`, and `databases_count`. Show also returns `databases`. Passwords never appear in responses.

The server's Process is an ordinary Docker Node Process that you can inspect, stop, start, or restart with [`process`](/cli/process) commands. `process:destroy` refuses it with `process.required_by_database_server`; destroy the server instead. Replacing a server's Process preserves its Docker health check.

### Update a Valkey server's image

For a `redis` server, `process:update` accepts only an image-only change to `valkey/valkey:<tag>` (for example `orbit process:update 41 --image=valkey/valkey:8.1.3`), keeping the command, `--aclfile`, and volume mounts intact. Tags must be 1 through 128 letters, digits, underscores, periods, or hyphens, starting with a letter, digit, or underscore.

Invalid input (such as supplying `user`, `start`, an empty `command`, or whitespace in the image reference) returns HTTP 422. Valid input that changes any field other than `image`, omits `image`, or names another repository returns `process.required_by_database_server` (409).

## Close an adopted server

`database:server:update <slug> --require-password` requires a password for the `default` user on an open Valkey server. Move every client to [its own ACL user](#create-an-acl-user-on-a-valkey-server) first.

```bash
orbit database:server:update beast-valkey --require-password
```

The API endpoint is `PATCH /api/v1/database-servers/{database_server}` with `{"require_password": true}`. Orbit refuses with `database.server_in_use` (409) without changing anything if a connection on the server has no password or another client is currently connected as `default`. Otherwise it updates `default` to require the admin password, runs `ACL SAVE`, disconnects other `default` sessions, and verifies that an unauthenticated `PING` returns `NOAUTH`. No option reopens passwordless access.

## Create a database on a server

`database:create --server` creates a database and owner user on a MySQL or PostgreSQL server and records the [connection](/reference/database-connections).

```bash
orbit database:create dlf-leden --server=beast-mysql --instance=60
```

Orbit derives the database name from the slug by replacing hyphens with underscores. When needed, it shortens the name with an 8-character hash suffix to fit MySQL grant limits (64 characters for databases, 32 for users) or PostgreSQL's 63-character identifier limit (50 characters for primary names with test pools). Orbit generates the owner password automatically.

Each Instance shares one owner user per server (`<project>_<instance>`), scoped strictly to databases that Instance owns and their test databases. Databases created without `--instance` get their own user named after the database. Orbit refuses existing databases or users owned by someone else with `database.name_conflict` (409), and on MySQL also refuses any name starting with another database's test prefix.

Passing `--instance` on MySQL or PostgreSQL also creates `<name>_test` and a parallel test pool `<name>_test_test_1..N` (`--test-processes=N`, default 8, range 1–32), attaches the connection under `DB`, and records the Instance as owner. The pool count is fixed at creation; see [Test databases](/reference/database-connections#test-databases). To have the database ready before Instance setup runs, pass `--database-server` to [`instance:create`](/domains/applications#database-on-a-server) instead.

PostgreSQL creation also accepts `--locale=C.UTF-8` or `--locale=C` (builtin locale provider, `TEMPLATE template0`, UTF8). Omitting it inherits the image default, and the choice is fixed at creation. MySQL, Valkey, and external registrations refuse `--locale`.

## Reach a server from a container Instance

A [container Instance](/reference/development-containers#network) can reach only its assigned database server and port. Creation adds:

- A container NIC ACL rule allowing the server's address and port.
- A UFW rule on the Instance's Node tagged `orbit:instance-<id>-database` (an input rule from the container bridge for same-Node servers, or a route rule out to WireGuard for remote servers).

## Clone a default database for a container clone

A container clone gets its own owner and database on the default Instance's server. For PostgreSQL, Orbit clones the default database inside the server container using the admin login:

1. Closes the default database to new connections and terminates active sessions, with a cleanup trap that reopens it whether the copy succeeds or fails.
2. Drops the empty clone database and runs `CREATE DATABASE <clone> TEMPLATE <default> OWNER <clone owner>`.
3. Reassigns ownership of all objects owned by the default's owner to the clone's owner.
4. Revokes `PUBLIC` database access and the default owner's `public` schema grants.

The default Instance sees refused connections briefly while the template copy runs. MySQL defaults are cloned with a dump, the same as host checkouts. Cloning onto a different server than the default's returns `instance.database_clone_unsupported`.

## Repair a PostgreSQL test pool

Repeat the original `database:create` command for an Instance-owned PostgreSQL connection with the exact same server, Instance, locale, and `--test-processes` count (mismatched arguments return `database.slug_conflict`, 409):

```bash
orbit database:create app-dev --server=beast-pg --instance=606 --test-processes=4
```

Orbit checks `<name>_test` and each recorded worker database, creates only missing members with the recorded locale and owner, and reapplies managed ownership and `public` schema grants across all recorded members. The primary database, existing member data, credentials, and attachments stay untouched. If a pool name is owned by another role or the managed role is missing, Orbit refuses with `database.name_conflict` (409) before changing anything. Stop running tests before repairing, and check your [connection budget](/reference/database-connections#test-databases) before restarting them.

## Create an ACL user on a Valkey server

On a `redis` server, `database:create --server` creates a Valkey ACL user named after the slug and records a `redis` connection for it.

```bash
orbit database:create gateway-queue --server=services-valkey --database=1 \
  --pattern='orbit-gateway:*' --pattern='orbit-gateway-horizon:*' \
  --allow=@connection --allow=@transaction --allow=@keyspace --allow=@string --allow=@list \
  --allow=@hash --allow=@set --allow=@sortedset --allow=eval --allow=time
```

| Option | Meaning |
| --- | --- |
| `--pattern=PATTERN` | Key pattern with a literal prefix (1–32 patterns, max 255 characters each). Required; repeat for multiple. |
| `--allow=RULE` | Command category (such as `@list`) or command (such as `eval`) (1–64 rules, max 128 characters each). Required; repeat for multiple. |
| `--database=INDEX` | Optional integer database index (0–999), checked against the live server's database count (16 by default: 0–15). |

Orbit generates the user's password and runs:

```text
ACL SETUSER <slug> reset on #<sha256 of password> resetchannels ~<pattern> ... -@all +<rule> ... -@admin -@dangerous -function -script [+flushdb]
ACL SAVE
```

Orbit controls the framing around your patterns and rules: pub/sub channels are cleared, and `-@admin -@dangerous -function -script` always denies admin, dangerous, `FUNCTION`, and `SCRIPT` commands even if a broad category like `@write` or `@slow` was allowed. `EVAL` remains available when explicitly allowed. `--allow=flushdb` appends `+flushdb` after the deny list so Laravel's `cache:clear` can flush its own database index; `FLUSHALL` stays denied.

Validation refuses the slug `default`, key patterns starting with `*`, `?`, `[`, `\`, `~`, `%`, or `&`, and the rules `@all` or `@scripting`. Key patterns restrict key access across all database indexes; Valkey 8 has no per-index ACL rule, and `SCAN`/`DBSIZE` still show key names and counts.

`database:user:list` shows the recorded rules Valkey reported. Passing `--instance` or cloning against a `redis` server returns `database.driver_unsupported`; container clones get their own Valkey user through [container cloning](/reference/development-containers#a-clones-valkey-user).

## Add a user

`database:user:create` adds or updates an additional user on a managed MySQL or PostgreSQL connection (for example a read-only reporting login). `database:user:list` lists users created for a connection.

```bash
orbit database:user:create dlf-leden --username=reporting --read-only
```

The CLI reads the password from piped standard input or prompts interactively; see [`database:user:create`](/cli/database/user-create). A read-only user gets `SELECT`; a regular MySQL user gets all privileges on the database, and a regular PostgreSQL user gets data-write and sequence privileges on `public`. Repeating the command rotates the password and replaces privileges.

Orbit refuses external connections (`database.server_required`, 422), `redis` servers (`database.driver_unsupported`, 422), reserved system names such as `root`, `mysql`, `sys`, `postgres`, `public`, or `pg_*` (`database.user_reserved`, 422), and usernames already owned by another connection or unrecorded account on the server (`database.name_conflict`, 409). Only one user creation runs per server at a time (`operation.target_busy`, 409). After updating grants, Orbit logs in as the new user over TCP to verify credentials and grants (`database.user_login_failed` if the check fails), then stores the password encrypted so it can be [published to Vaultwarden](/reference/vaultwarden#database-user-passwords).

### Logical replication users

Create a read-only PostgreSQL login for logical replication consumers such as PowerSync:

```bash
orbit database:user:create app --username=powersync --replication
```

`--replication` (API field `replication`, boolean, default `false`) requires a managed PostgreSQL server with live `wal_level=logical`. Non-PostgreSQL servers return `database.driver_unsupported` (422), and any other WAL level returns `database.wal_level_not_logical` (422). Orbit does not change server configuration.

The user receives `LOGIN REPLICATION`, database `CONNECT`, `public` schema `USAGE`, and `SELECT` on current and future owner tables and sequences (implying `--read-only`), without superuser, `CREATEDB`, or `CREATEROLE`. Omit `--replication` on a later call to remove the replication attribute. Application migrations own creating and altering logical publications; Orbit does not create publications or replication slots.

## How Orbit runs admin commands

Admin batches run via `docker exec -i` inside the server container, reading the admin password from the first line of standard input followed by the statements. Passwords never appear in command arguments, host environment variables, Docker exec metadata, or files on disk. Connection [reads](/reference/database-connections#inspect-a-registered-connection) and [writes](/reference/database-connections#direct-sql-writes) run the same way as the connection's own user.

## Load a dump

[`database:import`](/cli/database/import) loads a plain or gzipped SQL dump into an empty database on a MySQL or PostgreSQL server as the database's owner, without using the admin password.

## Remove a database

`database:destroy` drops the primary database, test database, recorded test pool, and any user created for the connection that no other connection on the server shares. Unrecorded names, admin logins, and system roles are never dropped. Orbit deletes the Gateway record only after every planned object is gone; if a failure interrupts removal, repeat the command to finish.

Before dropping anything, Orbit checks the complete removal set:

- **PostgreSQL:** fences new connections, then checks active sessions, replication slots (including inactive logical slots), prepared transactions, subscriptions, and external role dependencies.
- **MySQL:** checks connected clients, metadata locks on planned schemas, external stored objects whose definer is a removed user, and cross-schema foreign keys.
- **Valkey:** deletes the connection's ACL user with `ACL DELUSER` and runs `ACL SAVE`, leaving keys intact. A connection with no username (logging in as `default`) deletes only the Gateway record.

Any blocker fails the Operation with `database.in_use` (409) without dropping anything; Orbit never force-disconnects clients. Stop clients or remove blocking dependencies and retry. [`instance:destroy`](/reference/instance-removal#replication-slots) also checks PostgreSQL replication slots before admission and refuses with `instance.database_replication_slot`.

## Remove a server

`database:server:destroy <slug>` refuses with `database.server_in_use` (409) while any connection points to the server. Otherwise it marks the server as removing (blocking new database creation), runs `docker rm --force` on the recorded container name, inspects afterward to confirm no container with that name remains, and deletes the server and Process records. Because removal deletes by name before inspecting, it does not verify container ownership first and can remove a same-name replacement container. The data volume stays on disk so data survives; remove the volume manually when you want to discard its data.

A Node running a Database server cannot be removed; [Node removal](/reference/node-provisioning#remove-a-node) refuses with `node.has_database_servers`, even offline.

## Check a server with Doctor

Database Operations validate server configuration, endpoints, and credentials directly. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat those checks; its Process family inspects the server container state.

## Repair a Valkey server

Repeat the server's `database:server:create` command with its original arguments to rebuild an active Valkey server from Gateway records:

```bash
orbit database:server:create beast-valkey --node=beast --driver=redis
```

Orbit rewrites `/data/users.acl` from `default` and every recorded user, then restarts the container once so Valkey loads the file. Keys stay intact, and clients of deleted users are disconnected.

## Errors

Database server commands return these error codes:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_slug_conflict` | 409 | Another server has the slug, or retry arguments differ. |
| `database.server_port_in_use` | 409 | Another Process on the Node publishes the port. |
| `database.server_removing` | 409 | The server is currently being removed. |
| `database.server_busy` | 409 | Another server Operation is already in progress. |
| `database.server_in_use` | 409 | A connection still points to the server, another removal or ACL change is running, or closing would lock out a client. |
| `database.server_inactive` | 409 | The server is not active yet. Repeat `database:server:create` to finish it. |
| `database.server_connection_managed` | 409 | An update would replace a managed connection's identity or credentials. |
| `database.required_by_analytics` | 409 | Analytics uses this connection; move the role first. |
| `database.in_use` | 409 | A session, replication object, prepared transaction, or user dependency blocks removal. |
| `database.name_conflict` | 409 | The name is taken on the server, a test member has a foreign owner, or the managed role is missing. |
| `database.slug_conflict` | 409 | The connection slug exists and the request is not an identical PostgreSQL pool retry. |
| `process.required_by_database_server` | 409 | `process:destroy` names a server Process, or a Valkey Process update violates the [image-update rule](#update-a-valkey-servers-image). |
| `database.server_required` | 422 | The connection has no server. |
| `database.driver_unsupported` | 422 | The driver does not support this operation (for example `instance_id` or `database:user:create` on `redis`, or `--replication` outside `pgsql`). |
| `database.wal_level_not_logical` | 422 | PostgreSQL server `wal_level` is not `logical`. |
| `database.lan_unavailable` | 422 | The server or consumer Node is not eligible for LAN endpoints. |
| `database.acl_rules_invalid` | 422 | Valkey refused a key pattern or rule. |
| `process.wireguard_ip_missing` | 422 | The target Node has no WireGuard IP address. |
| `database.server_image_pull_failed` | 502 | Docker could not pull the server image. |
| `database.server_start_failed` | 502 | Docker could not create or start the server container. |
| `database.server_unhealthy` | 502 | The container reported unhealthy or exited before becoming healthy. |
| `database.server_login_failed` | 502 | The admin login failed after the container became healthy. |
| `database.server_scrub_failed` | 502 | Docker could not recreate the container without its first-start password. |
| `database.server_inspect_failed` | 502 | Docker could not list the server's containers. |
| `database.server_remove_failed` | 502 | Docker could not stop or remove a server container. |
| `database.server_remove_incomplete` | 502 | A container of the server's Process still exists after removal. |
| `database.owner_login_failed` | 502 | The new owner could not log in after creation. |
| `database.user_login_failed` | 502 | The user could not log in or did not match the requested attributes and grants. |
| `database.create_outcome_unknown` | 502 | Creation ended without a readable result; retry the command. |
| `database.server_create_failed` | 502 | Creation ended before reaching the admin login check. |
| `database.valkey_acl_failed` | 502 | Docker could not write the Valkey ACL file into the volume. |
| `database.destroy_incomplete` | 502 | A planned database or user still exists after drops; retry the command. |
| `database.destroy_outcome_unknown` | 502 | Removal ended without a readable result; retry the command. |
| `database.server_command_failed` | 502 | An admin command on the server failed. Messages omit SQL output, rules, and passwords. |

## Why it works this way

### The root password lives only in the Gateway

MySQL and PostgreSQL images read their root password variable only when initializing an empty data directory. Passing it once on standard input and recreating the container keeps the password out of the Process record and `docker inspect`.

### One user per Instance

Scoping each Instance's user to its own databases prevents one Instance or clone from reading another's data.

### A server is not a connection

A connection describes one database that an Instance uses; a server holds the admin credentials that create databases. Keeping them separate avoids giving one record two meanings.

### Valkey starts only from Orbit's ACL file

With an ACL file, Valkey ignores `requirepass`, and starting without `--aclfile` or a `default` line opens the server without authentication. Orbit writes the ACL file before first start and restricts `process:update` to image-only changes within `valkey/valkey:<tag>` so an update cannot drop `--aclfile` or the volume mount. Any open adopted server stays open until [closed](#close-an-adopted-server).

### One Valkey user is one connection

Valkey ACL users restrict keys and commands rather than databases, and each client connects with one username and password. Creating the user and connection together keeps Valkey user management atomic.

### Orbit owns the frame of each user

Accepting only key patterns and allowed rules lets Orbit enforce `resetchannels` and the final deny of `@admin`, `@dangerous`, `FUNCTION`, and `SCRIPT` so user input can never grant admin or server-wide flush commands.

### A repair rebuilds from the records

Because the Gateway holds the admin password and every recorded user's password and rules, repeating `database:server:create` rebuilds `/data/users.acl` cleanly without a separate repair command.

### Private networks are the boundary

Binding explicitly to WireGuard and LAN IPv4 addresses prevents Docker from exposing database ports on public interfaces while keeping private networking simple. WireGuard stays the default so existing consumers do not shift unexpectedly when LAN addresses appear.

### PostgreSQL workers exist before tests start

PostgreSQL has no database prefix grant, and granting `CREATEDB` would let an Instance create databases outside its managed set. Pre-creating a bounded pool owned by the Instance role lets stock Laravel parallel tests reuse worker databases and run `migrate:fresh` without `CREATEDB` or custom test runners.

### Check the whole database set before removal

Checking active sessions, replication slots, prepared transactions, and role dependencies before any drop avoids partial removals and never force-disconnects clients. On PostgreSQL, Orbit fences `ALLOW_CONNECTIONS` and journals each database's original setting in `orbit_admin.database_removal_fences` so a failed removal restores every surviving database's exact state.
