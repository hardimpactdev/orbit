# Tool Catalog: `redis`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Redis tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `redis` |
| Label | Redis |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `cache` |

## Capabilities

`redis` supports `tool:install`, `tool:remove`, process-backed compatibility
lifecycle actions, `tool:update`, `tool:logs`, `tool:credentials`, WireGuard
service endpoint management, safe doctor fix, and safe doctor adopt.

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
          "host": "10.6.0.12",
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

`redis` exposes a TCP service endpoint reachable only over WireGuard at the
serving node's WireGuard service address, such as `10.6.0.12:6379`. This is
service endpoint configuration owned by the tool definition, not an HTTP proxy
route.

## Orbit Notes

Redis is a managed cache and queue-adjacent capability. App cache, queue, and
session configuration remain app concerns.

Runtime-model migration keeps the `redis` tool row as the capability and
compatibility payload record. The backfilled node-owned `redis` process row
uses `runtime=docker` and owns lifecycle and logs.

## Doctor Relationship

`doctor --family=tool` owns Redis capability and expected-state checks,
credential metadata, service endpoint metadata, and safe repair/adoption
boundaries. The related Docker process lifecycle belongs to the process family.
