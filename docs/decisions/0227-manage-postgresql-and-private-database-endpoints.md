---
title: "0227: Manage PostgreSQL and private database endpoints"
description: "Extend the Database server lifecycle to PostgreSQL and publish managed databases safely on LAN and WireGuard."
---

# ADR 0227: Manage PostgreSQL and private database endpoints

## Status

In progress.

Principle: One way, one name; security fits the real threat model. Nick approved the scope on 2026-10-21. This PR does not authorize a production migration or a merge.

## Context

MySQL Database servers already reserve an encrypted admin password, create a Docker Node Process, wait for the final TCP server, remove the initialization password from the Process, and resume unfinished steps. Valkey uses the same server records and Process lifecycle with an ACL file. PostgreSQL connections, user-password publication to Vault, and PDO inspection already exist; PostgreSQL server administration does not.

Services (Node 17) already publishes PostgreSQL on 5432 (Process 80, version 16) and 5433 (Process 213, version 18). The target is one new managed PostgreSQL 18 server, with an explicit free port 5434. Process 118 belongs to the Recall Instance, uses pgvector and loopback, and stays outside this work.

## Decision

Extend the existing server lifecycle to PostgreSQL and protect explicit private endpoint publication for all drivers.

### Extend the existing server path

Accept `pgsql` in `database:server:create`, default to `postgres:18` and port 5432, and retain the existing explicit `--port` and collision check. MySQL uses a plain version tag, so PostgreSQL does too. PostgreSQL 18 puts PGDATA at `/var/lib/postgresql/18/docker`; mount the named volume at `/var/lib/postgresql`, not the pre-18 `/var/lib/postgresql/data`. Validate PostgreSQL tags as major 18 only (`18`, `18.N`, optionally with a variant suffix); it does not perform in-place major upgrades.

Reuse the MySQL provisioning steps, encrypted `root_password` field, Process lease, protected stdin transport, errors, and retry state. Pass `POSTGRES_PASSWORD` only during initialization. Probe an authenticated TCP SQL query inside the container, which excludes the image's temporary socket-only initialization server. Remove the variable and recreate the container after readiness. Never return SQL errors or passwords. The admin remains `postgres`.

Extend the SQL administration contract to select PostgreSQL execution and a target database. PostgreSQL `CREATE DATABASE` cannot run in a transaction: use psql autocommit with ON_ERROR_STOP and send separate statements. Make a LOGIN owner role and a database owned by it. Revoke PUBLIC database privileges and PUBLIC schema CREATE. Use a PostgreSQL renderer with double-quoted identifiers and quote-doubled literals, separate from MySQL escaping. Render passwords as client-generated SCRAM verifiers. Before replacing grants, revoke current table and sequence rights and the owner's default privileges. Additional users get CONNECT, schema USAGE, and SELECT (read-only) or data-write privileges. Grant current tables and sequences and ALTER DEFAULT PRIVILEGES FOR ROLE the owner for objects the owner creates after the grant in public. Only the managed owner creates application objects. Custom schemas require explicit operator grants; Orbit does not claim to manage them.

Keep PostgreSQL owner names within its 63-byte identifier limit and retain deterministic MySQL-style normalization. Do not give application users superuser or CREATEDB. Refuse Instance-owned PostgreSQL databases with `database.driver_unsupported`, including Instance creation with a PostgreSQL server. Laravel parallel tests require dynamic database creation, which stays outside this PR.

### Publish private endpoints for all three server drivers

Generate Docker publications for the Node's WireGuard IP and, when present, its LAN IP. Never bind a wildcard or public address. Retrying creation also reconciles publication changes for existing servers, without reintroducing an initialization password.

Keep the connection's stored `host` as WireGuard. Expose the managed server's LAN address as `lan_host`. An Instance attachment can explicitly request `network=lan`; store that choice with the attachment and use the same projection for attach, test environment, and Doctor. Transfer and clone reattachment explicitly reset to WireGuard. Doctor also checks aliases pointing at a managed LAN endpoint against the peer list. Store an explicit list of LAN peer Node IDs on each server, supplied by repeatable `--lan-node`. Refuse LAN selection unless both Nodes are active with IPv4 LAN addresses and the consumer belongs to that list or is the server Node itself. Services has no Cluster and must not join prod. Use the same policy for firewall sources and connection projection. Do not infer a subnet from matching IP octets. Ordinary attachments and Gateway SQL inspection retain WireGuard. External connections retain their explicit host.

Ordinary UFW INPUT rules do not restrict Docker DNAT traffic. Install a raw PREROUTING chain owned by Orbit before publication, persisted by an owned block in UFW's `before.init` hook. Restore only Orbit's chain and ensure exactly one PREROUTING jump across repeated reloads. Never append jumps through `before.rules`. Allowed traffic RETURNs to Docker's remaining raw rules; unmatched endpoint ingress drops. Publish IPv4 only. Allow all mesh peers through interface `orbit` to WireGuard (as today), exact active peer LAN source IPs to LAN, and local consumers through `docker0` to WireGuard. Keep the existing analytics INPUT hairpin rule for docker-proxy. Refuse publication when UFW is absent or inactive or live verification fails. Preserve unrelated hooks and rules, serialize writes, validate candidates, activate with rollback, and verify the live chain before publication. The database role owns Docker prerequisites and does not open database ports broadly.

