# Tool Catalog: `caddy`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Caddy tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `caddy` |
| Label | Caddy |
| Backend | `orbit-caddy` Docker container |
| Support model | Role baseline where HTTP routing is needed, adopted and kept converged |
| Category | `always` |

## Capabilities

`caddy` supports lifecycle actions (`tool:start`, `tool:stop`,
`tool:restart`), `tool:reload`, `tool:reconfigure`, `tool:update`,
`tool:logs`, safe doctor fix, and safe doctor adopt.

`tool:install caddy` and `tool:remove caddy` are not supported for host package
management. Orbit converges `orbit-caddy` as part of node role baseline.

## Credentials

`caddy` does not support `tool:credentials`.

## Orbit Notes

`orbit-caddy` is the current proxy backend, but proxy route ownership remains
in the `proxy` state family. Tool management owns the container lifecycle,
logs, reload, and baseline drift only.

## Doctor Relationship

`doctor --family=tool` may adopt an existing `orbit-caddy` container and may
fix safe container drift. Proxy route drift remains owned by
[`doctor --family=proxy`](../../8_proxy/proxy-doctor.md).
