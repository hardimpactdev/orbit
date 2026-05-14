# Tool Catalog: `dns`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the DNS tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `dns` |
| Label | DNS |
| Backend | Docker service |
| Support model | Required infrastructure tool, adopted and kept converged |
| Category | `infrastructure` |

## Capabilities

`dns` supports lifecycle actions (`tool:start`, `tool:stop`, `tool:restart`),
`tool:update`, `tool:logs`, safe doctor fix, and safe doctor adopt.

`tool:install dns` and `tool:remove dns` are not supported as ordinary
operator actions unless a later DNS bootstrap contract explicitly says so.

## Credentials

`dns` does not support `tool:credentials`.

## Orbit Notes

The `dns` tool is the runtime capability behind Orbit-managed DNS
infrastructure. DNS records, zones, provider configuration, and DNS command
behavior remain owned by the DNS command family.

In the current topology, the DNS runtime tool is gateway infrastructure. App and
control nodes do not own DNS runtime rows unless a future DNS contract expands
node-local DNS support.

## Doctor Relationship

`doctor --family=tool` verifies the DNS runtime tool. DNS record drift belongs
to the DNS family.