Reconcile peer sources at the router LAN lifecycle triggers in ProvisionNodeAction (including LAN address changes) and RemoveNodeAction: widen before use, prune after persistence or removal. A stale source must not retain access. Doctor reports publication and filter drift in the database-server family. Creation retries repair drift; active MySQL retries may recreate the container. Removal stops the Process before pruning its filter. Repeat creation to reconcile a server's own address changes before consumers switch. Verify reload twice, disable/enable, reboot, and Docker restart on a disposable target, including exactly one jump.

### Move data through an operator runbook

Do not adopt arbitrary PostgreSQL Processes or discover their passwords. Create the new server, create managed target databases and users, and restore pg_dump custom archives with pg_restore --no-owner --no-acl as the target owner. Keep the original database names by using matching hyphenated slugs. Read-only inventory on 2026-10-21 confirmed IDs 9/10/11 use mealou-food-admin, mealou-food-reader, and mealou-food-writer; mealou-food-catalog is free and derives mealou_food_catalog. Existing connection IDs 9/10/11 remain external aliases: after restore, update their endpoint and each matching user's credentials, preserving IDs and attachments. Their canonical managed connection owns the target database. Never mark a read-only alias as a database owner. Create additional users through the canonical managed connection, then update aliases and reattach their consumers. Vault keeps publishing by stable source IDs.

The analytics role still takes postgres_process_id. When it names a managed PostgreSQL Process, resolve exactly one canonical plausible_db owner connection and its encrypted credentials, refusing missing or ambiguous records without an admin fallback instead of reading the removed POSTGRES_PASSWORD. Permit both WireGuard and the exact managed LAN publication in its storage validation. Keep support for the existing unmanaged version-16 Process. Accept version 18 only for a managed server Process. Register a core DatabaseConnectionRemovalGuard to protect the analytics owner connection and a DatabaseConnectionUpdateHook to refuse managed PostgreSQL identity or credential changes with database.server_connection_managed. Use existing provider tags and test their wiring. This adds no plug-in point and leaves Vault unchanged. Only enable a version-18 analytics target after the disposable rehearsal below passes.

Migrate mealou_food_catalog (~1.6 GB), device_catalog (~4 GB), then plausible_db (~14 MB), each only with Nick's separate go. Stop writers, take a final dump, restore, verify schema/row counts/extensions/owner/read-only and write behavior, repoint one consumer, check it, then the rest. Keep the source stopped or read-only and intact for rollback. Roll back before new writes by restoring old endpoints and analytics settings; after new writes, stop writers and reconcile or reverse-export before switching. New Hauzer and Mealou app databases are separate SQLite-to-PostgreSQL projects after this migration.

### Plausible compatibility is a measured gate

Upstream v3.2.1 ships postgres:16 and does not explicitly certify 17/18. In a disposable dev/test environment, create synthetic representative Plausible data on PostgreSQL 16, dump and restore it into PostgreSQL 18, run the full db createdb && db migrate && run startup as the non-superuser owner, and exercise authenticated dashboard query paths with disposable ClickHouse. Record exact images, commands, results and limits. No Services or production data is used. A failure keeps analytics on 16 and must be reported; a fixture rehearsal alone does not authorize the production move.

## Rejected alternatives

A separate PostgreSQL manager duplicates Process and retry ownership. A password left in Process environment defeats the encrypted server credential. A UFW allow rule alone leaves Docker forwarding unfiltered. A whole-subnet LAN allow admits unregistered machines. Automatic LAN selection guesses network topology and changes existing consumers. Importing source roles wholesale carries admin rights and stale grants. Replacing connection IDs breaks attachments and Vault identities. Upgrading analytics on Services to learn compatibility risks the live dashboard.

## Consequences

Add feature coverage beside MySQL server, database, user, attachment, Vault and Doctor tests: PostgreSQL defaults and explicit port collisions, password removal and retry, owner and read-only default privileges, failure cleanup, both private publications for every driver, no-LAN default, explicit LAN eligibility and stored projection, filtering before DNAT and safe failure/rollback. Run only affected tests with no more than four workers. Run a fresh independent model review of this design before implementation and a fresh code review before the one PR. Production migration and merge remain Nick's decisions.


## Affects

- ADRs: 0190, 0221
- Components: Gateway database actions, SQL infrastructure, private endpoint firewall, Node lifecycle, analytics, CLI, SDK, Vault, Doctor
- Detail: /reference/database-servers, /reference/database-connections, /reference/postgresql-migration
- Verify: affected feature tests, PostgreSQL grants, disposable Plausible startup and dashboard requests, persistent ingress checks
