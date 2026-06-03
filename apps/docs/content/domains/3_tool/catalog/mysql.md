# Tool Catalog: `mysql`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the MySQL tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `mysql` |
| Label | MySQL |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `database` |

## Capabilities

`mysql` supports `tool:install`, `tool:remove`, process-backed compatibility
lifecycle actions, `tool:update`, `tool:logs`, `tool:credentials`, WireGuard
service endpoint management, safe doctor fix, and safe doctor adopt.

## Credentials

`tool:credentials mysql` returns database connection fields for the managed
MySQL service. Orbit generates and stores both the operator-facing `orbit` user
password and the backend root/admin password required by MySQL. The default
operator credential is the `orbit` service user.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "mysql",
        "node": "app-1",
        "fields": {
          "host": "10.6.0.12",
          "port": 3306,
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

`mysql` exposes a TCP service endpoint reachable only over WireGuard at the
serving node's WireGuard service address, such as `10.6.0.12:3306`. This is
service endpoint configuration owned by the tool definition, not an HTTP proxy
route.

## Orbit Notes

MySQL is a managed database capability. App database selection and application
migrations remain app or deployment concerns.

`tool:install mysql` requires the target node to have an active `database`
role assignment. Orbit does not select an app database host here. Installing
MySQL on a node with an app role is allowed only when that same node also has an active
`database` role.

Orbit interacts with managed databases through the `database_connection`
state family and `database:*` commands. The `mysql` tool no longer installs
the `mysql` client binary; queries run via PHP driver from the gateway or
owning node.

Runtime-model migration keeps the `mysql` tool row as the capability and
compatibility payload record. The backfilled node-owned MySQL process row uses
`runtime=docker` and owns lifecycle and logs.

## Doctor Relationship

`doctor --family=tool` owns MySQL capability and expected-state checks,
credential metadata, service endpoint metadata, and safe repair/adoption
boundaries. The related Docker process lifecycle belongs to the process family.
