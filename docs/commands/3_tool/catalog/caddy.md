# Tool Catalog: `caddy`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `caddy` |
| Label | Caddy |
| Backend | system service |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`caddy` supports lifecycle actions (`tool:start`, `tool:stop`,
`tool:restart`), `tool:reload`, `tool:reconfigure`, `tool:update`,
`tool:logs`, safe doctor fix, and safe doctor adopt.

`tool:install caddy` and `tool:remove caddy` are not supported because Caddy is
a required node baseline tool.

## Credentials

`caddy` does not support `tool:credentials`.

## Orbit Notes

Caddy is the current proxy backend, but proxy route ownership remains in the
`proxy` state family. Tool management owns the Caddy service lifecycle,
logs, reload, and baseline drift only.

## Doctor Relationship

`doctor --family=tool` may adopt an existing Caddy installation and may fix safe
service drift. Proxy route drift remains owned by
[`doctor --family=proxy`](../../8_proxy/proxy-doctor.md).
