# Database Commands

Database commands manage reusable database connection intent, instance and
workspace target mappings, `.env` convergence, schema
inspection, and audited SQL execution. Spec:
[`apps/docs/content/domains/18_database/`](../../../apps/docs/content/domains/18_database/).

`database_connection` is a state family. Use
`doctor --family=database_connection --restore` to write gateway-owned
connection values into instance/workspace `.env` files, or `--adopt` to materialize
existing supported env prefixes into gateway state. Instance mappings render
through `instance:env render` in this slice.

Database commands do not install database services. Managed service lifecycle,
when present, belongs to process-owned runtime units; database commands own
connection intent and data-plane operations.

## Registry

```bash
orbit database:list [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>] [--json]
orbit database:show [<connection>] [--json]
```

## Connection Management

```bash
orbit database:add [<slug>] --driver=<mysql|pgsql|sqlite> [--node=<node>]
                   [--host=<host>] [--port=<port>] [--database=<name>]
                   [--path=<path>] [--username=<username>] [--password=<password>]
                   [--json]

orbit database:add-user [<connection>] --service=<process> --database=<name>
                         --username=<username> --password=<password>
                         [--node=<node>] [--json]

orbit database:update [<connection>] [--node=<node>] [--slug=<slug>]
                      [--driver=<mysql|pgsql|sqlite>] [--host=<host>]
                      [--port=<port>] [--database=<name>] [--path=<path>]
                      [--username=<username>] [--password=<password>]
                      [--clear-password] [--json]

orbit database:remove [<connection>] [--force] [--json]
```

For node-owned MySQL/PostgreSQL connections, app/workspace env restore writes
the owning node's WireGuard service address as host when the target reaches the
database over Orbit's private network. If a stored MySQL connection matches a
managed Docker MySQL process on the same node as the app or workspace target,
restore and doctor comparisons write the process Docker service alias and
internal target port instead; adoption recognizes that alias as the same
canonical connection and keeps the stored gateway endpoint for `database:query`.
SQLite queries execute on the node that owns the SQLite file path.

`database:add-user` requires an existing managed MySQL process. It creates or
updates the MySQL database/user through that process and then persists the
database connection record. It currently supports Docker runtime managed MySQL
processes; service lifecycle remains process-owned.

## Instance / Workspace Targets

```bash
orbit database:attach [<connection>] [--instance=<project.instance>|--workspace=<workspace>]
                       [--env-prefix=DB] [--json]

orbit database:detach [<connection>] [--instance=<project.instance>|--workspace=<workspace>]
                       [--env-prefix=DB] [--json]
```

`DB` expands to `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, and `DB_PASSWORD`. Custom prefixes such as `ANALYTICS_DB` are
uppercase underscore tokens.

Use `--instance=<project.instance>` to attach the connection to one concrete
instance. Instance env rendering then injects the database keys for that
instance.

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
