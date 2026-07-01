# Tool Catalog: `codex-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Codex CLI tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `codex-cli` |
| Label | Codex CLI |
| Backend | OpenAI standalone installer (`https://chatgpt.com/codex/install.sh`) |
| Support model | User-scoped runtime CLI on authorized active non-gateway Linux or macOS nodes |
| Required node role | None |
| Category | `runtime` |
| Supported operating systems | `linux`, `macos` |

## Capabilities

`codex-cli` supports `tool:install`, `tool:update`, live probing through
`tool:show --live`, and safe adoption through `doctor --family=tool`.

`tool:install codex-cli` runs OpenAI's Codex CLI installer for the target
node's stored default user (`nodes.user`), falling back to `orbit` when the
node user is missing. Repeat `--user=<name>` to install additional user-scoped
copies on the same node. The managed binary path is:

```text
~/.local/bin/codex
```

`tool:update codex-cli` reruns the same standalone installer, matching
OpenAI's documented upgrade path. `tool:install codex-cli --tool-version=...`
is rejected in this slice because Orbit has not encoded a source-backed
version-selection syntax for the standalone installer.

## Credentials

`codex-cli` does not support `tool:credentials`. Orbit does not manage, read,
validate, print, export, or repair OpenAI API keys, ChatGPT login state, Codex
session files, account state, or provider configuration. First-run sign-in and
provider configuration belong to the user's Codex CLI install.

## Orbit Notes

These notes describe Orbit-specific boundaries for Codex CLI management.

- `codex-cli` is separate from [`codex-app`](codex-app.md). `codex-app` is the
  macOS app project-registration bridge and does not install or update the
  terminal CLI.
- Orbit probes `codex-cli` by running `codex --version` as the persisted default
  managed user.
- The official source-backed installer is documented by OpenAI at
  `https://developers.openai.com/codex/cli` and mirrored in the
  `openai/codex` repository.
