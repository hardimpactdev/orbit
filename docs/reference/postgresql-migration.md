---
title: "Move PostgreSQL databases to a managed server"
description: "Rehearse, restore, verify and cut over one PostgreSQL database at a time, with rollback."
---

# Move PostgreSQL databases to a managed server

Create a new [managed server](/reference/database-servers) and move each database with `pg_dump` and `pg_restore`. This is an operator procedure, not a Gateway migration. Nick approves each production cutover separately. Keep every source volume and Process until verification and the rollback window end.

## Scope and stop conditions

The planned target is one PostgreSQL 18 server on Services (Node 17), on a verified free port 5434. Port 5432 belongs to analytics-postgres (Process 80, PostgreSQL 16); 5433 belongs to food-postgres (Process 213, PostgreSQL 18). Confirm current records and listeners before selecting a port. Do not stop, edit, upgrade or remove the Recall Instance's index-postgres (Process 118, pgvector, loopback only).

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
4. Start Plausible on PostgreSQL 18 with the production startup chain as the non-superuser owner.
5. Sign in and check dashboard summary and timeseries. Check top pages and sources. Test countries and filters. Test realtime and date comparisons. Check logs for SQL errors.
6. Verify the account, site and events remain visible. Record exit codes and HTTP results without credentials. Record untested paths.

Use this startup chain for step 4:

```bash
/entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run
```

A synthetic rehearsal does not prove every production query or extension.

If restore, migration or a dashboard path fails, stop. Keep analytics on PostgreSQL 16 and report the error. Do not bypass the version gate to test on Services.

### Recorded rehearsal: 2026-10-21

On Beast, isolated Docker containers on an internal network used only synthetic data. Each PostgreSQL container had one CPU and 512 MiB; Plausible had two CPUs and 1 GiB; ClickHouse had two CPUs and 2 GiB. No production Node or database was contacted.

| Image | Digest |
| --- | --- |
| PostgreSQL 16 alpine | `sha256:97ff59a4e30e08d1c11bdcd9455e7832368c0572b576c9092cde2df4ae5552a3` |
| PostgreSQL 18 | `sha256:ddbc320a9ad558c01c719248108e607eadde92fea799fec799028cfb2fab7319` |
| Plausible CE v3.2.1 | `sha256:33e60bfb40f2df5da00f8753b76fad04f67dba3abe6d73eb516e440e3fb62985` |
| ClickHouse 24.12 alpine | `sha256:cd450891db46cc6ffe313ca2b0fb7dbfb897a6873ca74a724cbe050a2cf62621` |

Plausible's `db createdb` and `db migrate` passed on PostgreSQL 16. The fixture had one account, one site, 222 schema migration records and three pageviews on distinct paths. A custom-format dump restored with `--no-owner --no-acl` into a PostgreSQL 18 database owned by a LOGIN role without superuser or CREATEDB rights. Plausible's own `db createdb` accepted the existing database without CREATEDB, `db migrate` exited 0, and the full `db createdb && db migrate && run` chain then started successfully as the non-superuser owner. All eight authenticated site/dashboard requests passed again after that full startup. The restored account, site and all 222 migration records remained present.

Authenticated `/sites` and the site dashboard returned 200 after the move. The dashboard's `main-graph`, `top-stats`, `pages`, `sources`, `countries` and `current-visitors` APIs each returned 200 for the current day, realtime, a previous-period comparison and a page filter: 24 successful query requests. Page and source results retained the fixture. No PostgreSQL errors appeared in the migration output. T3 browser automation was unavailable because of the host's AppArmor browser-sandbox restriction, so these were direct HTTP checks, not a visual browser check. Geo data, production extensions, production query load, and every production filter combination remain untested. This result permits planning the PostgreSQL 18 cutover; it does not authorize it.

## Inventory before each move

Read `database:list --json`, `database:show <slug> --json`, `database:user:list <slug> --json`, `process:show <id> --json`, and analytics role settings. Store an encrypted rollback inventory with the connection IDs, slugs, driver, Node, host, port, database, usernames, each user's grants, attachment prefixes, consumer Process IDs and current analytics settings. Get secret values from Vault through its private workflow; never print them or place them in shell history or command arguments.

