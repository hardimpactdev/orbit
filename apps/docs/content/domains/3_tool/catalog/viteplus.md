# Tool Catalog: `viteplus`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the VitePlus tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `viteplus` |
| Label | VitePlus |
| Backend | system binary |
| Support model | Optional observational runtime inventory; no role baseline requirement |
| Category | `runtime` |

## Capabilities

`viteplus` is not materialized by the `app-dev` or `app-prod` role baseline.
An existing `vp` binary may be probed and explicitly adopted as observational
tool inventory. An absent tool row or binary does not create role-baseline
drift. VitePlus has no install, update, remove, credentials, or reconfigure
surface. Runtime lifecycle and logs belong to the process family.

## Credentials

`viteplus` does not support `tool:credentials`.

## Orbit Notes

VitePlus supplies frontend development runtime support. Instance, workspace, and
process behavior remain owned by their respective command families.

Vite-backed development servers that need browser/HMR access across the Orbit
network must bind to a node-reachable interface, such as
`npm run dev -- --host=0.0.0.0`. Runtime HTTPS support for those servers is
supplied by the process family through Orbit URL and certificate environment
fields.

## Doctor Relationship

`doctor --family=tool` may adopt an explicitly selected existing `vp` binary.
Once a VitePlus tool row exists, tool doctor verifies the capability expected
by that row. Without a row, an absent binary is unmanaged inventory rather than
role-baseline drift.
