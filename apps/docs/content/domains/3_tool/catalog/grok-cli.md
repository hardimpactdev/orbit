# Tool Catalog: `grok-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Grok Build CLI tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `grok-cli` |
| Label | Grok CLI |
| Backend | xAI Grok Build installer (`https://x.ai/cli/install.sh`) |
| Support model | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes |
| Required node role | None |
| Category | `runtime` |
| Supported operating systems | `linux`, `macos` |

## Capabilities

`grok-cli` supports `tool:install`, `tool:update`, live probing through
`tool:show --live`, and safe adoption through `doctor --family=tool`.

`tool:install grok-cli` runs xAI's Grok Build installer for the target node's
stored default user (`nodes.user`), falling back to `orbit` when the node user
is missing. Repeat `--user=<name>` to install additional user-scoped copies on
the same node.

The installer publishes a `grok` command and may also publish an `agent`
compatibility alias. Orbit records and probes the unambiguous managed binary:

```text
~/.grok/bin/grok
```

`tool:update grok-cli` runs the native `grok update` command through the
managed `~/.grok/bin/grok` binary. `tool:install grok-cli --tool-version=...`
is rejected in this slice because Orbit has not encoded a source-backed
version-selection syntax for the standalone installer.

## Credentials

`grok-cli` does not support `tool:credentials`. Orbit does not manage, read,
validate, print, export, or repair xAI account state, Grok login sessions,
deployment keys, `~/.grok/auth.json`, or provider configuration. User login and
provider configuration remain owned by the Grok CLI.

## Orbit Notes

These notes describe Orbit-specific boundaries for Grok CLI management.

- Orbit intentionally does not use the generic `agent` alias for probing
  because Cursor CLI also publishes an `agent` command.
- The official xAI announcement documents the installer at
  `https://x.ai/news/grok-build-cli`; the installer script itself is served
  from `https://x.ai/cli/install.sh`.
