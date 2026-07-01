# Tool Catalog: `antigravity-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Antigravity CLI tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `antigravity-cli` |
| Label | Antigravity CLI |
| Backend | Google Antigravity installer (`https://antigravity.google/cli/install.sh`) |
| Support model | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes |
| Required node role | None |
| Category | `runtime` |
| Supported operating systems | `linux`, `macos` |

## Capabilities

`antigravity-cli` supports `tool:install`, `tool:update`, live probing through
`tool:show --live`, and safe adoption through `doctor --family=tool`.

`tool:install antigravity-cli` runs Google's Antigravity CLI installer for the
target node's stored default user (`nodes.user`), falling back to `orbit` when
the node user is missing. Repeat `--user=<name>` to install additional
user-scoped copies on the same node. The managed binary path is:

```text
~/.local/bin/agy
```

`tool:update antigravity-cli` reruns the same official installer as the managed
user. Orbit uses that install-or-upgrade path instead of relying on
auth/session-sensitive provider configuration. `tool:install antigravity-cli
--tool-version=...` is rejected in this slice because Orbit has not encoded a
source-backed version-selection syntax for the standalone installer.

## Credentials

`antigravity-cli` does not support `tool:credentials`. Orbit does not manage,
read, validate, print, export, or repair Google login state, keyring entries,
OAuth sessions, account state, or provider configuration. First-run sign-in and
provider configuration belong to the user's Antigravity CLI install.

## Orbit Notes

These notes describe Orbit-specific boundaries for Antigravity CLI management.

- `antigravity-cli` supersedes outdated Gemini CLI catalog support for this
  agent-coding-CLI slice. Orbit does not add `gemini-cli`.
- Orbit probes `antigravity-cli` by running `agy --version` as the persisted
  default managed user.
- The source-backed installer is documented in the Google Antigravity CLI docs
  and the `google-antigravity/antigravity-cli` repository.
