# Database Concepts

This document defines database-family vocabulary and invariants. It supports
the database command contracts and the [database doctor](database-doctor.md); it
does not override the [Architecture](../../architecture.md).

## Identity

These terms define the core vocabulary used across database command contracts
and the database doctor.

- **Database connection:** Gateway-owned reusable record of how Orbit should
  connect to one database. The record stores the supported driver, the address
  shape needed by that driver, the non-secret username when present, and
  encrypted credentials for secret fields such as passwords.
- **Database connection target:** Gateway-owned mapping from one database
  connection to exactly one instance or one workspace plus the environment prefix
  Orbit uses when reading or writing that target's `.env` file.
- **Environment prefix:** The stable prefix that expands into one database env
  key set for a target. `DB` expands to `DB_CONNECTION`, `DB_HOST`,
  `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`.
  `ANALYTICS_DB` expands to `ANALYTICS_DB_CONNECTION`, `ANALYTICS_DB_HOST`,
  and the same suffix set. Supported prefixes are uppercase tokens separated by
  underscores.

## Convergence

These terms describe how database connection intent converges with target
runtime configuration.

- **Database connection restore:** Doctor direction that writes gateway-owned
  database connection values into a selected instance or workspace `.env` file for
  one supported environment prefix. Restore preserves unrelated `.env` keys and
  comments, and it never deletes unrelated database-looking keys unless Orbit
  has an explicit target mapping for that prefix.
- **Database connection adopt:** Doctor direction that reads supported database
  env prefixes from a selected instance or workspace `.env` file, normalizes them
  into Orbit's database connection shape, creates or updates gateway connection
  records, creates or updates connection targets, and stores adopted passwords
  in encrypted credentials.
- **Existing-target rollout adoption:** The explicit first-rollout path for
  instances and workspaces that already exist in Orbit before the
  `database_connection` family exists. Adoption reads their current `.env`
  files and materializes the matching connection state without requiring app or
  workspace re-registration.
- **Managed MySQL user provisioning:** Database-family mutation that connects
  to an existing process-owned managed MySQL service, creates or updates a
  database user and database, grants that user access, and persists the
  resulting connection record. It is data-plane convergence, not service
  lifecycle.

## Execution

These terms describe how Orbit uses stored connection state for live database
operations.

- **Database query execution:** Orbit-owned execution of SQL against a selected
  stored database connection, with command contracts routing the query through
  a read or write path and with results rendered in strict JSON for machine use.
  The read path establishes a native read-only transaction for PostgreSQL/MySQL
  or a restored `PRAGMA query_only` scope for SQLite before user SQL runs. SQL
  classification guides UX and routing but is not the mutation-prevention
  boundary.
- **SQLite locality:** Invariant that SQLite queries execute on the node that
  owns the SQLite file path. Orbit does not tunnel SQLite file access through
  the gateway as a mounted path.

## Boundaries

These rules define what database commands may and may not change.

- **Database-family boundaries:** Database commands own reusable connection
  intent, instance/workspace target mappings, supported database env-prefix
  convergence, schema inspection, and audited SQL execution. They do not own
  app registration, workspace registration, database service installation,
  runtime health, proxy routes, process definitions, schedule definitions, or
  firewall policy.
- **Managed service boundary:** MySQL service installation, image selection,
  runtime backend, lifecycle, and logs belong to process-owned managed service
  rows. `database:add-user` may converge database users through a supported
  running MySQL process. Execution is gateway-local for a gateway owner and
  uses authenticated Agent push for a non-gateway owner. The command does not
  create or operate the process itself.
