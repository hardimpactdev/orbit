# Database Commands

Database commands manage reusable database connection intent for app instances
and workspaces, converge that intent into target `.env` files, inspect schemas, and
run audited SQL through Orbit's supported drivers.

PostgreSQL runtime ownership belongs to the process domain. A database node may
host multiple `service=postgres` processes when each has its own process name,
database, username, published port, encrypted password, data path, and Docker
resource identity. The database domain consumes an explicit process identity
for connection behavior; it does not treat PostgreSQL as a node-wide singleton.

## Domain Rules

These rules govern what the database command family owns and what it may not
touch.

- The database command family owns the `database:*` command prefix.
- `database_connection` is a state family. Gateway database-connection records
  and target mappings are the expected state for app-instance and workspace
  database access.
- A database connection record stores reusable connection facts such as driver,
  host, port, database name, SQLite path, username, and encrypted credentials.
- A database connection target maps one connection record to exactly one app
  instance or one workspace plus the environment-variable prefix used in
  that target's `.env`.
- Workspace database targets remain subject to the workspace domain's strict
  `app-dev` boundary. An `app-prod` caller cannot list, inspect, attach,
  detach, query, describe, or diagnose a workspace target, even when a database
  grant would otherwise allow it. A workspace mapping served by `app-prod` is equally
  unsupported. Explicit targets fail with
  `workspace.unsupported_for_production` before registry, `.env`, probe, or
  executor effects; broad connection reads omit the forbidden workspace
  mappings while retaining supported app-instance targets.
- CLI callers resolve input locally, then the gateway reads or writes durable
  database connection state and performs any target `.env` inspection or update.
- `doctor --family=database_connection` owns drift between gateway
  database-connection intent and supported database-related keys in app-instance
  and workspace `.env` files.
- Database restore writes gateway-owned values into selected app-instance and workspace
  `.env` files while preserving unrelated keys and comments.
- For node-owned MySQL and PostgreSQL connections, database restore and doctor
  comparisons materialize `*_HOST` as the owning node's WireGuard service
  address when the consuming app instance or workspace reaches the database over Orbit's
  private network.
- When a stored connection record matches a managed Docker MySQL process on the
  same node as the target app instance or workspace, doctor restore and comparisons
  materialize the process Docker service alias and internal target port.
- Doctor adoption recognizes the managed Docker MySQL alias as the same
  canonical connection instead of replacing the stored gateway endpoint.
- When the matching managed Docker MySQL process is renamed through
  `process:update --name=<new-slug>`, same-node app-instance and workspace `.env`
  materialization uses the new Docker service alias, while gateway
  `database:query` continues to use the stored canonical endpoint.
- Gateway `database:query` continues to use the stored connection endpoint.
  External connections without an owning node keep their stored host value.
- When a mapped managed database host equals the consuming node's own WireGuard
  service address, `doctor --family=database_connection` diagnoses the Linux
  self-route with `ip route get <wireguard-ip>`. macOS reports
  `WireGuard self-route diagnostics are only supported on Linux.` and the
  database family does not mutate routes.
- Database adopt reads observed `.env` values by supported prefixes, creates or
  updates reusable connection records, creates or updates target mappings, and
  stores adopted passwords in encrypted credentials.
- Existing registered app instances and workspaces enter this family through explicit
  `doctor --adopt --family=database_connection` rollout. Database commands do
  not require re-registering those app instances or workspaces.
- Query and schema commands use gateway-owned connection state. They do not
  treat ad hoc env parsing as an alternate source of truth.
- SQLite query execution is local to the node that owns the SQLite file.
  MySQL and PostgreSQL query execution runs from the gateway when the target is
  reachable over Orbit's private network.
- Database commands do not own app registration, workspace registration,
  process definitions, schedule definitions, proxy routes, tool installation,
  or firewall policy.
- Managed database service lifecycle belongs to process-owned service
  definitions. The database family owns connection intent, data-plane
  operations, and read-only WireGuard self-route diagnostics for same-node
  connections, not service installation, lifecycle, or route mutation.
- `database:add-user` converges a database and user inside an existing managed
  MySQL service process. It executes gateway-locally for a gateway owner or
  through authenticated Agent push for a non-gateway owner, then creates or
  updates the reusable database connection record for that user. It does not
  install, start, stop, restart, or log the MySQL service.
- In the current implementation, `database:add-user` supports Docker runtime
  managed MySQL processes. Docker Swarm support needs a service exec primitive
  before it can converge users safely.

