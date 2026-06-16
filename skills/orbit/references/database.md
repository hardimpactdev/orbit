# Database Commands

Database commands manage reusable database connection intent, app/workspace
target mappings, `.env` convergence, schema inspection, and audited SQL
execution. Spec:
[`apps/docs/content/domains/18_database/`](../../../apps/docs/content/domains/18_database/).

`database_connection` is a state family. Use
`doctor --family=database_connection --fix --restore` to write gateway-owned
connection values into app/workspace `.env` files, or `--adopt` to materialize
existing supported env prefixes into gateway state.

Database commands do not install database services. Managed service lifecycle,
when present, belongs to process-owned runtime units; database commands own
connection intent and data-plane operations.

## Registry

```bash
orbit database:list [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--json]
orbit database:show [<connection>] [--json]
```

## Connection Management

```bash
orbit database:add [<slug>] --driver=<mysql|pgsql|sqlite> [--node=<node>]
                   [--host=<host>] [--port=<port>] [--database=<name>]
                   [--path=<path>] [--username=<username>] [--password=<password>]
                   [--json]

orbit database:update [<connection>] [--node=<node>] [--slug=<slug>]
                      [--driver=<mysql|pgsql|sqlite>] [--host=<host>]
                      [--port=<port>] [--database=<name>] [--path=<path>]
                      [--username=<username>] [--password=<password>]
                      [--clear-password] [--json]

orbit database:remove [<connection>] [--force] [--json]
```

For node-owned MySQL/PostgreSQL connections, app/workspace env restore writes
the owning node's WireGuard service address as host. SQLite queries execute on
the node that owns the SQLite file path.

## App / Workspace Targets

```bash
orbit database:attach [<connection>] [--app=<app>|--workspace=<workspace>]
                       [--env-prefix=DB] [--json]

orbit database:detach [<connection>] [--app=<app>|--workspace=<workspace>]
                       [--env-prefix=DB] [--json]
```

`DB` expands to `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, and `DB_PASSWORD`. Custom prefixes such as `ANALYTICS_DB` are
uppercase underscore tokens.

## Query And Schema

```bash
orbit database:query [<target>] --sql=<sql> [--connection=<slug>]
                     [--limit=50] [--full] [--write] [--json]
orbit database:tables [<target>] [--connection=<slug>] [--json]
orbit database:schema [<target>] [--connection=<slug>] [--json]
orbit database:describe [<target>] [<table>] [--connection=<slug>] [--json]
```

`database:query` is read-only unless `--write` is supplied and the caller has
the write-query permission. Prefer `--json` for machine use.
