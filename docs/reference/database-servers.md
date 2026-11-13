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
  - swamp/workflows/workflow-operation-database-{server-create,server-destroy,server-close,create,destroy,user-create}.yaml
---

# Database servers

A Database server is a MySQL, PostgreSQL or Valkey server that Orbit runs and administers. It runs as a Docker [Node Process](/reference/processes-and-schedules#owners), and the Gateway keeps its admin password. Orbit uses that password to create a database or an ACL user, and to [clone](/domains/applications#database-clone) the default Instance's MySQL database. [`database:server`](/cli/database/server-create) lists the commands.

A server's `driver` names its kind. A `mysql` server runs MySQL, a `pgsql` server runs PostgreSQL 18, and a `redis` server runs Valkey. A Valkey server's clients use the [`redis` driver](/reference/database-connections#drivers-and-fields), so the server uses the same name.

## Execution

Managed database creation runs `operation-database-create` on Node 209 over official SSH, in two steps ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/database/create.mmd)): one `docker exec -i` creates the owner and databases, then the owner logs in over TCP.

Each step's standard input is one whole vault value the Gateway renders: the login's password as the first line, then the statements. Inside the container, the client reads that line into its password variable, so no password enters argv or a file, and no cleanup step is needed. The create batch carries the owner's password.

The batch refuses a database that exists for another owner with `database.name_conflict` before any change; it prints the refusal for the Gateway, so no read runs first. The run has no report step: the Gateway reads both steps' output from the native result. The Gateway projects the recorded connection after native success.

Database actions return one [Operation](/reference/operations) with HTTP 202. The Gateway validates the request and records intent. Swamp runs one native DAG on the admitted Nodes and returns a bounded result. The CLI follows the Operation; use `--idempotency-key` after a lost start response. List and show read Gateway records. Tables, schema, describe and read-only queries run in the native Database read workflow.

When a read starts, the workflow receives full non-secret intent and vault key names. Its placed steps run on 209 and use official SSH to the admitted Node; the Gateway supplies private material through the shared execution copy. Separate script files enforce read-only access and redact bounded rows before native output. The shared terminal subscriber records the typed result.

Instance lifecycle workflows compose Database actions as placed jobs. They do not create child Operations or wait in the Gateway. `instance:destroy` drops the databases and users an Instance owns on each server through the `operation-database-destroy` workflow that `database:destroy` uses, with the server's own admin login key. Removal refuses system databases (`mysql`, `sys`, `performance_schema`, `information_schema`, `postgres` and names beginning with `template`), including any owned prefix that could match them. The native action checks expanded names before SQL. Create and user admission refuse while removal targets the same server; removal refuses while creation is unfinished.

Native workflows read stored credentials through the declared private-material source for the admitted run and Node. Operation history contains no passwords or step receipts. Removal deletes the admitted record after native success.

The SQL user parent resolves private data inside its existing read boundary. The shared SQL user action accepts that data directly and returns only a public completion or refusal. It does not receive a Gateway client or fetch callback.

## Create a server

`database_server.create` records the server and its Process, then starts `operation-database-server-create` with the full public intent. The intent holds each docker command the run needs, rendered by the Gateway. Every step runs on Node 209 and reaches the server Node with its recorded host key. Each step is one official `@swamp/cfgmgmt` behavior or one docker command over official SSH; nothing is copied to the Node. The steps follow the [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/database/server-create.mmd).

| Step | What it does |
| --- | --- |
| Pull the image | `@swamp/cfgmgmt/docker_image` keeps the image present. |
| Write the ACL file | Valkey only. The ACL file holds password hashes and goes into the data volume with `@swamp/cfgmgmt/file` (0600). |
| Start the container | One `docker run`. On a first start, `--env-file /dev/stdin` reads the password variable from the vault. A rerun keeps an existing container. |
| Wait until healthy | One bounded wait (`timeout 60`) for Docker's health status. Docker's probe uses TCP, which the server accepts only after its first-start initialization. |
| Check the admin login | One `docker exec -i` reads the password from the first stdin line and logs in over TCP. The Gateway checks the answer. |
| Remove the first-start env | MySQL and PostgreSQL. One bounded command starts the container again without the password variable and waits until Docker reports it healthy. |

