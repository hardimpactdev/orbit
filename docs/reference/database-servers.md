---
title: "Database servers"
description: "How Orbit runs a MySQL, PostgreSQL or Valkey server as a Docker Node Process, keeps its admin password only in the Gateway, and creates databases and ACL users on it."
covers:
  - apps/gateway/app/Actions/DatabaseServers/**
  - apps/gateway/app/Actions/DatabaseConnections/{CreateServerDatabaseAction,CreateDatabaseUserAction,DropServerDatabaseAction}.php
  - apps/gateway/app/{Domain,Infrastructure}/DatabaseServers/**
  - apps/gateway/database/migrations/2026_12_30_000000_add_test_processes_to_database_connections.php
  - apps/gateway/app/Http/{Controllers/Api/DatabaseServersController.php,Requests/DatabaseServers/**}
  - apps/gateway/{app/Models/DatabaseServer.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/cli/app/Commands/Database/{CreateDatabaseServerCommand,ListDatabaseServersCommand,ShowDatabaseServerCommand,DestroyDatabaseServerCommand,CreateDatabaseUserCommand}.php
  - packages/php-sdk/src/{Requests,Responses}/DatabaseServers/**
---

# Database servers

A Database server is a MySQL, PostgreSQL or Valkey server that Orbit runs and administers. It runs as a Docker [Node Process](/reference/processes-and-schedules#owners), and the Gateway keeps its admin password. Orbit uses that password to create a database or an ACL user, and to [clone](/domains/applications#database-clone) the default Instance's MySQL database. [`database:server`](/cli/database#orbit-databaseservercreate) lists the commands.

A server's `driver` names its kind. A `mysql` server runs MySQL, a `pgsql` server runs PostgreSQL 18, and a `redis` server runs Valkey. A Valkey server's clients use the [`redis` driver](/reference/database-connections#drivers-and-fields), so the server uses the same name.

## Execution

Database writes that change a Node return an [Operation](/reference/operations) with HTTP 202. The Gateway validates the request and records intent. Swamp runs the steps and reports proven results, including Valkey readiness, ACL writes, save failures and recovery. The CLI follows the Operation; use `--idempotency-key` to retry the same start after a lost response. List, show, tables, schema, describe, and read-only queries stay in the Gateway.

Retained Instance removal finishes its database work synchronously with the existing SQL plans, removal locks and ownership checks. MySQL removal accepts stored Activity receipts that prove creation without passwords, proven child receipts or explicit operator adoption. Removal refuses system databases (`mysql`, `sys`, `performance_schema`, `information_schema`, `postgres` and names beginning with `template`), including any test prefix that could match them. The same check applies before retained synchronous removal probes and to expanded database names. SQL create and clone reset prepare work for Operations; they have no synchronous execution helper. SQLite seed callers keep their synchronous agent bindings.

The Gateway resolves stored database credentials only when it invokes an authorized named command. Operations and Swamp receipts contain references, never passwords. Removal deletes the managed record only after the Node confirms the effect.

## Create a server

`database_server.create` records Server and Process intent and directly starts `operation-database-server-create` on the admitted Node. Swamp performs local initialization, readiness, secret removal and Valkey checks; the Gateway only validates records and projects the result. The initializer environment, admin password, ACL file and ACL user data use the shared private-material API. Public Operation input and native output omit these bytes. Newly reserved Process records store an empty environment; successful projection removes legacy initializer fields. The old kind is absent from polled registration and scheduled discovery. Rollback reverts the kind PR.

`database:server:create` creates the server and its Process in one command. It refuses a server with an unfinished removal intent. A Valkey repair also refuses a changed default-user policy. An old open repair never reopens a closed default user.

```bash
orbit database:server:create beast-mysql --node=beast
orbit database:server:create beast-valkey --node=beast --driver=redis
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--node=NODE` | required | Numeric Node ID or Node name. The Node must be an active Linux Node with Docker. |
| `--driver=DRIVER` | `mysql` | `mysql`, `pgsql` or `redis`. |
| `--tag=TAG` | `8.4` for mysql, `18` for pgsql, `8.1` for redis | Image tag. The image is `mysql:<tag>`, `postgres:<tag>` or `valkey/valkey:<tag>`. |
| `--port=PORT` | `3306` for mysql, `5432` for pgsql, `6379` for redis | Port published on the Node's WireGuard address and its LAN address when present. |

### A MySQL server

The Gateway records the server and protected command intent. Swamp creates a MySQL server in this order:

1. It generates a random root password and stores it encrypted on the server record.
2. It creates a Docker Node Process named `<slug>` with image `mysql:<tag>`, the port published on the Node's WireGuard address to container port `3306`, and the volume `orbit-<slug>-data` for `/var/lib/mysql`.
3. It starts the Process with `MYSQL_ROOT_PASSWORD` set and waits until MySQL accepts the root login.
4. It removes the variable from the Process and recreates the container. The data volume keeps the password.

Until password removal succeeds, an initialization retry injects the same recorded password into the container environment again. After step 4, the root password exists only encrypted in the Gateway. It is not in the Process record, the container environment, `docker inspect`, or a file on the Node. Orbit waits for the root login over TCP inside the container, which only the final MySQL server accepts, so it never recreates the container while MySQL still initializes its data directory.

### A PostgreSQL server

Create PostgreSQL 18 on an explicit free port:

```bash
orbit database:server:create services-postgres --node=17 --driver=pgsql --port=5434
```

PostgreSQL follows the MySQL steps with `postgres:<tag>`, command `postgres`, container port 5432, and first-start variable `POSTGRES_PASSWORD`. The default tag is `18`; accepted tags are `18`, `18.N`, and these versions with an image variant suffix. Other majors and `latest` return `validation.failed`. The named volume mounts at `/var/lib/postgresql`, because PostgreSQL 18 stores data in `/var/lib/postgresql/18/docker`. The pre-18 mount `/var/lib/postgresql/data` is wrong for this image. Readiness runs an authenticated TCP SQL query; the image's temporary initialization server is socket-only. A retry reuses the encrypted password, Process and volume, and finishes password removal before returning active.

A PostgreSQL database belongs to its LOGIN owner role. The role has no superuser or CREATEDB rights. Orbit revokes PUBLIC database privileges and PUBLIC schema CREATE. Additional users have CONNECT and public-schema USAGE. A read-only user gets SELECT on current tables and owner default privileges for future tables; a write user also gets data-write and sequence privileges. Repeating user creation revokes rights on current tables and sequences and owner defaults before granting again. PostgreSQL passwords must be printable ASCII; the Gateway sends SCRAM verifiers to avoid plaintext SQL log entries.

Create application objects as the managed owner. Orbit-managed databases use only Orbit-managed grants, without hand-written GRANT/REVOKE SQL or bespoke roles. Managed grants cover schema `public`; custom schemas are unsupported. A PostgreSQL database owned by an Instance includes a fixed pool of [test databases](/reference/database-connections#test-databases). The managed owner keeps `NOCREATEDB`.

[Move PostgreSQL databases](/reference/postgresql-migration) gives the dump, restore, verification and rollback procedure. Creating a server does not adopt or upgrade any existing Process.

### Private publication

Managed Database servers serve clients inside the Orbit network only. All three drivers publish one explicit IPv4 Docker binding on the Node's WireGuard IP and a second on its LAN IP when present. Publication requires RFC 1918 private IPv4 addresses. Orbit never binds `0.0.0.0`, an IPv6 wildcard, or the Node's public address. Services also has the public interface 85.9.217.155; that address must never appear in a Database server's bindings.

The private networks are the trust boundary. Clients that can reach those private addresses have network access to the ports. Ports published by Docker bypass UFW. Orbit adds no database port firewall, iptables chain, UFW hook, Docker service drop-in or PostgreSQL source rules. PostgreSQL keeps the image's stock `pg_hba.conf` defaults and SCRAM password authentication. Operators must keep these private addresses unreachable from the public internet.

The stored connection host stays WireGuard. Explicit LAN selection requires only that the server and consumer Nodes are active and each has an IPv4 LAN address. [LAN attachments](/reference/database-connections#choose-lan-for-an-instance) select LAN for an active consumer in the private network. A shared Cluster is not required.

Existing servers keep their publications until an explicit create retry. That retry enables private publication and SQL Doctor checks for the selected server. Existing Valkey ACL checks still run. Node provisioning and removal do not contact database hosts or install database filters. When a server address changes, retry creation before switching clients. A retry can recreate an active MySQL container to repair publication drift.

Doctor reports `database_server.publication_mismatch`, `database_server.login_failed`, and `database_server.initialization_secret_present` in the `database_server` family. Repeat creation repairs publication drift. A failed SQL repair keeps an active server active and records its failed step.

### A Valkey server

Swamp creates a Valkey server from Gateway intent in this order:

1. It generates a random admin password and stores it encrypted on the server record.
2. The agent's `database.write-valkey-acl` writes the ACL file `/data/users.acl` into the volume `orbit-<slug>-data` with a short-lived container, reading the file from standard input. The file has one line, `user default on #<sha256 of the admin password> ~* &* +@all`.
3. It creates and starts a Docker Node Process named `<slug>` with image `valkey/valkey:<tag>`, the volume at `/data`, and no environment.
4. It waits until the user `default` can log in with the admin password.

The Process publishes its port on the Node's WireGuard address to container port `6379`, and runs `valkey-server --appendonly yes --dir /data --aclfile /data/users.acl`. `default` is the admin user, and it always has the Gateway's password. The ACL file holds a SHA-256 hash of it, never the password. Valkey refuses to start when the ACL file is missing or has a wrong line, so a damaged server stays closed. The append-only file in the volume keeps the data across a restart.

### Retry and response

A retry with the same `--idempotency-key` returns the recorded Operation, including its terminal result. After a failed Operation, a new start reuses the server, encrypted password, Process and volume. A request with another Node, driver, tag or port returns `database.server_slug_conflict`. A new start for an active Valkey server [repairs the server](#repair-a-valkey-server).

Write endpoints return HTTP 202 with an Operation. List and show return `id`, `slug`, `driver`, `node_id`, `process_id`, `tag`, `port`, `status`, and `databases_count`. Show also returns `databases`, `failed_step` and `error_code`. Passwords never appear in either response.

The server's Process is an ordinary Docker Node Process: read its logs, stop it, start it, or restart it with the [`process`](/cli/process) commands. `process:destroy` refuses it with `process.required_by_database_server`; remove the server instead. `process:update` refuses the Process of a `redis` server with the same code, so the command always keeps `--aclfile`.

## Adopt a Valkey Process

`database:server:create` with `--process` and `--connection` brings an existing Valkey Process under Orbit, without a new volume and without a data move. The option exists only to adopt the `valkey` Processes on `services` and `beast` ([ADR 0221](/decisions/0221-run-valkey-database-servers-with-orbit-owned-acl-users)). A later version removes it.

```bash
orbit database:server:create services-valkey --node=services --driver=redis --process=162 --connection=services-valkey
```

The Gateway checks recorded Process and connection ownership before admitting adoption. The Node checks live credentials, ACLs and persistence before writing its ACL file or changing the container, including when the original command already has `--aclfile`. Either check refuses with `database.server_adoption_refused` unless all of these hold:

- The driver is `redis`, and the Process is a Docker Process of that Node with a `valkey/valkey` image and a volume at `/data`.
- The Process publishes container port `6379` on the Node's WireGuard address. That host port becomes the server's port.
- No server owns the Process yet.
- The live ACL contains only `default`, with all rights and exactly the selected password, or `nopass` without password hashes.
- The effective configuration uses `/data`, append-only persistence, `appendonlydir`, `appendonly.aof`, `dump.rdb`, and 16 databases. Adoption refuses other values before it writes a file.
- The connection is a `redis` connection to that address and port.
- The connection logs in as `default`, with its password or without a password.

The adoption never locks out a client that works today:

- When the connection has a password, that password becomes the admin password, and the ACL file's `default` line holds its hash.
- When the connection has no password, the Gateway generates the admin password. The ACL file's `default` line is `user default on nopass ~* &* +@all`.
- A server adopted without a password stays as open as it was until you [close it](#close-an-adopted-server).

Then Swamp invokes the named ACL command to write `/data/users.acl` into the Process's volume, and replaces the Process specification with the one above. The Process keeps its ID, name, volume, and port. It waits for the admin login, and links the connection to the server. Valkey restarts once and reads its append-only file again, so its data stays. The connection's clients keep logging in as `default` as before.

## Close an adopted server

`database:server:close` gives `default` the admin password, so a client without a password gets no access. Run it after each client of an open server has [its own user](#create-an-acl-user-on-a-valkey-server).

```bash
orbit database:server:close beast-valkey
```

Swamp runs the named Valkey command for `ACL SETUSER default resetpass #<sha256 of the admin password>` and `ACL SAVE`. It refuses with `database.server_in_use` (409) and changes nothing while either of these holds:

- A connection on the server has no password. Its clients would be locked out. Destroy it, or move its clients first.
- A client other than the Gateway is logged in as `default`. The receipt contains only the fixed refusal code.

After saving the password, the Node program revokes every other `default` session, including one accepted between the client check and the password change. A retry repeats the save and revocation before it records closure. A client that is not connected at that moment is not seen, so move every client before you close the server. Like adoption, the command exists only for the two adopted Processes.

## Create a database on a server

`database:create` with `--server` creates a database and an owner user on a MySQL or PostgreSQL server, then records the [connection](/reference/database-connections).

```bash
orbit database:create dlf-leden --server=beast-mysql --instance=60
```

The database name comes from the slug, with hyphens replaced by underscores. On MySQL, a database name longer than 64 characters, or a user name longer than 32, ends in an underscore and an 8-character hash. PostgreSQL names are limited to 63 ASCII characters and use the same hash suffix when shortened. Orbit generates the owner password.

On MySQL and PostgreSQL, each Instance has one owner user on a server, named `<project>_<instance>` like its databases. A second database for the same Instance reuses that user and its password. That user can reach only the databases the Instance owns and their test databases. A database created without `--instance` gets its own user, named like the database. The connection gets the server's driver, the server's Node address and port, and a link to the server.

Orbit refuses a name with `database.name_conflict` when the server already has that database or user. MySQL also refuses a name that starts with another database's test database name. PostgreSQL checks exact names against the primary database and the recorded test pool. So one Instance's user never reaches another database through its test database grant. A failed Operation keeps its reserved connection and encrypted credentials. An identical new start reuses them; changed requests remain conflicts.

On MySQL and PostgreSQL, `--instance` also creates the test database `<name>_test`, attaches the connection to the Instance under prefix `DB`, and records the Instance as the owner. [Test databases](/reference/database-connections#test-databases) describes how tests use it.

PostgreSQL also creates `<name>_test_test_1` through `<name>_test_test_N`, all owned by the same managed role. `--test-processes=N` sets N from 1 to 32; omission uses 8. The connection records `test_processes`. This option requires a PostgreSQL server and `--instance`; other combinations are refused. The primary name is limited to 50 characters with the same hash suffix, so the longest worker name fits PostgreSQL's 63-character limit. The selected locale applies to the primary database and every test database.

Creation checks every name before mutation. Failure keeps its reserved intent for a convergent retry; destruction drops only the recorded pool and leaves unrecorded names alone. The count is fixed at creation; create a new connection to change it.

`database:create --instance` needs an existing Instance, so it comes after that Instance's setup steps. To have the database before setup runs, for MySQL pass the server to [`instance:create --database-server`](/domains/applications#database-on-a-server) instead.

PostgreSQL database creation accepts `--locale=C.UTF-8` or `--locale=C`. Both use PostgreSQL 18's builtin locale provider, `TEMPLATE template0` and UTF8 encoding. Omitting the option inherits the image default. The connection records the selected locale; it cannot be changed after creation. MySQL, Valkey and external registration refuse this option.

## Repair a PostgreSQL test pool

Repeat the original `database:create` request for an Instance-owned PostgreSQL connection. The server, Instance, locale and worker count must match creation, including defaults. A different request returns `database.slug_conflict` (409).

```bash
orbit database:create app-dev --server=beast-pg --instance=606 --test-processes=4
```

Orbit checks the recorded `<name>_test` and exact worker names as admin, then creates only missing members with the recorded locale and managed Instance ownership. It reapplies managed ownership and public-schema grants and revokes PUBLIC database access on every recorded member, including existing ones. This completes a repair interrupted after CREATE but before the grants.

The primary database, member data and identities, credentials, attachments and unrecorded names stay unchanged. A primary database that is missing is not recreated by pool repair. Existing pool names owned by another role or a missing managed role return `database.name_conflict` (409) before any change; Orbit never adopts a foreign database. Repair and database removal share the server lock.

A successful retry returns an Operation for the same connection. A failure keeps the connection record and any members already restored, so repeat the same request to finish. Stop the affected test run before repair. Repeating repair on a complete pool converges the same grants without recreating databases. Use [Doctor](#check-a-server-with-doctor) to find missing members, and check the [connection budget](/reference/database-connections#test-databases) before restarting tests.

## Create an ACL user on a Valkey server

On a `redis` server, `database:create` with `--server` creates one Valkey ACL user and records a `redis` connection that logs in as that user. The user can reach only the keys that match its patterns, with only the commands it is allowed.

```bash
orbit database:create gateway-queue --server=services-valkey --database=1 \
  --pattern='orbit-gateway:*' --pattern='orbit-gateway-horizon:*' \
  --allow=@connection --allow=@transaction --allow=@keyspace --allow=@string --allow=@list \
  --allow=@hash --allow=@set --allow=@sortedset --allow=eval --allow=time
```

| Option | Meaning |
| --- | --- |
| `--pattern=PATTERN` | A key pattern, such as `orbit-gateway:*`. Required. Repeat it for more patterns. |
| `--allow=RULE` | A command category, such as `@list`, or a command, such as `eval`. Required. Repeat it for more rules. |
| `--database=INDEX` | The database index that the connection records for its clients. Optional. Must be below the live server's database count. |

The Gateway checks an explicit index against the live server before it creates or updates a connection. An out-of-range index returns `validation.failed`. New servers have 16 databases, numbered 0 through 15.

The user is named after the slug. Orbit generates its password and runs:

```text
ACL SETUSER <slug> reset on #<sha256 of the password> resetchannels ~<pattern> ... -@all +<rule> ... -@admin -@dangerous -function -script
ACL SAVE
```

Orbit adds every part except the patterns and the rules. So a user never has pub/sub channels or another user's password. The final `-@admin -@dangerous` removes every admin and dangerous command, also one that a category such as `@keyspace` holds. The final `-function -script` removes the `FUNCTION` and `SCRIPT` commands, such as `FUNCTION FLUSH` and `SCRIPT FLUSH`. They act on the whole server, and `@write` and `@slow` hold some of them. `EVAL` stays available when a rule allows it. No user can change the ACL or the server's configuration.

The Gateway refuses these inputs with `validation.failed`:

- A key pattern that starts with `*`, `?`, `[`, or `\`. Each pattern needs a literal key prefix.
- The rule `@all` or `@scripting`. A user gets only the categories and commands its clients need.

The connection gets driver `redis`, the server's Node address and port, the `--database` index, the username `<slug>`, the encrypted password, and a link to the server. Orbit also records the user with the rules that Valkey reports, so [Doctor](#check-a-server-with-doctor) can compare them. `database:user:list` shows those rules.

The slug `default` is refused. A user that the server already has, and that Orbit did not record, returns `database.name_conflict`. A rule that Valkey does not know fails with `database.acl_rules_invalid`, without native diagnostics. A save failure removes the unsaved new user. The reserved connection and encrypted intent remain for an identical retry. Another public Operation on the server returns `operation.target_busy`.

`database:update` can change the database index, but cannot change a managed Valkey connection's driver, Node, address, port, username, or password. Such a change returns `database.server_connection_managed` (409). Create a new connection and move its clients instead. Core applies this validation through the [typed connection update hook](/reference/extensions#plug-in-points). Connection removal runs every removal guard before it deletes an ACL user or its records.

Key patterns limit keys, not the database index. Valkey 8 has no ACL rule for an index, so a user can select any index, and its patterns apply in each one. `SCAN` and `DBSIZE` show the names and the count of all keys in an index, but no values. A user that can write can also fill the server's memory, because Orbit sets no memory limit.

`--instance`, [`instance:create --database-server`](/domains/applications#database-on-a-server), and the Instance database clone refuse a `redis` server with `database.driver_unsupported`.

## Add a user

`database:user:create` adds or updates a user on a MySQL or PostgreSQL server, for example a read-only user in production. `database:user:list` lists the users that Orbit created for a connection.

```bash
orbit database:user:create dlf-leden --username=reporting --password=SECRET --read-only
```

A read-only user gets `SELECT` on the database. A regular MySQL user gets all database privileges; a regular PostgreSQL user gets the data-write grants described above. Running the command again sets the password and replaces the privileges. A connection without a server returns `database.server_required` (422). A connection on a `redis` server returns `database.driver_unsupported` (422): on Valkey, each user is a connection of its own. The user of a connection on the same server returns `database.name_conflict` (409), so the command never changes the password that a connection uses. The Gateway stores the user's password encrypted, so it can [publish it to Vaultwarden](/reference/vaultwarden#database-user-passwords). No response returns it.

### Logical replication users

Create a managed read login for a logical replication consumer such as PowerSync.

```bash
orbit database:user:create app --username=powersync --password=SECRET --replication
```

`--replication` (API field `replication`, boolean, default false) requires a managed PostgreSQL server with live `wal_level=logical`. Orbit checks it before changing the role or grants. It refuses another driver with `database.driver_unsupported` (422) and another WAL level with `database.wal_level_not_logical` (422). Orbit does not change server configuration.

The user has `LOGIN REPLICATION`, database `CONNECT`, public-schema `USAGE`, and `SELECT` on all current tables and sequences. Default privileges give the same reads on future objects created by the connection's managed owner. Replication implies the read-only grants; adding `--read-only` is allowed and has the same result. The role has no superuser, CREATEDB, or CREATEROLE attribute. PostgreSQL's replication attribute is server-wide; publications and the database grants determine which application tables the consumer reads.

Repeating the command sets the password and replaces privileges and owner defaults. Omit `--replication` on a later call to remove the replication attribute. The same name-conflict rules apply: Orbit refuses any connection owner on the server, or an existing PostgreSQL role outside this connection. Orbit does not adopt hand-created replication roles. Use an unused name when moving an existing client to managed credentials.

User create returns an Operation. Its completion records the credentials and grants. List responses include the recorded `replication` boolean. Doctor compares the live PostgreSQL role's replication attribute with that record and reports `database_server.replication_mismatch` when it differs or the role is missing. This check runs for running PostgreSQL servers with recorded users, including servers that have not opted into private endpoint checks. A role cannot be inspected without a successful admin command; that failure reports `database_server.inspection_failed`.

Application migrations create and alter logical publications and select their tables. Orbit does not create publications or replication slots.

## How Orbit runs admin commands

On a MySQL server, every admin command runs `mysql` or `mysqldump` inside the server's container with `docker exec`, through the [Node agent](/reference/node-agent#commands)'s `database.server-admin`. The root password travels in the agent's protected input, and the agent hands it to `docker exec` as `MYSQL_PWD` through the environment. The SQL follows it on standard input. Neither appears as an argument, in a file, or in a log.

For Valkey writes, Swamp invokes the fixed `database.valkey` program on the server Node. The Gateway resolves its credentials after authorization. The program connects to the recorded private address, checks every reply, and returns only fixed result fields and refusal codes. A user's password goes to Valkey only as a SHA-256 hash. A separate named command writes the ACL file during creation or [repair](#repair-a-valkey-server). It receives the file on standard input and writes it through a short-lived container that mounts the volume. The file holds only password hashes.

## Remove a database

`database:destroy` of a connection on a MySQL server drops the database, every database whose name starts with its test database name, and each user Orbit created for it that no other connection on the server uses. On PostgreSQL it drops only the primary, test database, and recorded worker pool. It deletes the record only after every database and unshared user is gone. A failed drop keeps the record; a repeat tolerates missing objects and finishes the remaining drops.

Gateway admission preflights the full removal set before it records any remote drop. Each server batch runs as its own Operation with immutable intent. A public Operation holds its server target until it ends. PostgreSQL also serializes journal and drop work with the existing server advisory lock. Swamp runs the admission restoration descriptor after a failed drop.

Before the first drop, Orbit checks the whole removal set. PostgreSQL sessions, replication slots (including inactive logical slots), prepared transactions, and subscriptions block removal. Dependencies of an unshared role outside the set also block it, including ownership and grants in another database or on a shared object. MySQL checks connected clients, metadata locks on the planned schemas (including transactions that use qualified table names without a default database), stored objects outside the set whose definer is a user being removed, and incoming cross-schema foreign keys. Cross-schema references between planned databases also block; remove those constraints first.

PostgreSQL removal closes new client admission across the whole planned set with `ALLOW_CONNECTIONS false`, then checks blockers again before any drop. Existing clients stay connected and block removal. The fence remains through the drops. On refusal or failure, Orbit restores each surviving database's exact prior `ALLOW_CONNECTIONS` value; it never changes `CONNECTION LIMIT`.

The admin database `postgres` keeps the original admission values and database OIDs in the admin-only `orbit_admin.database_removal_fences` table. Journaling and fencing commit together. A crash before commit rolls both back; a crash after commit retains the journal for retry. Retrying either completes removal or restores admission before returning a refusal. A changed database OID stops recovery instead of changing a replacement database.

PostgreSQL advisory locks serialize journal changes and protect the final check and drops in one admin session. A lost session releases its lock, so a crash does not leave a timed lock blocking retry. A failed restore keeps the journal and connection records and returns a server error; retry the removal to recover.

A blocker returns `database.in_use` (409), names the affected databases or users, and leaves databases, users, attachments, and records unchanged. Stop clients and remove or move the blocking dependencies, then retry. Orbit never force-disconnects clients. A server or transport failure after dropping starts can still interrupt removal; retain the record and repeat the same command to finish.

On a Valkey server, `database:destroy` deletes the connection's ACL user with `ACL DELUSER` and runs `ACL SAVE`. The user's keys stay. A connection without a username, such as one linked by adoption, logs in as `default`, so destroying it deletes only the record.

## Remove a server

`database:server:destroy <slug>` refuses with `database.server_in_use` (409) while a connection points to the server. Otherwise it removes the Process and the server record. Admission marks the server as removing, so new database creation refuses an inactive server. It keeps the volume, so the data survives. Remove the volume on the Node by hand when its data must go.

`database:server:destroy` records one intent and starts `operation-database-server-destroy`. The owning Node removes only the admitted Process containers through the pinned agent with sudo. Its named data volume stays intact. A server without a Process uses a native no-op step. The Gateway validates that no connections remain and deletes the Server and Process records only after a successful native result. There is no Gateway execution relay or child Operation. Rollback reverts the cutover PR.

A new Valkey server with the slug of a removed one finds the old volume. It writes a new ACL file there, so the old users are gone, but the old keys stay.

A Node that runs a server cannot be removed. [Node removal](/reference/node-provisioning#remove-a-node) refuses it with `node.has_database_servers`, also offline.

## Check a server with Doctor

The [Doctor](/cli/doctor) family `database_server` checks Valkey servers, SQL servers that opted into private endpoint checks, and PostgreSQL servers with recorded users or test databases. The Process must have the desired state `running`. It connects as the Gateway does and reports drift when:

| Code | Meaning | Remedy |
| --- | --- | --- |
| `database_server.test_database_missing` | A recorded PostgreSQL serial test database or worker is missing. The issue names the connection and member. | [Repair the pool](#repair-a-postgresql-test-pool). |
| `database_server.open` | A client without a password can run a command. | On an adopted server, [close it](#close-an-adopted-server) after its clients have moved. On another server, [repair it](#repair-a-valkey-server). |
| `database_server.acl_file_missing` | Valkey does not run with the ACL file `/data/users.acl`. | [Repair the server](#repair-a-valkey-server). |
| `database_server.acl_mismatch` | The live users differ from the recorded users. | [Repair the server](#repair-a-valkey-server). |
| `database_server.inspection_failed` | Doctor could not connect, or `default` could not log in with the admin password. | Check that the Node and the server's Process run, then [repair the server](#repair-a-valkey-server). |

The recorded users are `default` with the admin password and all rights, and one user for each connection on the server that has a username, with its password and rules. A user that someone added by hand is drift too.

Doctor compares only the rule tokens Orbit sets. Payload sanitization flags belong to Valkey and do not count as drift. The order of flags, key patterns, and channel rules does not matter. The order of command rules does.

An adopted server that is not closed yet reports only `database_server.open`, because Doctor compares its `default` only after the close. The report names no user, rule, or hash. SQL servers that opted in get the [login, initialization-secret and publication checks](#private-publication). PostgreSQL servers with recorded users also get the [replication attribute check](#logical-replication-users). Running PostgreSQL servers with recorded test databases get the pool membership check even before endpoint opt-in. A failed admin query reports `database_server.inspection_failed`, not missing members.

## Repair a Valkey server

Run the server's `database:server:create` command again to make an active Valkey server match its records. The arguments must match the server, as for any [retry](#retry-and-response).

```bash
orbit database:server:create beast-valkey --node=beast --driver=redis
```

Gateway admission reads current state and prepares these Swamp steps:

1. It checks that `default` logs in with the admin password and that Valkey runs with `/data/users.acl`.
2. If either check fails, or admin commands cannot restore the ACL, it rebuilds the file and restarts the Process.
3. It runs `ACL SETUSER <name> reset <rules> #<hash>` for each recorded user that is missing or differs. It runs `ACL DELUSER` for each other user, except the `default` of an open server.
4. It runs `ACL SAVE`.

Rebuilding the file repairs a server that cannot start because the file is lost. The Gateway waits for the admin login before it retries ACL commands. It uses the same program as creation. The file holds `default` and one line per recorded user, `user <name> #<sha256 of the password> <rules>`. The restart uses [`process:restart`](/cli/process).

An active server reconciles its recorded users and saves the ACL; the effect is safe to repeat. Keys stay. A client of a deleted user loses its connection. A failed step returns its error and leaves the server active, so run the command again. The Operation holds the server target, so another public database Operation returns `operation.target_busy`. A repeat on a MySQL server repairs private publication.

## Errors

The server commands return these codes.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_slug_conflict` | 409 | Another server has the slug. |
| `database.server_port_in_use` | 409 | Another Process on the Node publishes the port. |
| `database.server_start_failed` | 502 | The server did not accept the admin login within 120 seconds. |
| `database.server_in_use` | 409 | A connection still points to the server, another database removal or ACL change is running, or closing would lock out a client. |
| `database.server_connection_managed` | 409 | A change would replace a managed PostgreSQL or Valkey connection's identity or credentials. |
| `database.server_inactive` | 409 | The server is not active yet. Run `database:server:create` again to finish it. |
| `database.server_adoption_refused` | 409 | The Process or the connection cannot be adopted. The message names the check that failed. |
| `database.server_required` | 422 | The connection has no server, so Orbit cannot create a user on it. |
| `database.driver_unsupported` | 422 | The driver does not support this operation, including automatic PostgreSQL Instance database creation and cloning. |
| `database.lan_unavailable` | 422 | The server or consumer is not eligible for the selected LAN endpoint. |
| `database.required_by_analytics` | 409 | Analytics still uses this connection; move the role before destroying it. |
| `database.acl_rules_invalid` | 422 | Valkey refused a key pattern or a rule. |
| `database.in_use` | 409 | A session, replication object, prepared transaction, or user dependency blocks the planned database removal. Nothing is dropped. |
| `database.name_conflict` | 409 | The name is taken on the server, a recorded test member has a foreign owner, or its managed role is missing. |
| `database.slug_conflict` | 409 | The connection slug exists and the request is not an identical PostgreSQL pool retry. |
| `process.required_by_database_server` | 409 | `process:destroy` names a server's Process, or `process:update` names a Valkey server's Process. |
| `database.server_command_failed` | 502 | An admin command on the server failed. The message holds no SQL output, rule, or password. |


## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The root password lives only in the Gateway

The MySQL image reads `MYSQL_ROOT_PASSWORD` only when it creates an empty data directory. So Orbit passes it once and then removes it. A password in the Process environment would stay unencrypted in the Gateway and visible through `docker inspect` on every Node, production included. Encrypting the Process environment was rejected, because the container would still show the password.

### One way to set up MySQL

A server is always a Docker Process, so it runs the same way on every Linux Node. Creating databases and users through any MySQL Process with a root password in its environment was removed, because a Process without that variable could never get a database. Adopting an existing MySQL Process was rejected, because its root password may be unknown. Create a server and move the databases once instead.

### One user per Instance

An Instance's user reaches only that Instance's databases, so one Instance cannot read another's data. A user per Project was rejected, because every Instance of the Project could then read every clone.

### A server is not a connection

A connection describes one database that an Instance uses. A server holds the admin password that creates those databases. Recording the server as a connection with root credentials would give one record two meanings.

### Valkey starts only from Orbit's ACL file

With an ACL file, Valkey ignores `requirepass`. A file without a `default` line, or a start without `--aclfile`, gives every client full access without a password. So Orbit writes the file before the first start, always with a `default` line, and refuses `process:update` of the Process. A missing or broken file stops Valkey instead of opening it. New servers require a password. The [open adoption](#adopt-a-valkey-process) is the temporary exception. Doctor reports that exception until the operator closes it.

### One Valkey user is one connection

A Valkey user limits keys and commands, not databases. A client reaches Valkey through one connection with one username and password. So a user and its connection are one record, made by one command, and `database:user:create` stays MySQL-only.

### Orbit owns the frame of each user

The caller names only key patterns and allowed commands. Orbit adds the reset, the password, no channels, and the final deny of `@admin`, `@dangerous`, `FUNCTION`, and `SCRIPT`. So no input can give a user the ACL commands, the configuration, another password, or a command that flushes the functions or scripts of every client. A raw rule string as input was rejected for the same reason. Refusing only `@scripting` was not enough, because `@write` and `@slow` also hold `FUNCTION FLUSH`.

### A repair rebuilds from the records

The Gateway holds the admin password and each user's password and rules, so the records are enough to rebuild a server's ACL. A repair writes the ACL file through the same program that created it, and Doctor, the repair, and the file all derive the users from the records in one place. So a lost file or a hand-made change is a repeat of one command, not a manual runbook. The repair is a repeat of `database:server:create`, because that command already brings a server to its record: a separate converge command would be a second way to do one thing.

### Valkey admin over WireGuard

`valkey-cli` exits 0 on an error reply when it reads commands from standard input, so a script inside the container cannot tell a refused `ACL SETUSER` from a done one. The fixed Node program uses the recorded private endpoint and reads each protocol reply. Swamp owns retries, and the Gateway supplies credentials only inside the authorized invocation. Reads and Doctor inspection remain in the Gateway.

### Adoption without a data move

The Valkey on `services` holds the Gateway queue and the collector's state, and the Gateway already holds its password. Adoption keeps the volume and the port and restarts Valkey once. A new server and a move of the data would need a queue outage or a copy tool, a release, and a new collector setup. Adoption is refused for MySQL, whose root password may be unknown.

### PostgreSQL shares the SQL server lifecycle

PostgreSQL uses the existing MySQL Process, encrypted admin credential, provisioning steps, connection and user actions. A separate manager would duplicate ownership and retry rules. PostgreSQL has its own statement renderer because its identifier and literal escaping differ from MySQL. SCRAM verifiers keep application passwords out of server SQL logs. Restricting the image to PostgreSQL 18 preserves the documented volume layout when the first-start container is recreated.

### Private networks are the boundary

This is the trust model for decision 0227, recorded for PR #1441. Managed servers are consumed only inside Orbit's private networks. Explicit WireGuard and LAN bindings keep Docker from listening on a public interface. Wildcard bindings were rejected because Docker bypasses UFW and Services has a public interface. A port firewall and custom `pg_hba.conf` source filtering were rejected: the private networks are trusted. No script-gate exception is needed.

A shared Cluster does not prove LAN reachability. Services has no Cluster and must not join prod to serve app-prod. The operator selects LAN for clients on the same data-centre network. WireGuard stays the default; automatic selection would change existing consumers.

### Migration preserves connection identities

A new canonical connection owns each restored database. Existing external connection IDs and their attachments stay intact, with their endpoints and application credentials updated. Replacing those IDs would break attachment and Vault identities. Importing source roles wholesale could carry superuser rights and stale grants. Plausible's PostgreSQL 18 target needs a disposable rehearsal because upstream does not certify that major; Services is not a compatibility test target.

### Replication follows the managed reader model

Replication consumers need the same current and future table reads as a managed read-only user, plus PostgreSQL's replication attribute. Reusing those grants keeps one owner-default rule. Publications belong to app migrations because the app owns table selection and schema changes. Managing publications here would add a second schema lifecycle and require table inputs unrelated to credential management. No additional roles or custom grant SQL are needed.

### PostgreSQL workers exist before tests start

PostgreSQL has no database prefix grant. Giving an Instance `CREATEDB` would let it create databases outside its managed set. Orbit therefore creates a bounded pool as admin, using its normal managed ownership and public-schema grants. This serves the mission principles **One way, one name** and **Lean**. Hand-written grants, bespoke roles, a repository-specific parallel-testing provider, and a custom test runner are rejected. Stock Laravel probes each worker database and reuses it when the connection succeeds. The Instance can migrate and reset tables as owner without the right to create databases.

### Keep one managed owner for PostgreSQL tests

The Instance role keeps ownership of its serial test database and workers, including the right to drop them. This preserves the existing managed ownership and schema rule and supports Laravel's `migrate:fresh`, which drops tables and recreates the schema's objects. A separate non-login database owner could block Laravel's database drop, but adds another role lifecycle and an ownership migration for every existing pool. It also cannot make `--recreate-databases` work without `CREATEDB`. We keep the existing ownership model under **One way, one name** and **Lean**; no ownership migration is needed.

Laravel's `--recreate-databases` and `--drop-databases` remain unsupported: dropping an owned database succeeds, but recreating it fails with `NOCREATEDB`. A failed worker probe takes the same destructive path. Orbit detects lost members with Doctor and restores them through the original create request. A separate repair command was rejected because it would add a second path for creating the same managed databases. Connection headroom and bounded parallelism prevent the observed exhaustion trigger; Orbit does not reserve server connections.

### Check the whole database set before removal

Database removal follows Orbit’s existing refusal rule for connected clients. `DROP DATABASE ... WITH (FORCE)` was rejected because it would disconnect clients without a separate choice and still fails on logical replication slots, subscriptions, and prepared transactions. Preflight checks every planned database and every role being removed before destructive SQL runs. Each server batch keeps a durable Operation and PostgreSQL protects its admission journal and drops with the existing server advisory lock.

A durable admission fence closes the worker-start race between that check and the drops. The server stores the journal beside its catalog so the snapshot and fence share one PostgreSQL transaction. A Gateway-only snapshot would create another crash window between the two systems.

PostgreSQL cannot run `DROP DATABASE` in a transaction, so an infrastructure failure still requires a retry. Avoid changing server dependencies through another admin connection during removal. Missing databases and users count as removed on retry.

### Direct default-user closure

`database:server:close` starts `operation-database-server-close` on the admitted Node. It reads only the declared admin password through the shared private-material endpoint. The native step checks other default-user clients, changes the password, saves the ACL file and disconnects default-user sessions. Save failure restores anonymous access only when it was open on entry, then reports a bounded failure. A resumed step authenticates with the admitted admin password even if the first attempt already closed anonymous access. The Gateway records `default_open=false` only after success.

A first Valkey adoption refused before mutation releases only its reserved Database server record. Its existing Process, container, data volume and connection remain unchanged, so correcting the connection password permits another adoption. Later or uncertain failures retain their records for inspection.
