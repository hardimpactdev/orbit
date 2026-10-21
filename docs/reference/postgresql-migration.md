---
title: "Move PostgreSQL databases to a managed server"
description: "Rehearse, restore, verify and cut over one PostgreSQL database at a time, with rollback."
---

# Move PostgreSQL databases to a managed server

Create a new [managed server](/reference/database-servers) and move each database with `pg_dump` and `pg_restore`. This is an operator procedure, not a Gateway migration. Nick approves each production cutover separately. Keep every source volume and Process until verification and the rollback window end.

## Scope and stop conditions

The planned target is one PostgreSQL 18 server on Services (Node 17), on a verified free port such as 5434. Port 5432 belongs to analytics-postgres (Process 80, PostgreSQL 16); 5433 belongs to food-postgres (Process 213, PostgreSQL 18). Confirm current records and listeners before selecting a port. Do not stop, edit, upgrade or remove the Recall Instance's index-postgres (Process 118, pgvector, loopback only).

| Order | Database | Source | Approximate size | Gate |
| --- | --- | --- | --- | --- |
| 1 | `mealou_food_catalog` | food-postgres, 213 | 1.6 GB | Nick's go; catalogue checks and writer pause |
| 2 | `device_catalog` | food-postgres, 213 | 4 GB | Nick's go; Hauzer EPREL catalogue checks and writer pause |
| 3 | `plausible_db` | analytics-postgres, 80 | 14 MB | Nick's go; successful disposable Plausible 3.2.1 rehearsal |

The later Hauzer and Mealou app databases are SQLite on app-prod. A logical PostgreSQL restore cannot migrate SQLite. Move those in separate application changes with schema, type, sequence, query and rollback checks.

## Rehearse Plausible first

Use disposable dev/test storage and a separate Docker network with no production routes or credentials. Pin `ghcr.io/plausible/community-edition:v3.2.1`, `postgres:16`, `postgres:18`, and the ClickHouse version currently used by that Plausible release. Record image digests. Upstream's compose pins PostgreSQL 16; its wiki says 16 or later but does not certify 17 or 18.

1. Run Plausible's own `/entrypoint.sh db createdb` and `/entrypoint.sh db migrate` against disposable PostgreSQL 16 and ClickHouse.
2. Create a test account and site, ingest test pageviews, and exercise the site's dashboard with a known date range.
3. Stop the disposable application. Dump `plausible_db` with a version-18 `pg_dump -Fc` client. Restore into a database owned by its managed application role on PostgreSQL 18 with `pg_restore --exit-on-error --no-owner --no-acl --role=<owner>`.
4. Point the same Plausible image at PostgreSQL 18 and run `/entrypoint.sh db migrate`. Start it, sign in, and check dashboard summary, timeseries, top pages, sources, countries, filters, realtime and date comparisons. Check logs for SQL errors. Verify that the same account, site and events remain visible.
5. Record commands, exit codes, migration output without credentials, HTTP results and any untested paths. A synthetic rehearsal does not prove every production query or extension.

If restore, migration or a dashboard path fails, stop. Keep analytics on PostgreSQL 16 and report the error. Do not bypass the version gate to test on Services.

## Inventory before each move

Read `database:list --json`, `database:show <slug> --json`, `database:user:list <slug> --json`, `process:show <id> --json`, and analytics role settings. Store an encrypted rollback inventory with the connection IDs, slugs, driver, Node, host, port, database, usernames, each user's grants, attachment prefixes, consumer Process IDs and current analytics settings. Get secret values from Vault through its private workflow; never print them or place them in shell history or command arguments.

Connections 9, 10 and 11 are the existing `mealou-food-*` connections. Resolve each ID to its exact current slug and database; do not assume all three point to one database or have the same rights. Inventory consumers of `device_catalog` separately. Check source extensions, collations, encodings, schemas, owners, table and sequence counts, large objects and database size. The managed grant policy covers `public`; custom schemas need reviewed explicit grants. Do not transfer source superuser attributes or wholesale `pg_dumpall` roles.

Check free disk for a dump, restored database and rollback copy. Test backups in the disposable target. Disable scheduled imports, refreshes and jobs for the database being moved; record how to resume each. Identify all writers before the final dump.

## Create the target after approval

```bash
orbit database:server:create services-postgres --node=17 --driver=pgsql --port=5434
orbit database:create mealou-food-catalog --server=services-postgres
orbit database:create device-catalog --server=services-postgres
```

