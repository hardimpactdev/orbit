# Tool Catalog: `redis`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `redis` |
| Label | Redis |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `cache` |

## Capabilities

`redis` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, `tool:logs`, `tool:credentials`, WireGuard service endpoint
management, safe doctor fix, and safe doctor adopt.

## Credentials

`tool:credentials redis` returns cache connection fields for the managed Redis
service. Orbit configures a managed `orbit` Redis user when the backend
supports ACL users and stores a generated password.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "redis",
        "node": "app-1",
        "fields": {
          "host": "orbit.test",
          "port": 6379,
          "database": 0,
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

`redis` exposes a WireGuard-only TCP service endpoint at
`orbit.<node-tld>:6379` for development app nodes. This is DNS/service endpoint
configuration owned by the tool definition, not an HTTP proxy route.

## Orbit Notes

Redis is a managed cache and queue-adjacent capability. App cache, queue, and
session configuration remain app concerns.

## Doctor Relationship

`doctor --family=tool` verifies the managed Redis container, expected lifecycle
state, logs availability, and safe repair/adoption boundaries.