## Permissions

Database commands are authorized through the node access permission registry
under the `database:*` namespace.

- `database:read` covers registry and schema metadata reads:
  `database:list`, `database:show`, `database:tables`, `database:schema`, and
  `database:describe`.
- `database:write` covers registry, target mapping, and managed user
  mutations: `database:add`, `database:add-user`, `database:update`,
  `database:remove`, `database:attach`, and `database:detach`.
- `database:query` allows read-only SQL execution through
  `database:query`.
- `database:query:write` allows write-capable SQL execution when the caller
  explicitly supplies the command's write opt-in. It implies `database:query`.

`database:read` intentionally does not imply `database:query`: schema metadata
inspection is not the same authority as reading table rows. `database:write`
intentionally does not imply `database:query:write`: mutating Orbit's
connection registry is not the same authority as mutating application data.

For target-scoped commands, the serving node is the selected app instance or
workspace's owning node. For direct
connection commands, the serving node is the connection's owning node when it
has one, otherwise the node reached through an attached app instance or
workspace target. Connections without an owning node or target are gateway-owned
and require gateway authority.

Authorization failures use `authorization_failed` with standard
`missing_permission` metadata.

The production workspace boundary is an eligibility failure, not an ordinary
missing grant. It returns `workspace.unsupported_for_production` before the
database command evaluates or enacts workspace state.

## State Ownership

The database command domain owns the `database_connection` state family.

- [`doctor --family=database_connection`](database-doctor.md) owns connection
  drift, restore, and adopt behavior for app-instance and workspace `.env` mappings.
- [`doctor --family=app`](../5_app/app-doctor.md) owns app runtime health and
  app runtime artifacts that are not part of database env mapping.
- [`doctor --family=workspace`](../6_workspace/workspace-doctor.md) owns
  workspace runtime artifacts and workspace path drift.
- [`doctor --family=process`](../7_process/process-doctor.md) owns managed
  database service runtime drift when the service is represented as a process
  definition.

## Database JSON Entity

Database-family JSON renderers that return one connection entity embed this
shape under `success.data.connection`, or under
`success.data.connections[]` for list items. Target-specific renderers may add
target data beside the connection entity. App-instance targets use
`type=app_instance` and carry both `app` and `instance`.

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
      "type": "app_instance",
      "app": "acme",
      "instance": "development",
      "env_prefix": "DB"
    },
    {
      "type": "workspace",
      "name": "feature-acme",
      "app": "acme",
      "env_prefix": "REPORTING_DB"
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
| `targets` | array | App-instance or workspace mappings that expose this connection into `.env` files. |

## Concepts

These concepts define the vocabulary used by database command contracts and the
database doctor.

- [Database Concepts](database-concepts.md)

## Commands

The `database:*` family covers registry reads and writes, target mapping, and
audited SQL or schema inspection.

### Registry and inspection

Use these commands to browse stored connection state before you change it.

1. [`orbit database:list`](1_database-list/database-list.md)
2. [`orbit database:show <connection>`](2_database-show/database-show.md)

### Connection lifecycle

Use these commands to create, update, or remove reusable connection records.

3. [`orbit database:add <slug>`](3_database-add/database-add.md)
4. [`orbit database:add-user <connection>`](12_database-add-user/database-add-user.md)
5. [`orbit database:update <connection>`](4_database-update/database-update.md)
6. [`orbit database:remove <connection>`](5_database-remove/database-remove.md)

### Target mapping

Use these commands to bind stored connections into app-instance or
workspace env space.

7. [`orbit database:attach <connection>`](6_database-attach/database-attach.md)
8. [`orbit database:detach <connection>`](7_database-detach/database-detach.md)

### Query and schema

Use these commands to inspect or query a resolved stored connection.

9. [`orbit database:query <target>`](8_database-query/database-query.md)
10. [`orbit database:tables <target>`](9_database-tables/database-tables.md)
11. [`orbit database:schema <target>`](10_database-schema/database-schema.md)
12. [`orbit database:describe <target> <table>`](11_database-describe/database-describe.md)

## Internal Commands

These hidden commands support gateway orchestration and are not public workflow entrypoints.

- [`orbit internal:database-query-local` (internal, non-public)](internal/1_database-query-local/database-query-local.md)

## Related

- [`doctor --family=database_connection`](database-doctor.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`orbit tool:*`](../3_tool/README.md)