Run only the database-create command for the database whose move Nick approved. The hyphenated slugs derive `mealou_food_catalog` and `device_catalog`. The canonical managed connection owns the database and its owner credentials are encrypted in the Gateway and published by `vault:sync`. The source Processes and existing connection IDs stay intact. Confirm both private publications, intended-source filtering and Doctor before copying data. Do not change a Node's LAN address as part of this procedure.

## Dump and restore one database

Pause its writers and confirm the source stops changing. Take the final custom-format archive with PostgreSQL 18 client tools. Use a private `PGPASSFILE` (0600) or protected standard input; disable shell tracing. Keep dumps in a private directory (0700), encrypted at rest, with a checksum recorded outside command output containing credentials.

```bash
pg_dump --format=custom --no-owner --no-acl --host=<source-host> --port=<source-port> --username=<source-user> --dbname=<database> --file=<private-archive>
pg_restore --exit-on-error --no-owner --no-acl --host=<target-host> --port=5434 --username=<target-owner> --dbname=<database> <private-archive>
```

These commands use a separately configured private password file. Do not embed passwords in URLs. The target database already exists and is empty. Do not use `--create`, which would restore source ownership, or `--clean` against an unverified target. Install required extensions explicitly as the admin before restore when the application owner cannot install them. A failed restore leaves a partial target: keep consumers on the source, clear only the verified disposable target database, and repeat the restore. Never repeat into a partially populated database and ignore errors.

Compare schema, table counts, exact row counts for critical tables, sequence values, catalogue checksums and representative queries. Run ANALYZE. Verify the owner can perform application writes in a rolled-back transaction. Confirm each read-only user can connect, use public, SELECT existing tables, and SELECT a new table made by the owner, but cannot INSERT, create tables or create another database. Drop the test table afterwards. Restore `--no-acl` deliberately avoids source grants: apply managed grants after restore, including current objects and owner default privileges.

## Keep existing connections and users

Create each needed secondary user on the canonical managed connection with `database:user:create`, using `--read-only` for readers. Supply passwords through the CLI's private prompt, not a literal `--password` in shell history. Match the prior rights deliberately; do not reuse an old admin login. A repeated user-create resets its password and grants.

Repoint each existing connection ID 9/10/11 by its resolved slug with `database:update --node=17 --host=<Services-WireGuard-IP> --port=5434 --database=<database> --username=<matching-user>`, and set the matching password privately. Preserve its ID, slug and attachments. These records remain external aliases; the new canonical managed connection owns the database. Alias deletion never drops data. A `database:user:create` call needs the canonical managed connection, since an external alias has no server. Preserve existing user records for Vault identity; rotate their stored credentials through the canonical user workflow and reconcile the alias publication before resuming consumers.

Reattach each consumer with its original prefix, then `env:sync` and restart or redeploy only that consumer. For a canonical managed connection, `instance:database:add ... --network=lan` explicitly selects LAN for an active same-Cluster consumer. External aliases keep their explicit host; use the LAN host only for an inventory-confirmed LAN consumer. WireGuard is the default for every other consumer. Run `vault:sync` and Doctor, check the consumer's health and application queries, then move the next consumer. A record update alone does not update previously attached environment values.

## Move analytics last

Only after the disposable PostgreSQL 18 rehearsal passes and Nick approves, create `plausible-db` on `services-postgres`, pause Process 83, dump and restore `plausible_db`, and apply its owner grants. Keep ClickHouse and all its data unchanged. Record the current analytics role's complete settings before replacing `postgres_process_id` with the new managed server's Process ID through `node:role:add <analytics-node> analytics --converge` and the existing required role options. The role resolves `plausible_db` application credentials from the managed connection; never put the server's superuser password back into a Process environment.

Run Plausible's own migration against the restored target before normal traffic resumes. Check login, site lists, dashboard totals, timeseries, top pages, sources, countries, filters and realtime against the source baseline. Compare logs, error rates and scheduled work. If any gate fails, return the role's `postgres_process_id` and settings to Process 80, converge, and keep PostgreSQL 16. Do not remove Process 80 or its volume during the rollback window.

## Rollback and finish

Before target writes, stop consumers, restore each saved alias endpoint and credentials, reattach and sync, restore analytics settings if changed, and resume the source. Verify health before restoring traffic. Keep the failed target for diagnosis with access restricted.

After target writes, switching endpoints alone loses those writes. Stop writers, take a target dump, and choose a reviewed reverse restore or application reconciliation before returning to the source. Never overwrite the retained source or merge divergent writes without Nick's approval.

After the agreed observation window, get separate approval to remove old Processes or volumes. Keep backups under the established retention policy. Resume imports one at a time, verify they write to the target, and update their connection inventory. This PR performs none of these production actions.
