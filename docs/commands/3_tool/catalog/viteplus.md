# Tool Catalog: `viteplus`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `viteplus` |
| Label | VitePlus |
| Backend | system binary |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`viteplus` is probed and adopted as a baseline CLI utility. It does not support
tool lifecycle commands, reload, logs, credentials, or removal.

## Credentials

`viteplus` does not support `tool:credentials`.

## Orbit Notes

VitePlus supplies frontend development runtime support. App, workspace, and
process behavior remain owned by their respective command families.

Vite-backed development servers that need browser/HMR access across the Orbit
network must bind to a node-reachable interface, such as
`npm run dev -- --host=0.0.0.0`. Runtime HTTPS support for those servers is
supplied by the process family through Orbit URL and certificate environment
fields.

## Doctor Relationship

`doctor --family=tool` may adopt an existing `vp` binary and report missing
baseline drift.
