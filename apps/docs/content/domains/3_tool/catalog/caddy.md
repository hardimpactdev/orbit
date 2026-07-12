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
| Supported operating systems | Linux and macOS |
| Required container provider | Docker-compatible |
| Isolation | Docker container |

## Capabilities

`caddy` supports `tool:reconfigure`, `tool:update`, safe doctor fix, safe
doctor adopt, `tool:reload`, and `tool:logs`. Reload and logs are declared
against exactly one direct tool-owned `orbit-caddy` runtime. They are not
inferred for other Docker-backed tools.

`tool:install caddy` and `tool:remove caddy` are not supported for host package
management. Orbit converges `orbit-caddy` as part of node role baseline.

## Credentials

`caddy` does not support `tool:credentials`.

## Orbit Notes

`orbit-caddy` is the current proxy backend, but proxy route ownership remains
in the `proxy` state family. The Caddy tool row owns capability and baseline
drift. The declared reload and logs verbs address the direct runtime without
moving route ownership into the tool family.

## Doctor Relationship

`doctor --family=tool` may adopt an existing `orbit-caddy` container and may
fix safe container drift. Proxy route drift remains owned by
[`doctor --family=proxy`](../../8_proxy/proxy-doctor.md).
