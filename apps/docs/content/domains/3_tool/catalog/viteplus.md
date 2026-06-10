# Tool Catalog: `viteplus`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the VitePlus tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `viteplus` |
| Label | VitePlus |
| Backend | system binary |
| Support model | Role baseline tool for the `app-dev` and `app-prod` roles |
| Category | `runtime` |

## Capabilities

`viteplus` is probed and adopted as the role baseline tool materialized by the
`app-dev` and `app-prod` roles. It is not required on nodes
without an app role. It supports probe and adopt only; it has no install,
update, remove, credentials, or reconfigure surface. Runtime lifecycle and
logs belong to the process family.

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
