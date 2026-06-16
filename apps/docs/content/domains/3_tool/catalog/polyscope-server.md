# Tool Catalog: `polyscope-server`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PolyScope Server tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `polyscope-server` |
| Label | PolyScope Server |
| Backend | Node-owned `systemd` process named `polyscope-server` with `tool=polyscope` |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`polyscope-server` supports `tool:install`, `tool:remove`,
`tool:reconfigure`, `tool:update`, safe doctor fix, and safe doctor adopt.
Start, stop, restart, and logs for the long-running server belong to the
related `polyscope-server` process.

`polyscope-server` declares a related singleton process, so `tool:install
polyscope-server` configures that process by default: a node-owned `systemd`
process named `polyscope-server`, command `polyscope-server`, with a
`tool=polyscope` dependency. The convergence is idempotent. Pass
`--no-process` to install the capability only.

## Credentials

`polyscope-server` does not currently support `tool:credentials` in the catalog
contract.

## Orbit Notes

PolyScope Server is an agent IDE server capability. Agent IDE workspace and
provider behavior remain owned by the agent IDE domain when that domain is
ported.

Runtime-model migration treats `polyscope` as the installed capability and
`polyscope-server` as the process name. The backfilled node-owned process row
owns lifecycle with `runtime=systemd`; the `polyscope-server` tool row remains
the capability and compatibility payload record.

Provider authentication remains provider-owned. When provider login cannot be
completed remotely, `tool:install polyscope-server` may report a manual
`polyscope-server login` recovery step, but that login state is not exposed as
`tool:credentials`.

`tool:update polyscope-server` currently runs PolyScope Server's standalone
updater. Update remains tool-owned while start, stop, restart, and logs belong
to the related process.

## Doctor Relationship

`doctor --family=tool` owns capability and expected-state checks and safe
repair/adoption boundaries. The related `systemd` process lifecycle belongs to
the process family.
