# Database Commands

Database commands manage reusable database connection intent for apps and
workspaces, converge that intent into target `.env` files, inspect schemas, and
run audited SQL through Orbit's supported drivers.

## Domain Rules

These rules govern what the database command family owns and what it may not
touch.

- The database command family owns the `database:*` command prefix.
- `database_connection` is a state family. Gateway database-connection records
  and target mappings are the expected state for app and workspace database
  access.
- A database connection record stores reusable connection facts such as driver,
  host, port, database name, SQLite path, username, and encrypted credentials.
- A database connection target maps one connection record to exactly one app or
  one workspace plus the environment-variable prefix used in that target's
  `.env`.
- CLI callers resolve input locally, then the gateway reads or writes durable
  database connection state and performs any target `.env` inspection or update.
- `doctor --family=database_connection` owns drift between gateway
  database-connection intent and supported database-related keys in app and
  workspace `.env` files.
- Database restore writes gateway-owned values into selected app and workspace
  `.env` files while preserving unrelated keys and comments.
- Database adopt reads observed `.env` values by supported prefixes, creates or
  updates reusable connection records, creates or updates target mappings, and
  stores adopted passwords in encrypted credentials.
- Existing registered apps and workspaces enter this family through explicit
  `doctor --adopt --family=database_connection` rollout. Database commands do
  not require re-registering those apps or workspaces.
- Query and schema commands use gateway-owned connection state. They do not
  treat ad hoc env parsing as an alternate source of truth.
- SQLite query execution is local to the node that owns the SQLite file.
  MySQL and PostgreSQL query execution runs from the gateway when the target is
  reachable over Orbit's private network.
- Database commands do not own app registration, workspace registration,
  process definitions, schedule definitions, proxy routes, tool lifecycle, or
  firewall policy.
- Managed database service lifecycle remains under `tool:*`. The database
  family owns connection intent and data-plane operations, not service
  installation.

## State Ownership

The database command domain owns the `database_connection` state family.

- [`doctor --family=database_connection`](database-doctor.md) owns connection
  drift, restore, and adopt behavior for app and workspace `.env` mappings.
- [`doctor --family=app`](../5_app/app-doctor.md) owns app runtime health and
  app runtime artifacts that are not part of database env mapping.
- [`doctor --family=workspace`](../6_workspace/workspace-doctor.md) owns
  workspace runtime artifacts and workspace path drift.
- [`doctor --family=tool`](../3_tool/tool-doctor.md) owns managed database
  service tool lifecycle such as PostgreSQL or MySQL installation.

## Database JSON Entity

Database-family JSON renderers that return one connection entity embed this
shape under `success.data.connection`, or under
`success.data.connections[]` for list items. Target-specific renderers may add
target data beside the connection entity.

```json
{
  "slug": "acme",
  "driver": "pgsql",
  "host": "postgres.internal",
  "port": 5432,
  "database": "acme",
  "path": null,
  "username": "orbit",
  "node": "gateway",
  "targets": [
    {
      "type": "app",
      "name": "acme",
      "env_prefix": "DB"
    }
  ]
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `slug` | string | Globally unique database connection identity. |
| `driver` | string | Supported driver: `mysql`, `pgsql`, or `sqlite`. |
| `host` | string \| null | Network host for non-SQLite drivers. |
| `port` | integer \| null | Network port for non-SQLite drivers. |
| `database` | string \| null | Database name for networked drivers. |
| `path` | string \| null | SQLite file path when `driver=sqlite`. |
| `username` | string \| null | Non-secret username associated with the connection. |
| `node` | string \| null | Owning node when the connection is node-scoped; `null` when not node-bound. |
| `targets` | array | App/workspace mappings that expose this connection into `.env` files. |

## Concepts

These concepts define the vocabulary used by database command contracts and the
database doctor.

- [Database Concepts](database-concepts.md)

## Commands

Public database commands are defined in this domain as they are converted.

## Related

- [`doctor --family=database_connection`](database-doctor.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`orbit tool:*`](../3_tool/README.md)
