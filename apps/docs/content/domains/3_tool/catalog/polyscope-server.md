# Tool Catalog: `polyscope-server`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PolyScope Server tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `polyscope-server` |
| Label | PolyScope Server |
| Backend | Supervisor program |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`polyscope-server` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:reconfigure`, `tool:update`, snapshot and streamed `tool:logs`, safe
doctor fix, and safe doctor adopt.

## Credentials

`polyscope-server` does not currently support `tool:credentials` in the catalog
contract.

## Orbit Notes

PolyScope Server is an agent IDE server capability. Agent IDE workspace and
provider behavior remain owned by the agent IDE domain when that domain is
ported.

Provider authentication remains provider-owned. When provider login cannot be
completed remotely, `tool:install polyscope-server` may report a manual
`polyscope-server login` recovery step, but that login state is not exposed as
`tool:credentials`.

`tool:update polyscope-server` runs PolyScope Server's standalone updater and
then restarts the Supervisor program that Orbit manages.

## Doctor Relationship

`doctor --family=tool` verifies the managed Supervisor program, expected
lifecycle state, logs availability, and safe repair/adoption boundaries.
