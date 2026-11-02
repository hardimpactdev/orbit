---
title: "Database servers"
description: "How Orbit runs a MySQL, PostgreSQL or Valkey server as a Docker Node Process, keeps its admin password only in the Gateway, and creates databases and ACL users on it."
covers:
  - apps/gateway/app/Actions/DatabaseServers/**
  - apps/gateway/app/Actions/DatabaseConnections/{CreateServerDatabaseAction,CreateDatabaseUserAction,DropServerDatabaseAction}.php
  - apps/gateway/app/{Domain,Infrastructure}/DatabaseServers/**
  - apps/gateway/app/Http/{Controllers/Api/DatabaseServersController.php,Requests/DatabaseServers/**}
  - apps/gateway/{app/Models/DatabaseServer.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,database/migrations/2026_12_30_000000_add_test_processes_to_database_connections.php}
  - apps/cli/app/Commands/Database/{CreateDatabaseServerCommand,ListDatabaseServersCommand,ShowDatabaseServerCommand,DestroyDatabaseServerCommand,CreateDatabaseUserCommand}.php
  - packages/php-sdk/src/{Requests,Responses}/DatabaseServers/**
  - swamp/{extensions/behaviors/operations/{database-node-command/**,database-server-commands/scripts/{database_create,shipped}.py},workflows/workflow-operation-database-{server-create,server-destroy,create}.yaml}
---

# Database servers

A Database server is a MySQL, PostgreSQL or Valkey server that Orbit runs and administers. It runs as a Docker [Node Process](/reference/processes-and-schedules#owners), and the Gateway keeps its admin password. Orbit uses that password to create a database or an ACL user, and to [clone](/domains/applications#database-clone) the default Instance's MySQL database. [`database:server`](/cli/database#orbit-databaseservercreate) lists the commands.

A server's `driver` names its kind. A `mysql` server runs MySQL, a `pgsql` server runs PostgreSQL 18, and a `redis` server runs Valkey. A Valkey server's clients use the [`redis` driver](/reference/database-connections#drivers-and-fields), so the server uses the same name.

## Execution

Managed database creation runs `operation-database-create` on Node 209 in the [chart's](https://github.com/hardimpactdev/orbit/blob/main/docs/flows/database.create.mmd) four Node steps over official SSH: stage the owner password, create the owner and databases, check the owner login, and remove the staged password.

The create step reads the server's admin password from its own vault key, `database-server-<id>-admin`, on standard input. A step takes one secret on standard input, so the owner password goes first into a root-only file under `/run`, and an always-run step removes it whether the run passed or failed. The owner key holds only the owner's password and salt, never the admin password.

Each step sends the shipped phase module and its library in argv, so no script is copied to the Node. Ownership checks precede changes; existing data and passwords survive a rerun. The Gateway projects the recorded connection after native success.

Database actions return one [Operation](/reference/operations) with HTTP 202. The Gateway validates the request and records intent. Swamp runs one native DAG on the admitted Nodes and returns a bounded result. The CLI follows the Operation; use `--idempotency-key` after a lost start response. List and show read Gateway records. Tables, schema, describe and read-only queries run in the native Database read workflow.

When a read starts, the workflow receives full non-secret intent and vault key names. Its placed steps run on 209 and use official SSH to the admitted Node; the Gateway supplies private material through the shared execution copy. Separate script files enforce read-only access and redact bounded rows before native output. The shared terminal subscriber records the typed result.

Instance lifecycle workflows compose Database actions as placed jobs. They do not create child Operations or wait in the Gateway. `instance:destroy` drops the databases and users an Instance owns on each server through the same server child as `database:destroy`; the Gateway copies each server's admitted password to the vault at run start. Removal refuses system databases (`mysql`, `sys`, `performance_schema`, `information_schema`, `postgres` and names beginning with `template`), including any owned prefix that could match them. The native action checks expanded names before SQL. Create and user admission refuse while removal targets the same server; removal refuses while creation is unfinished.

Native workflows read stored credentials through the declared private-material source for the admitted run and Node. Operation history contains no passwords or step receipts. Removal deletes the admitted record after native success.

The SQL user parent resolves private data inside its existing read boundary. The shared SQL user action accepts that data directly and returns only a public completion or refusal. It does not receive a Gateway client or fetch callback.

## Create a server

`database_server.create` records the server and its Process, then starts `operation-database-server-create` with the full public intent. Every step runs on Node 209. The official `@swamp/cfgmgmt/docker_image` behavior pulls the image; the other steps reach the server Node over official SSH with its recorded host key. The steps follow the [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/flows/database_server.create.mmd): pull the image, write the Valkey ACL file, start the container, wait until healthy, check the admin login, and remove the first-start environment. Each step sends one shipped script phase in argv, so nothing is copied to the Node.

The admin password goes to the vault under the server's own key, `database-server-<id>-admin`. Every database kind that logs in as the admin reads that key by name. A Valkey ACL file goes to the vault under `database-server-create-intent-<operation>`. Neither value enters the workflow inputs, a step output, or a command argument; scripts pass them to Docker in a 0600 env file or on standard input.

Docker owns the health probe. The wait step reads Docker's health events for the container: healthy succeeds; unhealthy or an exit fails. The step timeout bounds the wait. The result names the failed step and its error code, for example `database.server_unhealthy`, and the server record keeps both.

`database:server:create` creates the server and its Process in one command. It refuses a server with an unfinished removal intent, and a Node without a stored SSH host key (`swamp.ssh_target_unavailable`).

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

The Gateway records the server and its intent. Swamp runs SSH steps from Node 209 and creates a MySQL server in this order:

1. It generates a random root password and stores it encrypted on the server record.
2. It creates a Docker Node Process named `<slug>` with image `mysql:<tag>`, the port published on the Node's WireGuard address to container port `3306`, and the volume `orbit-<slug>-data` for `/var/lib/mysql`.
3. It starts the Process with `MYSQL_ROOT_PASSWORD` set and waits until MySQL accepts the root login.
4. It removes the variable from the Process and recreates the container. The data volume keeps the password.

Until password removal succeeds, a retry starts the container with the same recorded password again. After step 4, the Gateway keeps the encrypted root password and Swamp keeps its vault execution copy. The password is absent from the Process record, the container environment and `docker inspect`. Client commands use a temporary 0600 credentials file and remove it when they finish. Orbit waits for the root login over TCP inside the container, which only the final MySQL server accepts, so it never recreates the container while MySQL still initializes its data directory.

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

Existing servers keep their publications until an explicit create retry. That retry enables private publication and validates the selected server. Node provisioning and removal do not contact database hosts or install database filters. When a server address changes, retry creation before switching clients. A retry can recreate an active MySQL container to repair publication drift.

Repeat creation repairs publication drift. A failed SQL repair keeps an active server active and records its failed step.

### A Valkey server

Swamp creates a Valkey server from Gateway intent in this order:

1. It generates a random admin password and stores it encrypted on the server record.
2. Swamp writes the ACL file `/data/users.acl` into the volume `orbit-<slug>-data` with a short-lived container, reading the file from standard input. The file has one line, `user default on #<sha256 of the admin password> ~* &* +@all`.
3. It creates and starts a Docker Node Process named `<slug>` with image `valkey/valkey:<tag>`, the volume at `/data`, and no environment.
4. It waits until the user `default` can log in with the admin password.

The Process publishes its port on the Node's WireGuard address to container port `6379`, and runs `valkey-server --appendonly yes --dir /data --aclfile /data/users.acl`. `default` is the admin user, and it always has the Gateway's password. The ACL file holds a SHA-256 hash of it, never the password. Valkey refuses to start when the ACL file is missing or has a wrong line, so a damaged server stays closed. The append-only file in the volume keeps the data across a restart.

### Retry and response

A retry with the same `--idempotency-key` returns the recorded Operation, including its terminal result. After a failed Operation, a new start reuses the server, encrypted password, Process and volume. A request with another Node, driver, tag or port returns `database.server_slug_conflict`. A new start for an active Valkey server [repairs the server](#repair-a-valkey-server).

Write endpoints return HTTP 202 with an Operation. List and show return `id`, `slug`, `driver`, `node_id`, `process_id`, `tag`, `port`, `status`, and `databases_count`. Show also returns `databases`, `failed_step` and `error_code`. Passwords never appear in either response.

The server's Process is an ordinary Docker Node Process: read its logs, stop it, start it, or restart it with the [`process`](/cli/process) commands. `process:destroy` refuses it with `process.required_by_database_server`; remove the server instead. `process:update` refuses the Process of a `redis` server with the same code, so the command always keeps `--aclfile`.

## Close an adopted server

`database:server:close` gives `default` the admin password, so a client without a password gets no access. Run it after each client of an open server has [its own user](#create-an-acl-user-on-a-valkey-server).

```bash
orbit database:server:close beast-valkey
```

Swamp runs the named Valkey command for `ACL SETUSER default resetpass #<sha256 of the admin password>` and `ACL SAVE`. It refuses with `database.server_in_use` (409) and changes nothing while either of these holds:

- A connection on the server has no password. Its clients would be locked out. Destroy it, or move its clients first.
- A client other than the Gateway is logged in as `default`. The result contains only the fixed refusal code.

After saving the password, the Node program revokes every other `default` session, including one accepted between the client check and the password change. A retry repeats the save and revocation before it records closure. A client that is not connected at that moment is not seen, so move every client before you close the server. The command exists only for the two adopted Processes.

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

A successful retry returns an Operation for the same connection. A failure keeps the connection record and any members already restored, so repeat the same request to finish. Stop the affected test run before repair. Repeating repair on a complete pool converges the same grants without recreating databases. Inspect the native database result for missing members, and check the [connection budget](/reference/database-connections#test-databases) before restarting tests.

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
ACL SETUSER <slug> reset on #<sha256 of the password> resetchannels ~<pattern> ... -@all +<rule> ... -@admin -@dangerous -function -script [+flushdb]
ACL SAVE
```

Orbit adds every part except the patterns and the rules. So a user never has pub/sub channels or another user's password. The final `-@admin -@dangerous` removes every admin and dangerous command, also one that a category such as `@keyspace` holds. The final `-function -script` removes the `FUNCTION` and `SCRIPT` commands, such as `FUNCTION FLUSH` and `SCRIPT FLUSH`. They act on the whole server, and `@write` and `@slow` hold some of them. `EVAL` stays available when a rule allows it. No user can change the ACL or the server's configuration.

`FLUSHDB` is the one dangerous command a user can get back. When `--allow=flushdb` is given, Orbit adds `+flushdb` after the final deny. `FLUSHDB` clears only the index that the client selected, so give such a user its own `--database` index; a Laravel cache store on Redis needs it for `cache:clear`. ACL rules do not limit which index a client selects, so the index separates apps only while each app selects its own. `FLUSHALL` stays denied.

The Gateway refuses these inputs with `validation.failed`:

- A key pattern that starts with `*`, `?`, `[`, or `\`. Each pattern needs a literal key prefix.
- The rule `@all` or `@scripting`. A user gets only the categories and commands its clients need.

The connection gets driver `redis`, the server's Node address and port, the `--database` index, the username `<slug>`, the encrypted password, and a link to the server. Orbit also records the user with the rules that Valkey reports. `database:user:list` shows those rules.

The slug `default` is refused. A user that the server already has, and that Orbit did not record, returns `database.name_conflict`. A rule that Valkey does not know fails with `database.acl_rules_invalid`, without native diagnostics. A save failure removes the unsaved new user. The reserved connection and encrypted intent remain for an identical retry. Another public Operation on the server returns `operation.target_busy`.

`database:update` can change the database index, but cannot change a managed Valkey connection's driver, Node, address, port, username, or password. Such a change returns `database.server_connection_managed` (409). Create a new connection and move its clients instead. Core applies this validation through the [typed connection update hook](/reference/extensions#plug-in-points). Connection removal runs every removal guard before it deletes an ACL user or its records.

Key patterns limit keys, not the database index. Valkey 8 has no ACL rule for an index, so a user can select any index, and its patterns apply in each one. `SCAN` and `DBSIZE` show the names and the count of all keys in an index, but no values. A user that can write can also fill the server's memory, because Orbit sets no memory limit.

`--instance`, [`instance:create --database-server`](/domains/applications#database-on-a-server), and the Instance database clone refuse a `redis` server with `database.driver_unsupported`.

## Add a user

SQL user creation runs `operation-database-user-create` with full public intent and a vault descriptor. All steps run on 209 and reach the admitted server through official SSH. PostgreSQL replication requests have a separate read-only WAL check before the login and grant actions. The Gateway captures the database, owner, username and grant policy as public intent. The shipped script builds user and grant SQL on the target Node; the Gateway builds no SQL.

The admin password, requested login password and stable SCRAM salt use the shared vault execution copy; none enters workflow inputs, argv or public output. An unrecorded PostgreSQL role is reusable only when its verifier matches this exact intent. A recorded user may rotate its password. MySQL grants escape database-name underscores so each grant covers only the admitted database.

The shared terminal subscriber records the user after native success. Native terminal-delivery resume does not repeat SQL. Valkey users remain separate database connections.

`database:user:create` adds or updates a user on a MySQL or PostgreSQL server, for example a read-only user in production. `database:user:list` lists the users that Orbit created for a connection.

```bash
orbit database:user:create dlf-leden --username=reporting --read-only
```

The CLI reads the password from piped standard input, or asks for it with a hidden prompt on a terminal; see [`database:user:create`](/cli/database#orbit-databaseusercreate).

A read-only user gets `SELECT` on the database. A regular MySQL user gets all database privileges; a regular PostgreSQL user gets the data-write grants described above. Running the command again sets the password and replaces the privileges. A connection without a server returns `database.server_required` (422). A connection on a `redis` server returns `database.driver_unsupported` (422): on Valkey, each user is a connection of its own. The user of a connection on the same server returns `database.name_conflict` (409), so the command never changes the password that a connection uses. The Gateway stores the user's password encrypted, so it can [publish it to Vaultwarden](/reference/vaultwarden#database-user-passwords). No response returns it.

### Logical replication users

Create a managed read login for a logical replication consumer such as PowerSync.

```bash
orbit database:user:create app --username=powersync --replication
```

`--replication` (API field `replication`, boolean, default false) requires a managed PostgreSQL server with live `wal_level=logical`. Orbit checks it before changing the role or grants. It refuses another driver with `database.driver_unsupported` (422) and another WAL level with `database.wal_level_not_logical` (422). Orbit does not change server configuration.

The user has `LOGIN REPLICATION`, database `CONNECT`, public-schema `USAGE`, and `SELECT` on all current tables and sequences. Default privileges give the same reads on future objects created by the connection's managed owner. Replication implies the read-only grants; adding `--read-only` is allowed and has the same result. The role has no superuser, CREATEDB, or CREATEROLE attribute. PostgreSQL's replication attribute is server-wide; publications and the database grants determine which application tables the consumer reads.

Repeating the command sets the password and replaces privileges and owner defaults. Omit `--replication` on a later call to remove the replication attribute. The same name-conflict rules apply: Orbit refuses any connection owner on the server, or an existing PostgreSQL role outside this connection. Orbit does not adopt hand-created replication roles. Use an unused name when moving an existing client to managed credentials.

User create returns an Operation. Its completion records the credentials and grants. List responses include the recorded `replication` boolean. The native user operation validates the live PostgreSQL role before returning its result.

Application migrations create and alter logical publications and select their tables. Orbit does not create publications or replication slots.

## How Orbit runs admin commands

Swamp runs fixed `mysql`, `psql`, and `mysqldump` clients inside the admitted Database server container with local `sudo docker container exec`. The shared Deno SQL action frames the admin password and SQL on standard input. A fixed container program reads the password frame and sets only the client’s `MYSQL_PWD` or `PGPASSWORD`. Neither secret appears in host environment variables, command arguments, Docker exec metadata, files or native output. MySQL copies stream directly from dump to load inside the container. Readiness, client deadlines and output bounds belong to Swamp. The observer agent executes no database actions.

For Valkey writes, Swamp invokes the fixed `database.valkey` program on the server Node. The Gateway resolves its credentials after authorization. The program connects to the recorded private address, checks every reply, and returns only fixed result fields and refusal codes. A user's password goes to Valkey only as a SHA-256 hash. A separate named command writes the ACL file during creation or [repair](#repair-a-valkey-server). It receives the file on standard input and writes it through a short-lived container that mounts the volume. The file holds only password hashes.

## Remove a database

`database:destroy` of a connection on a MySQL server drops the database, every database whose name starts with its test database name, and each user Orbit created for it that no other connection on the server uses. On PostgreSQL it drops only the primary, test database, and recorded worker pool. It deletes the record only after every database and unshared user is gone. A failed drop keeps the record; a repeat tolerates missing objects and finishes the remaining drops.

Standalone removal receives captured public intent and a vault descriptor. Native steps on 209 use official SSH to inventory, prove ownership, check blockers, drop each database and remove each unshared user. PostgreSQL restores surviving connection fences after failure. Valkey saves the admitted ACL users for rollback before deleting them and persists the result with a separate save step. Native retry of the removal child repeats the safety checks; resuming terminal delivery alone repeats no effects. The Gateway removes records through the shared terminal projector.

The Gateway admits the recorded resource identities and starts one native Swamp workflow. It performs no live probe, builds no SQL, and holds no execution lock. Swamp inventories MySQL test-prefix members, verifies full grants for the recorded user, and checks PostgreSQL owners before removal. System database names and prefixes are refused before SQL. PostgreSQL keeps a persistent connection fence across the final checks and drops. Swamp restores database admission after a failed drop.

Before the first drop, Orbit checks the whole removal set. PostgreSQL sessions, replication slots (including inactive logical slots), prepared transactions, and subscriptions block removal. Dependencies of an unshared role outside the set also block it, including ownership and grants in another database or on a shared object. MySQL checks connected clients, metadata locks on the planned schemas (including transactions that use qualified table names without a default database), stored objects outside the set whose definer is a user being removed, and incoming cross-schema foreign keys. Cross-schema references between planned databases also block; remove those constraints first.

PostgreSQL removal closes new client admission across the whole planned set with `ALLOW_CONNECTIONS false`, then checks blockers again before any drop. Existing clients stay connected and block removal. The fence remains through the drops. On refusal or failure, Orbit restores each surviving database's exact prior `ALLOW_CONNECTIONS` value; it never changes `CONNECTION LIMIT`.

The admin database `postgres` keeps the original admission values and database OIDs in the admin-only `orbit_admin.database_removal_fences` table. Journaling and fencing commit together. A crash before commit rolls both back; a crash after commit retains the journal for retry. Retrying either completes removal or restores admission before returning a refusal. A changed database OID stops recovery instead of changing a replacement database.

PostgreSQL advisory transaction locks serialize journal changes. The persistent connection fence spans the native checks and drops; Gateway admission serializes managed changes on the server. A lost session releases its lock, so a crash does not leave a timed lock blocking retry. A failed restore keeps the journal and connection records and returns a server error; retry the removal to recover.

A blocker records `database.in_use` on the Operation and leaves databases, users, attachments, and records unchanged. Stop clients and remove or move the blocking dependencies, then retry. Orbit never force-disconnects clients. A server or transport failure after dropping starts can still interrupt removal; retain the record and repeat the same command to finish.

On a Valkey server, `database:destroy` deletes the connection's ACL user with `ACL DELUSER` and runs `ACL SAVE`. The user's keys stay. A connection without a username, such as one linked by adoption, logs in as `default`, so destroying it deletes only the record.

## Remove a server

`database:server:destroy <slug>` refuses with `database.server_in_use` (409) while a connection points to the server. A server without a Process has nothing on its Node, so the Gateway deletes the record at once and returns a succeeded Operation with no run (R1). Otherwise admission marks the server as removing, so new database creation refuses it, and starts `operation-database-server-destroy`. Three official SSH steps run one docker command each: stop the container gently, remove it, and check that `docker container inspect` reports it missing. The volume stays, so the data survives. Remove the volume on the Node by hand when its data must go.

`database:server:destroy` starts `operation-database-server-destroy` with the public Process identity and target SSH metadata. All steps select Node 209 with one placement selector and reach the server Node through official SSH. Native fan-out inspects every container slot before any stop or removal. Each slot inspection uses its own SSH model, named by the native run and iteration, so model locks do not serialize independent observations. One shared native child stops and removes each present owned container through the shared Process scripts. The named data volume stays intact.

The shared terminal subscriber deletes the Server and Process records only after native success. A failed step marks the server failed with that step and its error code, and keeps both records; run the command again. Steps make no Gateway calls and need no database credentials.

After correcting a failed action, an authorized operator can use native `swamp workflow resume operation-database-server-destroy --run <run-id> --from prepared` to inspect all slots again before continuing. Already absent containers stay absent. Resuming only terminal delivery preserves completed effects.

A new Valkey server with the slug of a removed one finds the old volume. It writes a new ACL file there, so the old users are gone, but the old keys stay.

A Node that runs a server cannot be removed. [Node removal](/reference/node-provisioning#remove-a-node) refuses it with `node.has_database_servers`, also offline.

## Check a server with Doctor

The native database-server and database operations own server configuration, endpoint and credential validation. [Doctor](/cli/doctor#what-each-family-checks) does not repeat these checks; its Process family can inspect the registered server container state.

## Repair a Valkey server

Run the server's `database:server:create` command again to make an active Valkey server match its records. The arguments must match the server, as for any [retry](#retry-and-response).

```bash
orbit database:server:create beast-valkey --node=beast --driver=redis
```

The same steps run. The ACL file step rewrites `/data/users.acl` from the records: `default` and one line per recorded user, `user <name> #<sha256 of the password> <rules>`. The start step then restarts the running container once, so Valkey reads the file. Rebuilding the file also repairs a server that cannot start because the file is lost. Keys stay. A client of a deleted user loses its connection. A failed step returns its error and leaves the server active, so run the command again. The Operation holds the server target, so another public database Operation returns `operation.target_busy`. A repeat on a MySQL server repairs private publication.

## Errors

The server commands return these codes.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_slug_conflict` | 409 | Another server has the slug. |
| `database.server_port_in_use` | 409 | Another Process on the Node publishes the port. |
| `database.server_image_pull_failed` | 502 | Docker could not pull the server image. |
| `database.server_start_failed` | 502 | Docker could not create or start the server container. |
| `database.server_unhealthy` | 502 | The container reported unhealthy or exited before it became healthy. |
| `database.server_login_failed` | 502 | The admin login failed after the container became healthy. |
| `database.server_scrub_failed` | 502 | Docker could not recreate the container without its first-start password. |
| `database.server_inspect_failed` | 502 | Docker could not list the server's containers. |
| `database.server_remove_failed` | 502 | Docker could not stop or remove a server container. |
| `database.server_remove_incomplete` | 502 | A container of the server's Process is still present after removal. |
| `database.owner_login_failed` | 502 | The new owner could not log in after creation. |
| `database.create_outcome_unknown` | 502 | The create run ended without a readable result; run the command again. |
| `database.server_create_failed` | 502 | The create run ended without reaching the admin login check. |
| `database.server_container_foreign` | 409 | A container with the server's name belongs to another Process; nothing changed. |
| `database.valkey_acl_failed` | 502 | Docker could not write the Valkey ACL file into the volume. |
| `database.server_in_use` | 409 | A connection still points to the server, another database removal or ACL change is running, or closing would lock out a client. |
| `database.server_connection_managed` | 409 | A change would replace a managed PostgreSQL or Valkey connection's identity or credentials. |
| `database.server_inactive` | 409 | The server is not active yet. Run `database:server:create` again to finish it. |
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

With an ACL file, Valkey ignores `requirepass`. A file without a `default` line, or a start without `--aclfile`, gives every client full access without a password. So Orbit writes the file before the first start, always with a `default` line, and refuses `process:update` of the Process. A missing or broken file stops Valkey instead of opening it. New servers require a password. A server adopted open before adoption was removed stays open until the operator [closes it](#close-an-adopted-server).

### One Valkey user is one connection

A Valkey user limits keys and commands, not databases. A client reaches Valkey through one connection with one username and password. So a user and its connection are one record, made by one command, and `database:user:create` stays MySQL-only.

### Orbit owns the frame of each user

The caller names only key patterns and allowed commands. Orbit adds the reset, the password, no channels, and the final deny of `@admin`, `@dangerous`, `FUNCTION`, and `SCRIPT`. So no input can give a user the ACL commands, the configuration, another password, or a command that flushes the functions or scripts of every client. A raw rule string as input was rejected for the same reason. Refusing only `@scripting` was not enough, because `@write` and `@slow` also hold `FUNCTION FLUSH`.

### A repair rebuilds from the records

The Gateway holds the admin password and each user's password and rules, so the records are enough to rebuild a server's ACL. A repair writes the ACL file through the same program that created it, and the repair and the file derive the users from the records in one place. So a lost file or a hand-made change is a repeat of one command, not a manual runbook. The repair is a repeat of `database:server:create`, because that command already brings a server to its record: a separate converge command would be a second way to do one thing.

### Valkey admin over WireGuard

`valkey-cli` exits 0 on an error reply when it reads commands from standard input, so a script inside the container cannot tell a refused `ACL SETUSER` from a done one. The fixed Node program uses the recorded private endpoint and reads each protocol reply. Swamp owns retries, and the Gateway supplies credentials only inside the authorized invocation. The native database operations own live validation.

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

Laravel's `--recreate-databases` and `--drop-databases` remain unsupported: dropping an owned database succeeds, but recreating it fails with `NOCREATEDB`. A failed worker probe takes the same destructive path. Orbit restores lost members through the original native create request. A separate repair command was rejected because it would add a second path for creating the same managed databases. Connection headroom and bounded parallelism prevent the observed exhaustion trigger; Orbit does not reserve server connections.

### Check the whole database set before removal

Database removal follows Orbit’s existing refusal rule for connected clients. `DROP DATABASE ... WITH (FORCE)` was rejected because it would disconnect clients without a separate choice and still fails on logical replication slots, subscriptions, and prepared transactions. Preflight checks every planned database and every role being removed before destructive SQL runs. Each server batch keeps a durable Operation and PostgreSQL protects its admission journal and drops with the existing server advisory lock.

A durable admission fence closes the worker-start race between that check and the drops. The server stores the journal beside its catalog so the snapshot and fence share one PostgreSQL transaction. A Gateway-only snapshot would create another crash window between the two systems.

PostgreSQL cannot run `DROP DATABASE` in a transaction, so an infrastructure failure still requires a retry. Avoid changing server dependencies through another admin connection during removal. Missing databases and users count as removed on retry.

### Direct default-user closure

`database:server:close` starts `operation-database-server-close` with captured public intent and a vault descriptor. Native steps on 209 use official SSH to run shipped scripts on the server Node: probe default-user clients, set the password, save ACLs, restore anonymous access after a failed save, and disconnect default-user sessions. The password reaches the official Valkey CLI through a protected temporary environment file and never enters arguments or outputs.

Save failure restores anonymous access only when it was open on entry, then reports a bounded failure. Native resume from the first `assets` step repeats the checks with the admitted password; terminal result delivery alone repeats no effects. The Gateway records `default_open=false` only after success.

A first Valkey adoption refused before mutation releases only its reserved Database server record. Its existing Process, container, data volume and connection remain unchanged, so correcting the connection password permits another adoption. Later or uncertain failures retain their records for inspection.

A failed ACL save attempts to restore `nopass` only if anonymous access was open before the action. If that restoration also fails, the Operation reports `database.server_restore_failed` and `default_open=unknown`. The server records `default_open=null`, failed status and `database.default_access_unknown`, and emits one critical alert. ACL generation refuses this unknown state until the operator verifies access.
