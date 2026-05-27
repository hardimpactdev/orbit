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
  connection to exactly one app or one workspace plus the environment prefix
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
  database connection values into a selected app or workspace `.env` file for
  one supported environment prefix. Restore preserves unrelated `.env` keys and
  comments, and it never deletes unrelated database-looking keys unless Orbit
  has an explicit target mapping for that prefix.
- **Database connection adopt:** Doctor direction that reads supported database
  env prefixes from a selected app or workspace `.env` file, normalizes them
  into Orbit's database connection shape, creates or updates gateway connection
  records, creates or updates connection targets, and stores adopted passwords
  in encrypted credentials.
- **Existing-target rollout adoption:** The explicit first-rollout path for
  apps and workspaces that already exist in Orbit before the
  `database_connection` family exists. Adoption reads their current `.env`
  files and materializes the matching connection state without requiring app or
  workspace re-registration.

## Execution

These terms describe how Orbit uses stored connection state for live database
operations.

- **Database query execution:** Orbit-owned execution of SQL against a selected
  stored database connection, with command contracts deciding whether the query
  is read-only or write-capable and with results rendered in strict JSON for
  machine use.
- **SQLite locality:** Invariant that SQLite queries execute on the node that
  owns the SQLite file path. Orbit does not tunnel SQLite file access through
  the gateway as a mounted path.

## Boundaries

These rules define what database commands may and may not change.

- **Database-family boundaries:** Database commands own reusable connection
  intent, app/workspace target mappings, supported database env-prefix
  convergence, schema inspection, and audited SQL execution. They do not own
  app registration, workspace registration, database service installation,
  runtime health, proxy routes, process definitions, schedule definitions, or
  firewall policy.
