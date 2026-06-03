# Tool Catalog: `postgres`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PostgreSQL tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `postgres` |
| Label | PostgreSQL |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `database` |

## Capabilities

`postgres` supports `tool:install`, `tool:remove`, process-backed
compatibility lifecycle actions, `tool:update`, `tool:logs`,
`tool:credentials`, WireGuard service endpoint management, safe doctor fix, and
safe doctor adopt.

## Credentials

`tool:credentials postgres` returns database connection fields for the managed
PostgreSQL service. Orbit generates and stores the password; catalog examples
use a placeholder rather than a literal default.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "postgres",
        "node": "app-1",
        "fields": {
          "host": "10.6.0.12",
          "port": 5432,
          "database": "orbit",
          "username": "orbit",
          "password": "<generated-password>"
        }
      }
    },
    "meta": {}
  }
}
```

## Service Endpoint

`postgres` exposes a TCP service endpoint reachable only over WireGuard at the
serving node's WireGuard service address, such as `10.6.0.12:5432`. This is
service endpoint configuration owned by the tool definition, not an HTTP proxy
route.

## Orbit Notes

PostgreSQL is a managed database capability. App database selection and
application migrations remain app or deployment concerns.

`tool:install postgres` requires the target node to have an active
`database` role assignment. Orbit does not select an app database host here.
Installing PostgreSQL on a node with an app role is allowed only when that same node also
has an active `database` role.

Orbit interacts with managed databases through the `database_connection`
state family and `database:*` commands. The `postgres` tool no longer
installs the `psql` client binary; queries run via PHP driver from the
gateway or owning node.

Runtime-model migration keeps the `postgres` tool row as the capability and
compatibility payload record. The backfilled node-owned PostgreSQL process row
uses `runtime=docker` and owns lifecycle and logs.

## Doctor Relationship

`doctor --family=tool` owns PostgreSQL capability and expected-state checks,
credential metadata, service endpoint metadata, and safe repair/adoption
boundaries. The related Docker process lifecycle belongs to the process family.
