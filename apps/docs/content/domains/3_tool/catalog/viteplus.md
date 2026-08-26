# Tool Catalog: `viteplus`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the VitePlus tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `viteplus` |
| Label | VitePlus |
| Backend | managed-user Vite+ runtime with stable host shims |
| Support model | Managed baseline on `app-dev` and `app-prod` |
| Category | `runtime` |

## Capabilities

`viteplus` supports install, update, remove, probe, and safe adoption on Linux
and macOS. Orbit installs the shared CLI and default LTS environment under
`/opt/orbit/vite-plus`. App and workspace commands set an isolated per-runtime
user `VP_HOME`. Vite+ owns Node.js and the `node`, `npm`, and `npx` shims. Bun
is a separate Orbit-managed baseline and is not auto-downloaded by Vite+.

## Credentials

`viteplus` does not support `tool:credentials`.

## Orbit Notes

VitePlus supplies frontend development runtime support. Instance, workspace, and
process behavior remain owned by their respective command families.

Vite-backed development servers that need browser/HMR access across the Orbit
network must bind to a node-reachable interface, such as
`vp run dev --host=0.0.0.0`. Runtime HTTPS support for those servers is
supplied by the process family through Orbit URL and certificate environment
fields.

## Doctor Relationship

Tool doctor verifies executable `vp`, `node`, `npm`, and `npx` links and runs
their version commands. Install, update, and remove replace or delete only
stable host links that Orbit can identify as Vite+ links. Removal deletes those
links before `vp implode --yes`, so deleted targets do not hide stale links. A
missing or unhealthy baseline is role drift.
