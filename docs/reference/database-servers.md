---
title: "Database servers"
description: "How Orbit runs a MySQL, PostgreSQL or Valkey server as a Docker Node Process, keeps its admin password only in the Gateway, and creates databases and ACL users on it."
covers:
  - apps/gateway/app/Actions/DatabaseServers/**
  - apps/gateway/app/Actions/DatabaseConnections/{CreateServerDatabaseAction,CreateDatabaseUserAction,DropServerDatabaseAction}.php
  - apps/gateway/app/{Domain,Infrastructure}/DatabaseServers/**
  - apps/gateway/app/Http/{Controllers/Api/DatabaseServersController.php,Requests/DatabaseServers/**}
  - apps/gateway/app/Models/DatabaseServer.php
  - apps/gateway/app/Infrastructure/Firewall/{NativeDatabaseServerFirewall.php,database_server_firewall.py}
  - apps/cli/app/Commands/Database/{CreateDatabaseServerCommand,ListDatabaseServersCommand,ShowDatabaseServerCommand,DestroyDatabaseServerCommand,CreateDatabaseUserCommand}.php
  - packages/php-sdk/src/{Requests,Responses}/DatabaseServers/**
---

# Database servers

A Database server is a MySQL, PostgreSQL or Valkey server that Orbit runs and administers. It runs as a Docker [Node Process](/reference/processes-and-schedules#owners), and the Gateway keeps its admin password. Orbit uses that password to create a database or an ACL user, and to [clone](/domains/applications#database-clone) the default Instance's MySQL database. [`database:server`](/cli/database#orbit-databaseservercreate) lists the commands.

A server's `driver` names its kind. A `mysql` server runs MySQL, a `pgsql` server runs PostgreSQL 18, and a `redis` server runs Valkey. A Valkey server's clients use the [`redis` driver](/reference/database-connections#drivers-and-fields), so the server uses the same name.

## Create a server

`database:server:create` creates the server and its Process in one command.

```bash
orbit database:server:create beast-mysql --node=beast
orbit database:server:create beast-valkey --node=beast --driver=redis
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--node=NODE` | required | Numeric Node ID or Node name. The Node must be an active Linux Node with Docker. |
| `--driver=DRIVER` | `mysql` | `mysql`, `pgsql` or `redis`. |
| `--tag=TAG` | `8.4` for mysql, `18` for pgsql, `8.1` for redis | Image tag. The image is `mysql:<tag>`, `postgres:<tag>` or `valkey/valkey:<tag>`. |
| `--lan-node=NODE` | none | Repeat to name active Nodes allowed to use the LAN endpoint. A create retry replaces the set; omission preserves it. The API accepts an empty list to clear it. |
| `--port=PORT` | `3306` for mysql, `5432` for pgsql, `6379` for redis | Port published on the Node's WireGuard address and its LAN address when present. |

### A MySQL server

The Gateway creates a MySQL server in this order:

1. It generates a random root password and stores it encrypted on the server record.
2. It creates a Docker Node Process named `<slug>` with image `mysql:<tag>`, the port published on the Node's WireGuard address to container port `3306`, and the volume `orbit-<slug>-data` for `/var/lib/mysql`.
3. It starts the Process with `MYSQL_ROOT_PASSWORD` set and waits until MySQL accepts the root login.
4. It removes the variable from the Process and recreates the container. The data volume keeps the password.

After step 4, the root password exists only encrypted in the Gateway. It is not in the Process record, the container environment, `docker inspect`, or a file on the Node. Orbit waits for the root login over TCP inside the container, which only the final MySQL server accepts, so it never recreates the container while MySQL still initializes its data directory.

### A PostgreSQL server

Create PostgreSQL 18 on an explicit free port:

```bash
orbit database:server:create services-postgres --node=17 --driver=pgsql --port=5434 --lan-node=app-prod --lan-node=gateway
```

PostgreSQL follows the MySQL steps with `postgres:<tag>`, command `postgres`, container port 5432, and first-start variable `POSTGRES_PASSWORD`. The default tag is `18`; accepted tags are `18`, `18.N`, and these versions with an image variant suffix. Other majors and `latest` return `validation.failed`. The named volume mounts at `/var/lib/postgresql`, because PostgreSQL 18 stores data in `/var/lib/postgresql/18/docker`. The pre-18 mount `/var/lib/postgresql/data` is wrong for this image. Readiness runs an authenticated TCP SQL query; the image's temporary initialization server is socket-only. A retry reuses the encrypted password, Process and volume, and finishes password removal before returning active.

A PostgreSQL database belongs to its LOGIN owner role. The role has no superuser or CREATEDB rights. Orbit revokes PUBLIC database privileges and PUBLIC schema CREATE. Additional users have CONNECT and public-schema USAGE. A read-only user gets SELECT on current tables and owner default privileges for future tables; a write user also gets data-write and sequence privileges. Repeating user creation revokes rights on current tables and sequences and owner defaults before granting again. PostgreSQL passwords must be printable ASCII; the Gateway sends SCRAM verifiers to avoid plaintext SQL log entries.

Create application objects as the managed owner. Custom schemas need explicit operator grants. Instance-owned PostgreSQL databases are refused with `database.driver_unsupported`. Laravel parallel testing needs dynamic database creation, which this feature does not grant.

[Move PostgreSQL databases](/reference/postgresql-migration) gives the dump, restore, verification and rollback procedure. Creating a server does not adopt or upgrade any existing Process.

### Private publication and filtering

All three drivers publish on WireGuard and, when set, `lan_ip`. The stored connection host stays WireGuard. [LAN attachments](/reference/database-connections#choose-lan-for-an-instance) explicitly select LAN for an active consumer in the server LAN peer list. A server never publishes on a wildcard or public address.

Only IPv4 is published. LAN traffic is limited to the server Node and explicitly selected active peer Nodes. WireGuard admits all mesh peers, as today. Orbit installs a raw PREROUTING chain before Docker DNAT with an owned UFW `before.init` hook. Allowed traffic returns to Docker's own rules; other endpoint ingress drops. UFW must be installed and active or creation fails before publication.

An owned INPUT chain permits only exact private endpoint hairpin traffic from `docker0`, including the LAN endpoint for local containers. A Docker service drop-in starts after UFW and restores the filter before daemon startup. Its `Wants=` dependency does not propagate an operator UFW stop or restart to Docker. A failed check prevents Docker startup. The database role adds no broad port allow.

Filtering is enabled for a server only by its explicit create/retry. Existing servers stay unchanged until their operators retry create. Doctor checks publication and filter drift only after this choice; SQL login and Valkey ACL checks still run. Creating PostgreSQL on Services does not filter an existing Valkey server there. Node provisioning, LAN address changes and removal reconcile only enabled servers on that Node or naming it as a LAN peer. An unrelated enrollment never contacts database hosts.

Expansion failure keeps the old peer identity; removal failure occurs before other removal effects. Server address changes require a create retry before switching clients; the old Process binding stays filtered until replaced. Retrying an active MySQL server can recreate its container to repair publication drift.

Doctor reports `database_server.publication_mismatch`, `database_server.filter_mismatch`, `database_server.login_failed`, and `database_server.initialization_secret_present` in the `database-server` family. Repeat creation repairs filter and publication drift. A failed repair keeps an active server active and records its failed step. Doctor inspection writes no files on the Node.

### A Valkey server

The Gateway creates a Valkey server in this order:

1. It generates a random admin password and stores it encrypted on the server record.
2. It writes the ACL file `/data/users.acl` into the volume `orbit-<slug>-data` with a short-lived container. The file has one line, `user default on #<sha256 of the admin password> ~* &* +@all`.
3. It creates and starts a Docker Node Process named `<slug>` with image `valkey/valkey:<tag>`, the volume at `/data`, and no environment.
4. It waits until the user `default` can log in with the admin password.

The Process publishes its port on the Node's WireGuard address to container port `6379`, and runs `valkey-server --appendonly yes --dir /data --aclfile /data/users.acl`. `default` is the admin user, and it always has the Gateway's password. The ACL file holds a SHA-256 hash of it, never the password. Valkey refuses to start when the ACL file is missing or has a wrong line, so a damaged server stays closed. The append-only file in the volume keeps the data across a restart.

### Retry and response

A retry of the same request continues from the first step that did not finish. A request with the slug of an existing server and another Node, driver, tag, or port returns `database.server_slug_conflict`. A repeat of a finished request returns the server with status 200. On an active Valkey server, the repeat first [repairs the server](#repair-a-valkey-server).

The response holds `id`, `slug`, `driver`, `node_id`, `process_id`, `tag`, `port`, `status`, and `databases_count`. `database:server:show` also returns `databases`, the connections on the server. No response, Activity entry, or error contains the password.

The server's Process is an ordinary Docker Node Process: read its logs, stop it, start it, or restart it with the [`process`](/cli/process) commands. `process:destroy` refuses it with `process.required_by_database_server`; remove the server instead. `process:update` refuses the Process of a `redis` server with the same code, so the command always keeps `--aclfile`.

## Adopt a Valkey Process

`database:server:create` with `--process` and `--connection` brings an existing Valkey Process under Orbit, without a new volume and without a data move. The option exists only to adopt the `valkey` Processes on `services` and `beast` ([ADR 0221](/decisions/0221-run-valkey-database-servers-with-orbit-owned-acl-users)). A later version removes it.

```bash
orbit database:server:create services-valkey --node=services --driver=redis --process=162 --connection=services-valkey
```

The Gateway refuses with `database.server_adoption_refused` and changes nothing unless all of these hold:

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

Then the Gateway writes `/data/users.acl` into the Process's volume, and replaces the Process specification with the one above. The Process keeps its ID, name, volume, and port. It waits for the admin login, and links the connection to the server. Valkey restarts once and reads its append-only file again, so its data stays. The connection's clients keep logging in as `default` as before.

## Close an adopted server

`database:server:close` gives `default` the admin password, so a client without a password gets no access. Run it after each client of an open server has [its own user](#create-an-acl-user-on-a-valkey-server).

```bash
orbit database:server:close beast-valkey
```

The Gateway runs `ACL SETUSER default resetpass #<sha256 of the admin password>` and `ACL SAVE`. It refuses with `database.server_in_use` (409) and changes nothing while either of these holds:

- A connection on the server has no password. Its clients would be locked out. Destroy it, or move its clients first.
- A client other than the Gateway is logged in as `default`. The message gives the number of clients and their addresses.

After saving the password, the Gateway revokes every other `default` session, including one accepted between the client check and the password change. A retry repeats the save and revocation before it records closure. A client that is not connected at that moment is not seen, so move every client before you close the server. Like adoption, the command exists only for the two adopted Processes.

## Create a database on a server

`database:create` with `--server` creates a database and an owner user on a MySQL or PostgreSQL server, then records the [connection](/reference/database-connections).

```bash
orbit database:create dlf-leden --server=beast-mysql --instance=60
```

The database name comes from the slug, with hyphens replaced by underscores. On MySQL, a database name longer than 64 characters, or a user name longer than 32, ends in an underscore and an 8-character hash. PostgreSQL names are limited to 63 ASCII characters and use the same hash suffix when shortened. Orbit generates the owner password.

On MySQL, each Instance has one owner user on a server, named `<project>_<instance>` like its databases. A second database for the same Instance reuses that user and its password. That user can reach only the databases the Instance owns and their test databases. A database created without `--instance` gets its own user, named like the database. The connection gets the server's driver, the server's Node address and port, and a link to the server.

Orbit refuses a name with `database.name_conflict` when the server already has that database or user, or when the name starts with the test database name of another database on the server. So one Instance's user never reaches another database through its test database grant. A create that fails drops what it made and records nothing.

On MySQL, `--instance` also creates the test database `<name>_test`, attaches the connection to the Instance under prefix `DB`, and records the Instance as the owner. [Test databases](/reference/database-connections#test-databases) describes how tests use it.

`database:create --instance` needs an existing Instance, so it comes after that Instance's setup steps. To have the database before setup runs, pass the server to [`instance:create --database-server`](/domains/applications#database-on-a-server) instead.

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

The connection gets driver `redis`, the server's Node address and port, the `--database` index, the username `<slug>`, the encrypted password, and a link to the server. Orbit also records the user with the rules that Valkey reports, so [Doctor](#check-a-valkey-server-with-doctor) can compare them. `database:user:list` shows those rules.

The slug `default` is refused. A user that the server already has, and that Orbit did not record, returns `database.name_conflict`. A rule that Valkey does not know returns `database.acl_rules_invalid` with Valkey's message. A create that fails removes the user and records nothing. Concurrent ACL changes return `database.server_in_use`; retry after the other command finishes.

`database:update` can change the database index, but cannot change a managed Valkey connection's driver, Node, address, port, username, or password. Such a change returns `database.server_connection_managed` (409). Create a new connection and move its clients instead. Core applies this validation through the [typed connection update hook](/reference/extensions#plug-in-points). Connection removal runs every removal guard before it deletes an ACL user or its records.

Key patterns limit keys, not the database index. Valkey 8 has no ACL rule for an index, so a user can select any index, and its patterns apply in each one. `SCAN` and `DBSIZE` show the names and the count of all keys in an index, but no values. A user that can write can also fill the server's memory, because Orbit sets no memory limit.

`--instance`, [`instance:create --database-server`](/domains/applications#database-on-a-server), and the Instance database clone refuse a `redis` server with `database.driver_unsupported`.

## Add a user

`database:user:create` adds a user to a database on a MySQL server, for example a read-only user in production. `database:user:list` lists the users that Orbit created for a connection.

```bash
orbit database:user:create dlf-leden --username=reporting --password=SECRET --read-only
```

A read-only user gets `SELECT` on the database. Any other user gets all privileges on it. Running the command again sets the password and replaces the privileges. A connection without a server returns `database.server_required` (422). A connection on a `redis` server returns `database.driver_unsupported` (422): on Valkey, each user is a connection of its own. The user of a connection on the same server returns `database.name_conflict` (409), so the command never changes the password that a connection uses. The Gateway stores the user's password encrypted, so it can [publish it to Vaultwarden](/reference/vaultwarden#database-user-passwords). No response returns it.

## How Orbit runs admin commands

On a MySQL server, every admin command runs `mysql` or `mysqldump` inside the server's container with `docker exec`, over SSH. The root password travels on protected standard input, and the remote script hands it to `docker exec` as `MYSQL_PWD` through the environment. The SQL follows it on standard input. Neither appears as an argument, in a file, or in a log.

On a Valkey server, the Gateway connects to the Node's WireGuard address and the server's port, as every client does, and logs in as `default`. A user's password goes to Valkey only as a SHA-256 hash. The only Node program writes the ACL file, when a server is created and when a [repair](#repair-a-valkey-server) rebuilds it. It receives the file on standard input and writes it through a short-lived container that mounts the volume. The file holds only password hashes.

## Remove a database

`database:destroy` of a connection on a MySQL server drops the database, every database whose name starts with its test database name, and each user Orbit created for it that no other connection on the server uses. Then it deletes the record. A failed drop keeps the record, and a repeat drops again.

On a Valkey server, `database:destroy` deletes the connection's ACL user with `ACL DELUSER` and runs `ACL SAVE`. The user's keys stay. A connection without a username, such as one linked by adoption, logs in as `default`, so destroying it deletes only the record.

## Remove a server

`database:server:destroy <slug>` refuses with `database.server_in_use` (409) while a connection points to the server. Otherwise it removes the Process and the server record. Valkey removal holds the ACL lock across both steps, so no user can be created during removal. It keeps the volume, so the data survives. Remove the volume on the Node by hand when its data must go.

A new Valkey server with the slug of a removed one finds the old volume. It writes a new ACL file there, so the old users are gone, but the old keys stay.

A Node that runs a server cannot be removed. [Node removal](/reference/node-provisioning#remove-a-node) refuses it with `node.has_database_servers`, also offline.

## Check a Valkey server with Doctor

The [Doctor](/cli/doctor) family `database_server` checks each Valkey server whose Process has the desired state `running`. It connects as the Gateway does and reports drift when:

| Code | Meaning | Remedy |
| --- | --- | --- |
| `database_server.open` | A client without a password can run a command. | On an adopted server, [close it](#close-an-adopted-server) after its clients have moved. On another server, [repair it](#repair-a-valkey-server). |
| `database_server.acl_file_missing` | Valkey does not run with the ACL file `/data/users.acl`. | [Repair the server](#repair-a-valkey-server). |
| `database_server.acl_mismatch` | The live users differ from the recorded users. | [Repair the server](#repair-a-valkey-server). |
| `database_server.inspection_failed` | Doctor could not connect, or `default` could not log in with the admin password. | Check that the Node and the server's Process run, then [repair the server](#repair-a-valkey-server). |

The recorded users are `default` with the admin password and all rights, and one user for each connection on the server that has a username, with its password and rules. A user that someone added by hand is drift too.

Doctor compares only the rule tokens Orbit sets. Payload sanitization flags belong to Valkey and do not count as drift. The order of flags, key patterns, and channel rules does not matter. The order of command rules does.

An adopted server that is not closed yet reports only `database_server.open`, because Doctor compares its `default` only after the close. The report names no user, rule, or hash. MySQL servers have no checks in this family yet.

## Repair a Valkey server

Run the server's `database:server:create` command again to make an active Valkey server match its records. The arguments must match the server, as for any [retry](#retry-and-response).

```bash
orbit database:server:create beast-valkey --node=beast --driver=redis
```

The Gateway takes these steps:

1. It checks that `default` logs in with the admin password and that Valkey runs with `/data/users.acl`.
2. If either check fails, or admin commands cannot restore the ACL, it rebuilds the file and restarts the Process.
3. It runs `ACL SETUSER <name> reset <rules> #<hash>` for each recorded user that is missing or differs. It runs `ACL DELUSER` for each other user, except the `default` of an open server.
4. It runs `ACL SAVE`.

Rebuilding the file repairs a server that cannot start because the file is lost. The Gateway waits for the admin login before it retries ACL commands. It uses the same program as creation. The file holds `default` and one line per recorded user, `user <name> #<sha256 of the password> <rules>`. The restart uses [`process:restart`](/cli/process).

A server that already matches gets only `ACL SAVE`, so the repair is safe to repeat. Keys stay. A client of a deleted user loses its connection. A failed step returns its error and leaves the server active, so run the command again. A repair takes the lock of the other ACL changes, so a concurrent change returns `database.server_in_use`. A repeat on a MySQL server repairs private publication and filtering.

## Errors

The server commands return these codes.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `database.server_slug_conflict` | 409 | Another server has the slug. |
| `database.server_port_in_use` | 409 | Another Process on the Node publishes the port. |
| `database.server_start_failed` | 502 | The server did not accept the admin login within 120 seconds. |
| `database.server_in_use` | 409 | A connection still points to the server, an ACL change is running, or closing would lock out a client. |
| `database.server_connection_managed` | 409 | A change would replace a managed PostgreSQL or Valkey connection's identity or credentials. |
| `database.server_inactive` | 409 | The server is not active yet. Run `database:server:create` again to finish it. |
| `database.server_adoption_refused` | 409 | The Process or the connection cannot be adopted. The message names the check that failed. |
| `database.server_required` | 422 | The connection has no server, so Orbit cannot create a user on it. |
| `database.driver_unsupported` | 422 | The driver does not support this operation, including Instance-owned PostgreSQL databases. |
| `database.server_firewall_failed` | 502 | The private endpoint filter could not be applied or verified. |
| `database.lan_unavailable` | 422 | The server or consumer is not eligible for the selected LAN endpoint. |
| `database.required_by_analytics` | 409 | Analytics still uses this connection; move the role before destroying it. |
| `database.acl_rules_invalid` | 422 | Valkey refused a key pattern or a rule. |
| `database.name_conflict` | 409 | The name is taken on the server. |
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

`valkey-cli` exits 0 on an error reply when it reads commands from standard input, so a script inside the container cannot tell a refused `ACL SETUSER` from a done one. The Gateway already reaches each server over WireGuard, like every client. So it runs the admin commands itself and reads each reply, and only the first ACL file needs a Node program.

### Adoption without a data move

The Valkey on `services` holds the Gateway queue and the collector's state, and the Gateway already holds its password. Adoption keeps the volume and the port and restarts Valkey once. A new server and a move of the data would need a queue outage or a copy tool, a release, and a new collector setup. Adoption is refused for MySQL, whose root password may be unknown.

### PostgreSQL shares the SQL server lifecycle

PostgreSQL uses the existing MySQL Process, encrypted admin credential, provisioning steps, connection and user actions. A separate manager would duplicate ownership and retry rules. PostgreSQL has its own statement renderer because its identifier and literal escaping differ from MySQL. SCRAM verifiers keep application passwords out of server SQL logs. Restricting the image to PostgreSQL 18 preserves the documented volume layout when the first-start container is recreated.

### LAN access names its peers

A shared Cluster does not prove LAN reachability. Services has no Cluster and must not join prod to serve app-prod. Explicit peer Nodes define both the connection projection and firewall source list. WireGuard stays the default; automatic selection would change existing consumers. A subnet allow would admit unregistered machines. UFW INPUT alone cannot filter Docker DNAT, so the owned raw chain runs first and returns allowed traffic to Docker. Enabling filtering through an explicit create retry gives existing servers an operator-controlled rollout.

### Migration preserves connection identities

A new canonical connection owns each restored database. Existing external connection IDs and their attachments stay intact, with their endpoints and application credentials updated. Replacing those IDs would break attachment and Vault identities. Importing source roles wholesale could carry superuser rights and stale grants. Plausible's PostgreSQL 18 target needs a disposable rehearsal because upstream does not certify that major; Services is not a compatibility test target.
