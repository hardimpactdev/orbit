# Tool Catalog: `bun`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `bun` |
| Label | Bun |
| Backend | managed-user binary exposed at `/usr/local/bin/bun` |
| Support model | Installable, updatable, and removable by Orbit on app-dev/app-prod nodes |
| Category | `runtime` |

## Capabilities

`bun` supports `tool:install`, `tool:update`, `tool:remove`, and safe doctor
adopt. Orbit installs Bun with the official installer as the node's managed
user, then exposes that user's `~/.bun/bin/bun` through `/usr/local/bin/bun`.

`tool:update bun` runs the managed user's `bun upgrade` command. Removal deletes
the host link and the managed user's Bun directory.

## Platform support

Bun is supported on Linux and macOS. Linux installation installs `unzip` when
the host does not already provide it.

## Doctor relationship

`doctor --family=tool` probes `/usr/local/bin/bun` with `bun --version`. When the
binary is absent on an app-dev or app-prod node, the fixer runs
`tool:install bun` with the node managed user configuration.