Each secret reaches the Node only on standard input, from one whole vault value per run: `database-server-create-start-<operation>` holds the first-start line, and `database-server-create-login-<operation>` holds the password line and the check. A Valkey ACL file goes under `database-server-create-intent-<operation>`. No value enters the workflow inputs, a step output, a file or a command argument. The run has no report step: the failed step names the error code, for example `database.server_unhealthy`, and the server record keeps both.

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

To move a database onto a managed PostgreSQL server, dump it with a `pg_dump -Fc` client of the server's version and restore it with `pg_restore --exit-on-error --no-owner --no-acl --role=<owner>`, so the managed application role owns the objects. Creating a server does not adopt or upgrade any existing Process.

### Private publication

Managed Database servers serve clients inside the Orbit network only. All three drivers publish one explicit IPv4 Docker binding on the Node's WireGuard IP and a second on its LAN IP when present. Publication requires RFC 1918 private IPv4 addresses. Orbit never binds `0.0.0.0`, an IPv6 wildcard, or the Node's public address. Services also has the public interface 85.9.217.155; that address must never appear in a Database server's bindings.

The private networks are the trust boundary. Clients that can reach those private addresses have network access to the ports. Ports published by Docker bypass UFW. Orbit adds no database port firewall, iptables chain, UFW hook, Docker service drop-in or PostgreSQL source rules. PostgreSQL keeps the image's stock `pg_hba.conf` defaults and SCRAM password authentication. Operators must keep these private addresses unreachable from the public internet.

Docker starts a server's container at boot, and it can do that before the WireGuard tunnel or the LAN address is up. Every managed Linux Node allows binds to an address it does not hold yet, through its [kernel policy](/reference/node-provisioning#kernel-policy), so the publication succeeds and serves as soon as the address appears. Without it, the bind failed with `cannot assign requested address` and Docker left the container stopped.

