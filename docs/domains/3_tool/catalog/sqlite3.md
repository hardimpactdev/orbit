# Tool Catalog: `sqlite3`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the SQLite CLI utility's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `sqlite3` |
| Label | SQLite 3 CLI |
| Backend | system package |
| Support model | Baseline app-role utility, installable by Orbit |
| Category | `database-client` |

## Capabilities

`sqlite3` supports `tool:install`, `tool:update`, safe doctor fix, and safe
doctor adopt. It does not support lifecycle actions or credentials because it
is a local CLI utility, not a managed service.

## Credentials

`sqlite3` does not support `tool:credentials`.

## Orbit Notes

SQLite is local storage. Orbit includes the `sqlite3` CLI in the
`app-development` and `app-production` baselines so nodes have the lightweight
local database utility that Laravel development, tests, and small local data
workflows commonly expect.

`sqlite3` is not part of the `database` role baseline. Managed database service
tools own their service-specific clients: the `postgres` tool installs
`postgresql-client`, and the `mysql` tool installs `default-mysql-client`.

## Doctor Relationship

`doctor --family=tool` verifies that the `sqlite3` binary exists when an app
role baseline declares it, and safe repair installs the system package.