Read-only inventory on 2026-10-21 identified connection 9 as `mealou-food-admin` (postgres), 10 as `mealou-food-reader` (mealou_food_catalog_reader), and 11 as `mealou-food-writer` (mealou_food_catalog_writer), all on mealou_food_catalog at 10.44.0.3:5433. The canonical slug mealou-food-catalog was free. Resolve each ID to its exact current slug and database; do not assume all three point to one database or have the same rights. Inventory consumers of `device_catalog` separately. Check source extensions, collations, encodings, schemas, owners, table and sequence counts, large objects and database size. The managed grant policy covers `public`; custom schemas need reviewed explicit grants. Do not transfer source superuser attributes or wholesale `pg_dumpall` roles.

Check free disk for a dump, restored database and rollback copy. Test backups in the disposable target. Disable scheduled imports, refreshes and jobs for the database being moved; record how to resume each. Identify all writers before the final dump.

## Create the target after approval

Use port 5434 after confirming it is free, and the slug for the database whose move Nick approved.

Mealou requires the builtin `C.UTF-8` locale. Create its canonical database with `database:create mealou-food-catalog --server=services-postgres --locale=C.UTF-8` before restore. Orbit renders `TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE 'C.UTF-8'` and records `locale` on the connection. Verify `pg_database.datlocprovider = 'b'` and `datlocale = 'C.UTF-8'` before copying data and after restore. Do not restore into a database with the image's libc default. For each other database, inventory its source provider and locale and choose the matching supported builtin locale or stop for a separate migration decision.

The supported choices are builtin `C.UTF-8` and `C`; omission inherits the image default. Inventory ICU or libc source locales and stop for a separate migration decision if they cannot use one of these choices. Locale is fixed when the target database is created.

```sql
SELECT datname, datlocprovider, datlocale, pg_encoding_to_char(encoding)
FROM pg_database WHERE datname = 'mealou_food_catalog';
```

Expect `b`, `C.UTF-8`, and `UTF8` for Mealou.

```bash
orbit database:server:create services-postgres --node=17 --driver=pgsql --port=5434
orbit database:create mealou-food-catalog --server=services-postgres --locale=C.UTF-8
orbit database:create device-catalog --server=services-postgres
```

Run only the database-create command for the database whose move Nick approved. The hyphenated slugs derive `mealou_food_catalog` and `device_catalog`. The canonical managed connection owns the database and its owner credentials are encrypted in the Gateway and published by `vault:sync`. The source Processes and existing connection IDs stay intact. Confirm both explicit private publications and Doctor before copying data.

Before and after server creation, verify the Gateway Redis connection, Horizon workers, queue depths and collector freshness. The new PostgreSQL server does not change existing Services Valkey servers; retrying those servers is a separate operator change. Do not change a Node's LAN address as part of this procedure.

## Dump and restore one database

Pause its writers and confirm the source stops changing. Take the final custom-format archive with PostgreSQL 18 client tools. Use a private `PGPASSFILE` (0600) or protected standard input; disable shell tracing. Keep dumps in a private directory (0700), encrypted at rest, with a checksum recorded outside command output containing credentials.

```bash
pg_dump --format=custom --no-owner --no-acl --host=<source-host> --port=<source-port> --username=<source-user> --dbname=<database> --file=<private-archive>
pg_restore --exit-on-error --no-owner --no-acl --host=<target-host> --port=5434 --username=<target-owner> --dbname=<database> <private-archive>
```

These commands use a separately configured private password file. Do not embed passwords in URLs. The target database already exists and is empty. Do not use `--create`, which would restore source ownership, or `--clean` against an unverified target. Create untrusted extensions as admin before restore. On the target Node, resolve the managed Process's exact container name from `process:show`. Use its local PostgreSQL socket; the admin password is never returned:

```bash
sudo docker exec --user postgres <verified-target-container> psql --no-psqlrc --set=ON_ERROR_STOP=1 --dbname=<target-database> --command='CREATE EXTENSION IF NOT EXISTS <reviewed-extension>;'
```

Review the extension name, package availability and target database first. Run this only on the approved target, one reviewed extension at a time.

Generate a table of contents with `pg_restore --list <private-archive>`, remove `COMMENT ... EXTENSION` entries from a private restore list, and pass `--use-list=<private-list>` to restore. Such comments require extension ownership and otherwise fail the owner restore under `--exit-on-error`. Review extension requirements before running any extension SQL.

 A failed restore leaves a partial target: keep consumers on the source, clear only the verified disposable target database, and repeat the restore. Never repeat into a partially populated database and ignore errors.

