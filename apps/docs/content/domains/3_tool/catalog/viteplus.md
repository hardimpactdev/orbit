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
and macOS. Orbit uses the managed node user. Fresh installs use
`~/.local/share/vite-plus/bin`; older installs use `~/.vite-plus/bin`.
Vite+ owns LTS Node.js and the `node`, `npm`, and `npx` shims. Bun is a separate
Orbit-managed baseline and is not auto-downloaded by Vite+.

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

Tool doctor verifies executable `vp`, `node`, `npm`, and `npx` links and runs
their version commands. Removal runs `vp implode --yes` before deleting stable
host links. A missing or unhealthy baseline is role drift.
