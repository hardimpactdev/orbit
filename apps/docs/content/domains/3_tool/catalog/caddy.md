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

`caddy` supports `tool:reconfigure`, `tool:update`, safe doctor fix, and safe
doctor adopt. Runtime lifecycle and logs for the proxy runtime belong to the
related process family, not the tool.

`tool:install caddy` and `tool:remove caddy` are not supported for host package
management. Orbit converges `orbit-caddy` as part of node role baseline.

## Credentials

`caddy` does not support `tool:credentials`.

## Orbit Notes

`orbit-caddy` is the current proxy backend, but proxy route ownership remains
in the `proxy` state family. The Caddy tool row owns capability and baseline
drift; the long-running container lifecycle and logs are process-backed, with
transitional `tool:*` compatibility commands where still present.

## Doctor Relationship

`doctor --family=tool` may adopt an existing `orbit-caddy` container and may
fix safe container drift. Proxy route drift remains owned by
[`doctor --family=proxy`](../../8_proxy/proxy-doctor.md).
