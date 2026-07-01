# Tool Catalog: `cursor-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Cursor Agent CLI tool's identity, backend, and
support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `cursor-cli` |
| Label | Cursor CLI |
| Backend | Cursor Agent installer (`https://cursor.com/install`) |
| Support model | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes |
| Required node role | None |
| Category | `runtime` |
| Supported operating systems | `linux`, `macos` |

## Capabilities

`cursor-cli` supports `tool:install`, `tool:update`, live probing through
`tool:show --live`, and safe adoption through `doctor --family=tool`.

`tool:install cursor-cli` runs Cursor's installer for the target node's stored
default user (`nodes.user`), falling back to `orbit` when the node user is
missing. Repeat `--user=<name>` to install additional user-scoped copies on the
same node.

Cursor's public CLI starts through `agent`, and the installer also publishes a
`cursor-agent` symlink. Orbit records and probes `cursor-agent` to avoid
colliding with other agent CLIs that publish an `agent` command:

```text
~/.local/bin/cursor-agent
```

`tool:update cursor-cli` reruns the same Cursor installer. `tool:install
cursor-cli --tool-version=...` is rejected in this slice because Orbit has not
encoded a source-backed version-selection syntax for the standalone installer.

## Credentials

`cursor-cli` does not support `tool:credentials`. Orbit does not manage, read,
validate, print, export, or repair Cursor login state, keychain entries,
account state, or provider configuration. First-run sign-in and provider
configuration belong to the user's Cursor Agent CLI install.

## Orbit Notes

These notes describe Orbit-specific boundaries for Cursor CLI management.

- Orbit does not use `cursor-agent --version` for live version probing because
  that command can require local login/keychain access. Instead, Orbit verifies
  the `cursor-agent` symlink and derives the installed package label from the
  installer-managed version directory.
- Cursor documents the installer at `https://cursor.com/cli` and in the Cursor
  Agent CLI announcement.