Compare schema, table counts, exact row counts for critical tables, sequence values, catalogue checksums and representative queries. Run ANALYZE. Verify the owner can perform application writes in a rolled-back transaction. Confirm each read-only user can connect, use public, SELECT existing tables, and SELECT a new table made by the owner, but cannot INSERT, create tables or create another database. Drop the test table afterwards. Restore `--no-acl` deliberately avoids source grants: apply managed grants after restore, including current objects and owner default privileges.

## Keep existing connections and users

### Recreate application logins

Create each needed secondary user on the canonical managed connection with `database:user:create`, using `--read-only` for readers. The CLI currently takes `--password`; use the authenticated API or SDK with a protected request body for secret-bearing user creation and connection updates, so credentials never enter argv or shell history. For connection 9, replace the source postgres login with the canonical managed owner and its privately retrieved credentials; never recreate postgres as an application user.

Recreate each existing application user with its privately retrieved password and matching prior rights; do not import source admin roles. A repeated user-create resets its password and grants.

### Repoint the aliases

Repoint each existing connection ID 9/10/11 by its resolved slug with `database:update --node=17 --host=<Services-WireGuard-IP> --port=5434 --database=<database> --username=<matching-user>`, and retain its existing password when the recreated application user uses it. Use a protected API body if credentials must change. Preserve its ID, slug and attachments.

These records remain external aliases; the new canonical managed connection owns the database. Alias deletion never drops data. A `database:user:create` call needs the canonical managed connection, since an external alias has no server. Preserve the existing user records and their passwords for Vault identity; recreate those users on the target through the canonical managed connection with the same passwords. Do not rotate them during this move, since user password updates belong to the canonical managed record.

### Reattach and verify consumers

Reattach each consumer with its original prefix, then `env:sync` and restart or redeploy only that consumer. For a canonical managed connection, `instance:database:add ... --network=lan` selects LAN when both the server and consumer Nodes are active with IPv4 LAN addresses. The operator confirms they share a reachable LAN. External aliases keep their recorded host; use a LAN host only after confirming every attached consumer can reach it. Orbit does not scan aliases against managed servers. Keep a shared alias on WireGuard if any consumer needs it. WireGuard remains the default.

 Run `vault:sync` and Doctor, check the consumer's health and application queries, then move the next consumer. A record update alone does not update attached environment values.

## Move analytics last

Only after the disposable PostgreSQL 18 rehearsal passes and Nick approves, create `plausible-db` on `services-postgres`, pause Process 83, dump and restore `plausible_db`, and apply its owner grants. Keep ClickHouse and all its data unchanged. Record the current analytics role's complete settings before replacing `postgres_process_id` with the new managed server's Process ID through `node:role:add <analytics-node> analytics --converge` and the existing required role options. The role resolves `plausible_db` application credentials from the managed connection; never put the server's superuser password back into a Process environment.

Run Plausible's own migration against the restored target before normal traffic resumes. Check login, site lists, dashboard totals, timeseries, top pages, sources, countries, filters and realtime against the source baseline. Compare logs, error rates and scheduled work. If any gate fails, return the role's `postgres_process_id` and settings to Process 80, converge, and keep PostgreSQL 16. Do not remove Process 80 or its volume during the rollback window.

## Rollback and finish

Before target writes, stop consumers, restore each saved alias endpoint and credentials, reattach and sync, restore analytics settings if changed, and resume the source. Verify health before restoring traffic. Keep the failed target for diagnosis with access restricted.

After target writes, switching endpoints alone loses those writes. Stop writers, take a target dump, and choose a reviewed reverse restore or application reconciliation before returning to the source. Never overwrite the retained source or merge divergent writes without Nick's approval.

After the agreed observation window, get separate approval to remove old Processes or volumes. Keep backups under the established retention policy. Resume imports one at a time, verify they write to the target, and update their connection inventory. This PR performs none of these production actions.

## Recorded infrastructure checks

On 2026-10-21, disposable PostgreSQL 18 retained a fixture database after its container was recreated without POSTGRES_PASSWORD, using the documented parent volume mount. Real SQL checks confirmed that downgrading a writer removed INSERT on current and future owner tables while keeping SELECT.

Database publication uses explicit WireGuard and LAN bindings with no port firewall. Check the final Process bindings before each cutover: `10.44.0.3:5434:5432` and `10.0.0.6:5434:5432` only. Reject `0.0.0.0`, an unqualified port mapping, or Services' public address 85.9.217.155. Keep the private networks unreachable from the public internet. PostgreSQL uses SCRAM passwords and the image's stock `pg_hba.conf` defaults.
