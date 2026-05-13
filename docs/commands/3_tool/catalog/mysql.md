# Tool Catalog: `mysql`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `mysql` |
| Label | MySQL |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `database` |

## Capabilities

`mysql` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, `tool:logs`, `tool:credentials`, WireGuard service endpoint
management, safe doctor fix, and safe doctor adopt.

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
          "host": "orbit.test",
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

`mysql` exposes a WireGuard-only TCP service endpoint at
`orbit.<node-tld>:3306` for development app nodes. This is DNS/service endpoint
configuration owned by the tool definition, not an HTTP proxy route.

## Orbit Notes

MySQL is a managed database capability. App database selection and application
migrations remain app or deployment concerns.

## Doctor Relationship

`doctor --family=tool` verifies the managed MySQL container, expected lifecycle
state, logs availability, and safe repair/adoption boundaries.
