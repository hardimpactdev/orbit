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

`postgres` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, `tool:logs`, `tool:credentials`, WireGuard service endpoint
management, safe doctor fix, and safe doctor adopt.

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
          "host": "orbit.test",
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

`postgres` exposes a TCP service endpoint reachable only over WireGuard at
`orbit.<node-tld>:5432` for development nodes. This is DNS/service endpoint
configuration owned by the tool definition, not an HTTP proxy route.

## Orbit Notes

PostgreSQL is a managed database capability. App database selection and
application migrations remain app or deployment concerns.

Installing `postgres` also installs the `postgresql-client` package on the same
node so operators and local automation have the matching `psql` CLI available
only where the managed PostgreSQL service is installed.

`tool:install postgres` requires the target node to have an active
`database` role assignment. Orbit does not select an app database host here.
Installing PostgreSQL on a node with an app role is allowed only when that same node also
has an active `database` role.

## Doctor Relationship

`doctor --family=tool` verifies the PostgreSQL client capability, managed
service intent, and safe repair/adoption boundaries.