The stored connection host stays WireGuard. Explicit LAN selection requires only that the server and consumer Nodes are active and each has an IPv4 LAN address. [LAN attachments](/reference/database-connections#choose-lan-for-an-instance) select LAN for an active consumer in the private network. A shared Cluster is not required.

Existing servers keep their publications until an explicit create retry. That retry enables private publication and validates the selected server. Node provisioning and removal do not contact database hosts or install database filters. When a server address changes, retry creation before switching clients. A publication on the old address still starts, because the Node allows non-local binds, but it serves nothing. A retry can recreate an active MySQL container to repair publication drift.

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

For every driver, the Process stores the current image. A repair keeps that image, whether the container is present or must be recreated. The server's `tag` selects the image only when the Process is first created and remains the creation tag; repeat `database:server:create` with the original arguments to repair it.

Write endpoints return HTTP 202 with an Operation. List and show return `id`, `slug`, `driver`, `node_id`, `process_id`, `tag`, `port`, `status`, and `databases_count`. Show also returns `databases`, `failed_step` and `error_code`. Passwords never appear in either response.

The server's Process is an ordinary Docker Node Process: read its logs, stop it, start it, or restart it with the [`process`](/cli/process) commands. `process:destroy` refuses it with `process.required_by_database_server`; remove the server instead. MySQL Process updates keep the ordinary partial-update contract.

Replacement of any Database server's Process keeps the server's Docker health check. Creation, replacement and repair use the same health-check settings, so a later repair can wait for the replacement container to become healthy. Before this fix, MySQL replacements lost this health check; they retain the `mysqladmin` probe with the fix.

### Update a Valkey server's image

`process:update` accepts only an image-only update to `valkey/valkey:<tag>` for a `redis` server. For example, `orbit process:update 41 --image=valkey/valkey:8.1.3` changes the image while keeping the command, including `--aclfile`, volumes and every other specification field. Tags contain 1 through 128 letters, digits, underscores, periods or hyphens, and start with a letter, digit or underscore.

Request normalization trims surrounding whitespace. Validation then runs before the server ownership check. Invalid input returns HTTP 422, including a supplied `user`, `start`, an empty `command`, or an image containing whitespace within the reference. After validation, an update with any other field, without `image`, or with another image repository returns `process.required_by_database_server` (409). Both refusals leave the Process unchanged.

## Close an adopted server

`database:server:update <slug> --require-password` requires a password for the default user, so a client without a password gets no access. Run it after each client of an open server has [its own user](#create-an-acl-user-on-a-valkey-server).

```bash
orbit database:server:update beast-valkey --require-password
```

Swamp runs one `valkey-cli` call per step from 209, in a throwaway `valkey/valkey:8.1` container on the server's Node. Each call reads its whole stdin from the vault: an `AUTH default` line with the admin password, then the step's commands. The password never enters argv. The close sends `ACL SETUSER default resetpass #<sha256 of the admin password>`, `ACL SAVE` and `CLIENT KILL USER default SKIPME yes`. The end check is a `PING` without a password, which must answer `NOAUTH`. The command refuses with `database.server_in_use` (409) and changes nothing while either of these holds:

- A connection on the server has no password. Its clients would be locked out. Destroy it, or move its clients first.
- A client other than the Gateway is logged in as `default`. The result contains only the fixed refusal code.

After saving the password, the close revokes every other `default` session, including one accepted between the client check and the password change. A failed save has no rollback: the live ACL stays closed, and a rerun repeats the close. The Gateway deletes both vault inputs when the task ends. A client that is not connected at that moment is not seen, so move every client before you close the server. The command exists only for the two adopted Processes.

The API is `PATCH /api/v1/database-servers/{database_server}` with JSON `{"require_password":true}`. Missing or false input is refused. No option reopens passwordless access. A server already requiring a password runs the existing readiness check.

## Create a database on a server

`database:create` with `--server` creates a database and an owner user on a MySQL or PostgreSQL server, then records the [connection](/reference/database-connections).

```bash
orbit database:create dlf-leden --server=beast-mysql --instance=60
```

The database name comes from the slug, with hyphens replaced by underscores. On MySQL, a database name ends in an underscore and an 8-character hash when its grant form is longer than 64 characters, and a user name when it is longer than 32. The grant form escapes each underscore, and the test prefix grant also adds `%`. PostgreSQL names are limited to 63 ASCII characters and use the same hash suffix when shortened. Orbit generates the owner password.

On MySQL and PostgreSQL, each Instance has one owner user on a server, named `<project>_<instance>` like its databases. A second database for the same Instance reuses that user and its password. That user can reach only the databases the Instance owns and their test databases. A database created without `--instance` gets its own user, named like the database. The connection gets the server's driver, the server's Node address and port, and a link to the server.

Orbit refuses a name with `database.name_conflict` when the server already has that database or user. MySQL also refuses a name that starts with another database's test database name. PostgreSQL checks exact names against the primary database and the recorded test pool. So one Instance's user never reaches another database through its test database grant. A failed Operation keeps its reserved connection and encrypted credentials. An identical new start reuses them; changed requests remain conflicts.

On MySQL and PostgreSQL, `--instance` also creates the test database `<name>_test`, attaches the connection to the Instance under prefix `DB`, and records the Instance as the owner. [Test databases](/reference/database-connections#test-databases) describes how tests use it.

Both also create the parallel test pool `<name>_test_test_1` through `<name>_test_test_N`, owned by the same managed user or role. `--test-processes=N` sets N from 1 to 32; omission uses 8. The connection records `test_processes`. This option requires a MySQL or PostgreSQL server and `--instance`; other combinations are refused. The primary name is limited to 50 characters with the same hash suffix, so the longest worker name fits PostgreSQL's 63-character limit. The selected locale applies to the primary database and every test database.

Creation checks every name before mutation. Failure keeps its reserved intent for a convergent retry; destruction drops only the recorded pool and leaves unrecorded names alone. The count is fixed at creation; create a new connection to change it.

`database:create --instance` needs an existing Instance, so it comes after that Instance's setup steps. To have the database before setup runs, pass the server to [`instance:create --database-server`](/domains/applications#database-on-a-server) instead; create runs the same `operation-database-create` child.

PostgreSQL database creation accepts `--locale=C.UTF-8` or `--locale=C`. Both use PostgreSQL 18's builtin locale provider, `TEMPLATE template0` and UTF8 encoding. Omitting the option inherits the image default. The connection records the selected locale; it cannot be changed after creation. MySQL, Valkey and external registration refuse this option.

## Reach a server from a container Instance

A [container Instance](/reference/development-containers#network) reaches only its own database server and port. The Instance's create flow adds the way, for a default with `--database-server` and for every clone:

- A rule on the container's NIC ACL allows the server's address and port.
- A UFW rule on the Instance's Node carries the comment `orbit:instance-<id>-database`.
- For a server on the same Node, it is an input rule from the container's bridge address.
- For a server on another Node, it is a route rule out to WireGuard. The bridge's NAT sends that traffic from the Node's WireGuard address.

## Clone a default database for a container clone

A clone of a container default gets its own owner and database on the default's server, from the same `operation-database-create` child. The run then clones the default database into it, in the server's container, with the admin login:

1. It closes the default database to new connections and ends its sessions. A trap opens it again, whether the copy succeeds or not.
2. It drops the empty database the child made, then runs `CREATE DATABASE <clone> TEMPLATE <default> OWNER <clone owner>`.
3. In the copy, every object of the default's owner passes to the clone's owner: tables, sequences, views, routines, types and schemas.
4. `PUBLIC` loses its access to the database. The default's owner loses its grants on `public`.

The default Instance sees refused connections while the copy runs. A MySQL default database is copied with a dump, as for a checkout Instance. A clone on another server than the default's refuses with `instance.database_clone_unsupported`: the dump across servers is not built yet.

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

`database:update` can change the database index; the run checks it against the server's live database count with one `valkey-cli` call. It cannot change a managed Valkey connection's driver, Node, address, port, username, or password. Such a change returns `database.server_connection_managed` (409). Create a new connection and move its clients instead. Core applies this validation through the [typed connection update hook](/reference/extensions#plug-in-points). Connection removal runs every removal guard before it deletes an ACL user or its records.

Key patterns limit keys, not the database index. Valkey 8 has no ACL rule for an index, so a user can select any index, and its patterns apply in each one. `SCAN` and `DBSIZE` show the names and the count of all keys in an index, but no values. A user that can write can also fill the server's memory, because Orbit sets no memory limit.

`--instance`, [`instance:create --database-server`](/domains/applications#database-on-a-server), and the Instance database clone refuse a `redis` server with `database.driver_unsupported`. A container clone of a default with a Valkey target gets its own ACL user instead: see [Clone the database](/reference/development-containers#a-clones-valkey-user).

## Add a user

SQL user creation runs `operation-database-user-create` with full public intent and a vault descriptor. All steps run on 209 and reach the admitted server through official SSH. PostgreSQL replication requests have a separate read-only WAL check before the login and grant actions. The Gateway captures the database, owner, username and grant policy as public intent. The shipped script builds user and grant SQL on the target Node; the Gateway builds no SQL.

The admin password, requested login password and stable SCRAM salt use the shared vault execution copy; none enters workflow inputs, argv or public output. An unrecorded PostgreSQL role is reusable only when its verifier matches this exact intent. A recorded user may rotate its password. MySQL grants escape database-name underscores so each grant covers only the admitted database.

The shared terminal subscriber records the user after native success. Native terminal-delivery resume does not repeat SQL. Valkey users remain separate database connections.

`database:user:create` adds or updates a user on a MySQL or PostgreSQL server, for example a read-only user in production. `database:user:list` lists the users that Orbit created for a connection.

```bash
orbit database:user:create dlf-leden --username=reporting --read-only
```

The CLI reads the password from piped standard input, or asks for it with a hidden prompt on a terminal; see [`database:user:create`](/cli/database/user-create).

A read-only user gets `SELECT` on the database. A regular MySQL user gets all database privileges; a regular PostgreSQL user gets the data-write grants described above. Running the command again sets the password and replaces the privileges. A connection without a server returns `database.server_required` (422). A connection on a `redis` server returns `database.driver_unsupported` (422): on Valkey, each user is a connection of its own. The user of a connection on the same server returns `database.name_conflict` (409), so the command never changes the password that a connection uses. The Gateway stores the user's password encrypted, so it can [publish it to Vaultwarden](/reference/vaultwarden#database-user-passwords). No response returns it.

### Logical replication users

Create a managed read login for a logical replication consumer such as PowerSync.

```bash
orbit database:user:create app --username=powersync --replication
```

`--replication` (API field `replication`, boolean, default false) requires a managed PostgreSQL server with live `wal_level=logical`. Orbit checks it before changing the role or grants. It refuses another driver with `database.driver_unsupported` (422) and another WAL level with `database.wal_level_not_logical` (422). Orbit does not change server configuration.

The user has `LOGIN REPLICATION`, database `CONNECT`, public-schema `USAGE`, and `SELECT` on all current tables and sequences. Default privileges give the same reads on future objects created by the connection's managed owner. Replication implies the read-only grants; adding `--read-only` is allowed and has the same result. The role has no superuser, CREATEDB, or CREATEROLE attribute. PostgreSQL's replication attribute is server-wide; publications and the database grants determine which application tables the consumer reads.

Repeating the command sets the password and replaces privileges and owner defaults. Omit `--replication` on a later call to remove the replication attribute. The same name-conflict rules apply: Orbit refuses any connection owner on the server, or an existing PostgreSQL role outside this connection. Orbit does not adopt hand-created replication roles. Use an unused name when moving an existing client to managed credentials.

User create returns an Operation. With `--replication`, its run first reads the live WAL level with one `docker exec -i`; another level skips the rest. The run then sends one batch with one `docker exec -i`: the name check, the login, then the grants.

The user then logs in over TCP with its own password and reads back its attributes and grants. MySQL compares `SHOW GRANTS`; PostgreSQL compares the replication attribute, database `CONNECT`, public-schema `USAGE` and the owner's default table privileges for the user. Each login reads its password from the first stdin line of a vault value, never from argv or a file. Completion records the credentials and grants. List responses include the recorded `replication` boolean.

Application migrations create and alter logical publications and select their tables. Orbit does not create publications or replication slots.

## How Orbit runs admin commands

Swamp runs each batch as one `docker exec -i` into the server container over official SSH from Node 209. The client in the image reads the login's password from the first stdin line, and the statements follow. PostgreSQL admin batches use local trust in the container instead of a password line. No password appears in command arguments, host environment variables, Docker exec metadata or files. A [read](/reference/database-connections#inspect-a-registered-connection) of a connection on the server runs the same way, as the connection's own user inside a `READ ONLY` transaction. A [write](/reference/database-connections#direct-sql-writes) runs the same way without the read-only transaction. The observer agent runs no database actions.

## Load a dump

[`database:import`](/cli/database/import) loads a plain or gzipped SQL dump into an empty database on a MySQL or PostgreSQL server. It logs in as the database's owner, never as the admin, so the server's own grants hold the dump to that owner's databases. The admin password is not used.

## Remove a database

`database:destroy` drops the primary database, the test database, the recorded test pool, and each user Orbit created for the connection that no other connection on the server uses. The plan comes from the records; unrecorded names are left alone. It deletes the record only after every database and unshared user is gone. A failed drop keeps the record; a repeat tolerates missing objects and finishes the remaining drops.

Standalone removal runs `operation-database-destroy` on Node 209 over official SSH, one `docker exec -i` per batch ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/database/destroy.mmd)). PostgreSQL first fences new clients. One batch then checks blockers for the whole set and drops, and the end check fails the run while a planned name remains. On Valkey the batch runs `ACL DELUSER` for each user and `ACL SAVE`. Each batch's standard input is one vault value: the admin password line, then the statements. The Gateway removes records through the shared terminal projector.

The Gateway admits the recorded resource identities and starts one native Swamp workflow. It performs no live probe, builds no SQL, and refuses a server Node without a stored SSH host key. System database names and prefixes are refused before SQL. Removal runs no ownership probe.

Before the first drop, Orbit checks the whole removal set. PostgreSQL sessions, replication slots (including inactive logical slots), prepared transactions, and subscriptions block removal. Dependencies of an unshared role outside the set also block it, including ownership and grants in another database or on a shared object. MySQL checks connected clients, metadata locks on the planned schemas (including transactions that use qualified table names without a default database), stored objects outside the set whose definer is a user being removed, and incoming cross-schema foreign keys. Cross-schema references between planned databases also block; remove those constraints first.

A blocker records `database.in_use` on the Operation and leaves databases, users, attachments, and records unchanged. Stop clients and remove or move the blocking dependencies, then retry. Orbit never force-disconnects clients. A server or transport failure after dropping starts can still interrupt removal; retain the record and repeat the same command to finish.

On a Valkey server, `database:destroy` deletes the connection's ACL user with `ACL DELUSER` and runs `ACL SAVE`. The user's keys stay. A connection without a username, such as one linked by adoption, logs in as `default`, so destroying it deletes only the record.

## Remove a server

`database:server:destroy <slug>` refuses with `database.server_in_use` (409) while a connection points to the server. A server without a Process has nothing on its Node, so the Gateway deletes the record at once and returns a succeeded Operation with no run (R1). Otherwise admission marks the server as removing, so new database creation refuses it, and starts `operation-database-server-destroy`. One `docker rm --force` over official SSH removes the container, and one `docker container inspect` must find it missing; that is the end check. The volume stays, so the data survives. Remove the volume on the Node by hand when its data must go.

The shared terminal subscriber deletes the Server and Process records only after native success. The run has no report step: a failed step marks the server failed with that step and its error code, and keeps both records; run the command again. Steps make no Gateway calls and need no database credentials.

A new Valkey server with the slug of a removed one finds the old volume. It writes a new ACL file there, so the old users are gone, but the old keys stay.

A Node that runs a server cannot be removed. [Node removal](/reference/node-provisioning#remove-a-node) refuses it with `node.has_database_servers`, also offline.

## Check a server with Doctor

The native database-server and database operations own server configuration, endpoint and credential validation. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat these checks; its Process family can inspect the registered server container state.

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
| `database.user_login_failed` | 502 | After `database:user:create`, the new user could not log in with its own password, or its login did not show the requested attributes and grants. |
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
| `database.destroy_incomplete` | 502 | A planned database or user is still present after the drops; run the command again. |
| `database.destroy_outcome_unknown` | 502 | The removal run ended without a readable result; run the command again. |
| `database.in_use` | 409 | A session, replication object, prepared transaction, or user dependency blocks the planned database removal. Nothing is dropped. |
| `database.name_conflict` | 409 | The name is taken on the server, a recorded test member has a foreign owner, or its managed role is missing. |
| `database.slug_conflict` | 409 | The connection slug exists and the request is not an identical PostgreSQL pool retry. |
| `process.required_by_database_server` | 409 | `process:destroy` names a server's Process, or a validated Valkey Process update violates the [image-update rule](#update-a-valkey-servers-image). |
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

With an ACL file, Valkey ignores `requirepass`. A file without a `default` line, or a start without `--aclfile`, gives every client full access without a password. So Orbit writes the file before the first start, always with a `default` line, and limits `process:update` to an image-only change within `valkey/valkey:<tag>`. The stored command and volume mounts stay, so an update cannot drop `--aclfile` or the ACL file mount. A missing or broken file stops Valkey instead of opening it. New servers require a password. A server adopted open before adoption was removed stays open until the operator [closes it](#close-an-adopted-server).

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

Database removal follows Orbit’s existing refusal rule for connected clients. `DROP DATABASE ... WITH (FORCE)` was rejected because it would disconnect clients without a separate choice and still fails on logical replication slots, subscriptions, and prepared transactions. Preflight checks every planned database and every role being removed before destructive SQL runs.

On PostgreSQL, the fence closes the worker-start race between that check and the drops. It journals each database's `ALLOW_CONNECTIONS` with its OID in `orbit_admin.database_removal_fences`, in the same transaction that closes it. After a failed step, `Restore the fence` gives each surviving database its journaled value back as `true` or `false`; the OID keeps it from changing a replacement database. A failed restore keeps the journal for the next removal.

PostgreSQL cannot run `DROP DATABASE` in a transaction, so an infrastructure failure still requires a retry. Avoid changing server dependencies through another admin connection during removal. Missing databases and users count as removed on retry.

### Direct default-user closure

`database:server:update --require-password` starts `operation-database-server-close` with captured public intent and two vault inputs. Native steps on 209 use official SSH and ship no script. The [flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/database/server-update.mmd) shows the steps. The Gateway records `default_open=false` only after the end check passed, and reads a failed `Prove no other client` as `database.server_in_use`; any other failed step is `database.server_command_failed`.

A first Valkey adoption refused before mutation releases only its reserved Database server record. Its existing Process, container, data volume and connection remain unchanged, so correcting the connection password permits another adoption. Later or uncertain failures retain their records for inspection.

A server recorded with `default_open=null` by an earlier release has unknown default access. ACL generation refuses that state until the operator verifies access.
