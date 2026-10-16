---
title: "ADR 0221: Run Valkey Database servers with Orbit-owned ACL users"
sidebarTitle: "0221 Valkey servers"
description: "In progress. database:server:create --driver=redis runs Valkey as a Database server that starts only from an Orbit-owned ACL file. The Gateway holds the admin password, and database:create --server adds one ACL user per Redis Database connection. A one-time --process option adopts the Valkey Process on services."
---

# ADR 0221: Run Valkey Database servers with Orbit-owned ACL users

`orbit database:server:create <slug> --driver=redis` runs Valkey as a [Database server](/reference/database-servers). The Gateway generates the admin password and keeps it encrypted. Valkey starts only from an ACL file that Orbit writes, so no path starts it without a password. `orbit database:create <slug> --server=<server>` adds one ACL user with the key patterns and commands that the caller names, and records a Redis Database connection for it. A one-time `--process` option brings the existing Valkey Process on `services` under Orbit without moving its data.

## Status

In progress.

Principle: [security fits the real threat model](/mission#principles) and [one way, one name](/mission#principles). Every Valkey client gets only the keys and commands it needs, and Valkey servers are set up the same way as MySQL servers. Exception, during the build only, to [no exceptions and no legacy](/mission#principles): the `--process` and `--connection` options of `database:server:create` exist only to adopt Process 162 on `services`. Once Process 162 is a Database server, a pull request removes them and retires this ADR.

## Context

The Valkey on `services` is Docker Process 162, an operator-made Process with the Database connection `services-valkey`. The [Gateway queue](/reference/gateway-queue) and the [ProxyCli collector](/reference/proxycli) both log in with its one password, so each client can read and delete the keys of the other. The Process writes that password from its environment into `requirepass` at each start. The environment is stored as plain JSON in the Gateway and shows in `docker inspect`.

A first version of pull request #1296 documented a manual ACL file with a separate user for the queue. The review blocked it: the users and passwords lived outside Orbit, so Doctor had nothing to check them against, and the setup failed open. With an ACL file, Valkey ignores `requirepass`. Without a `default` line in the file, or without `--aclfile` in the command, Valkey accepts every client without a password. A `process:update` without `--aclfile` opens the server to every WireGuard peer.

[ADR 0190](/decisions/0190-run-mysql-servers-with-a-gateway-held-admin-password) solved the same problem for MySQL: the Gateway owns the server and its admin password, and creates each database and user.

## Decision

The Gateway owns Valkey servers as it owns MySQL servers: it creates the server, holds the admin password, and creates every user.

### A driver on the server

A Database server has a `driver`: `mysql`, the default, or `redis`. A `redis` server runs Valkey. `--tag` defaults to `8.1` and `--port` to `6379` for it. The admin password keeps its place on the server record, encrypted.

### Create a Valkey server

`orbit database:server:create <slug> --node=NODE --driver=redis [--tag=TAG] [--port=PORT]` does this in order:

1. It generates a random admin password and stores it encrypted on a new server record.
2. It writes `/data/users.acl` into the volume `orbit-<slug>-data` with a short-lived container. The file has one line: `user default on #<sha256 of the admin password> ~* &* +@all`.
3. It creates and starts a Docker Node Process named `<slug>` with image `valkey/valkey:<tag>` and the command `valkey-server --appendonly yes --dir /data --aclfile /data/users.acl`. The Process has no environment.
4. It waits until `default` can log in with the admin password.

`default` is the admin user, and it always has the Gateway's password. No Process command, environment, or Activity entry holds a password. Valkey refuses to start when the ACL file is missing or has a wrong line, so a damaged setup fails closed. A retry continues from the first step that did not finish.

### Admin commands

The Gateway connects to the server's WireGuard address and published port, the address every client uses, and logs in as `default`. It runs `ACL SETUSER`, `ACL DELUSER`, `ACL SAVE`, and `ACL LIST`. A user's password reaches Valkey only as a SHA-256 hash. The ACL file is the only copy of the users on the Node, and `ACL SAVE` keeps it current.

### One ACL user per connection

`orbit database:create <slug> --server=SERVER --pattern=PATTERN ... --allow=RULE ... [--database=INDEX]` on a `redis` server:

1. It generates a password and runs `ACL SETUSER <slug> reset on #<hash> resetchannels ~<pattern> ... -@all +<rule> ... -@admin -@dangerous`, then `ACL SAVE`.
2. It records a `redis` connection with the Node's WireGuard address, the server's port, `--database`, the username `<slug>`, the encrypted password, and the server link. It also records the user with the rules that Valkey reports.

Orbit adds `reset`, the password, `resetchannels`, `-@all`, and the final `-@admin -@dangerous`. So no user gets pub/sub channels, an `@admin` command, or a `@dangerous` command, and no user can change the ACL. `database:destroy` of the connection runs `ACL DELUSER` and `ACL SAVE`. The user's keys stay.

`--instance`, `instance:create --database-server`, the Instance database clone, and `database:user:create` refuse a `redis` server with `database.driver_unsupported`.

### The Process belongs to the server

`process:update` refuses the Process of a `redis` server with `process.required_by_database_server`, as `process:destroy` already refuses every server Process. So nobody can drop `--aclfile` from the command or add an environment value such as `VALKEY_EXTRA_FLAGS`. `process:start`, `process:stop`, and `process:restart` stay allowed: they keep the command, and the ACL file stays in the volume.

### Doctor checks the server

A new Doctor family, `database_server`, checks each Valkey server whose Process has the desired state `running`:

- A client without a password gets no access.
- Valkey runs with the ACL file `/data/users.acl`.
- The live users equal the recorded users: `default` with the admin password and all rights, and one user for each connection on the server, with its password hash and rules.

### Adopt Process 162

`orbit database:server:create <slug> --driver=redis --node=NODE --process=ID --connection=CONNECTION` adopts an existing Valkey Process. It refuses with `database.server_adoption_refused` and changes nothing unless all of these hold:

- The Process is a Docker Process of that Node with a `valkey/valkey` image and a volume at `/data`.
- It publishes container port 6379 on the Node's WireGuard address.
- No server owns the Process.
- The connection is a `redis` connection to that address and port, has a password, and that password logs in as `default`.

Then the Gateway uses that password as the admin password. It writes `/data/users.acl` into the Process's volume, and replaces the Process specification with the server specification. The ID, name, volume, and port stay. It waits for the login, and links the connection to the server. Valkey restarts once. It reads its append-only file again, so queued jobs stay, and the connection's clients keep working as `default`.

### The collector gets its own user later

The ProxyCli collector and the Gateway's snapshot reads keep using `services-valkey`, and so `default`, until step 3 of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension#build-order) renames their keys to `orbit:ai-router:*`. Before step 4, the operator creates a connection on the server with the pattern `orbit:ai-router:*`, and step 4 binds it with `--cache-connection`. The admin password then has no client outside the Gateway. A rotation of that password is a follow-up of its own.

## Rejected alternatives

- A manual ACL file and passwords in files on the Node: Orbit does not record them, Doctor cannot check them, and a Process change opens the server.
- A separate admin user with `default` switched off: a client that logs in with only a password, such as the collector, stops working. `default` with a password the Gateway holds is just as closed.
- Admin commands through SSH and `valkey-cli` inside the container, as for MySQL: `valkey-cli` exits 0 on an error reply when it reads commands from standard input. The Gateway already reaches Valkey over WireGuard for the queue, so only the first ACL file needs a Node program.
- `database:user:create` for Valkey users: it adds a user to a database that a connection has, and records no connection. On Valkey, one user is one connection.
- A raw ACL rule string as input: Orbit must own the reset, the password, the channels, and the final deny, so the input is the key patterns and the allowed commands only.
- A new server for `services` and a move of the data: it needs a queue outage or a copy tool, a release, and a new setup of the collector, against one restart for adoption.
- Refusing `process:update` for MySQL servers too: MySQL keeps its users in its data directory, so a specification change cannot open it, and an image update of a MySQL server still uses `process:update`.

## Consequences

- The Gateway queue gets a user that reaches only its own keys.
- A Valkey server cannot start without a password, and Doctor reports a server that runs without one.
- A Valkey server's image tag cannot change through `process:update`. A command for that is a follow-up.
- Until the collector has its own user, the admin password of the adopted server is also in the collector's environment.
- Key patterns do not limit the database index: Valkey 8 has no ACL rule for it.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: [0190](/decisions/0190-run-mysql-servers-with-a-gateway-held-admin-password) gains a driver; [0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension) binds the collector's own connection at step 4
- Detail: [Database servers](/reference/database-servers), [Database connections](/reference/database-connections), [Gateway queue: ACL users](/reference/gateway-queue#acl-users), [`doctor`](/cli/doctor)
- Verify: Gateway tests for Valkey server creation, retry, adoption, the ACL user create and destroy, the `process:update` refusal, and the Doctor family; a test against a real Valkey container
