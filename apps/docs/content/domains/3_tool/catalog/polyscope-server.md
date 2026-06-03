# Tool Catalog: `polyscope-server`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PolyScope Server tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `polyscope-server` |
| Label | PolyScope Server |
| Backend | Transitional Supervisor program; pending process-backed `systemd` migration |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`polyscope-server` supports `tool:install`, `tool:remove`, transitional
lifecycle actions, `tool:reconfigure`, `tool:update`, snapshot and streamed
`tool:logs`, safe doctor fix, and safe doctor adopt.

## Credentials

`polyscope-server` does not currently support `tool:credentials` in the catalog
contract.

## Orbit Notes

PolyScope Server is an agent IDE server capability. Agent IDE workspace and
provider behavior remain owned by the agent IDE domain when that domain is
ported.

Runtime-model migration treats `polyscope` as the installed capability and
`polyscope-server` as the process name. The process will own lifecycle with
`runtime=systemd`; the current catalog slug remains transitional until that
migration lands.

Provider authentication remains provider-owned. When provider login cannot be
completed remotely, `tool:install polyscope-server` may report a manual
`polyscope-server login` recovery step, but that login state is not exposed as
`tool:credentials`.

`tool:update polyscope-server` currently runs PolyScope Server's standalone
updater and then restarts the Supervisor program that Orbit manages. After
process migration, update remains tool-owned while restart/log lifecycle belongs
to the related process.

## Doctor Relationship

`doctor --family=tool` currently verifies the managed Supervisor program,
expected lifecycle state, logs availability, and safe repair/adoption
boundaries. After process migration, tool doctor owns capability and
expected-state checks while `doctor --family=process` owns the related
`systemd` process lifecycle.
