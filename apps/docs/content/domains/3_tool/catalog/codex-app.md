# Tool Catalog: `codex-app`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Codex App tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `codex-app` |
| Label | Codex App |
| Backend | macOS Codex App config file and URL callback |
| Support model | App-facing project-registration bridge for Codex App on macOS |
| Category | `operator` |
| Supported operating systems | `macos` |

## Capabilities

`codex-app` supports app-facing add, remove, and list operations through
`codex:app`. Those operations merge Orbit-owned project entries into
`~/.codex/codex-app/config.json`. The target node must be active, visible, not
the gateway, and have a platform that resolves to macOS. The tool does not
expose generic `tool:install`, `tool:remove`, credentials, service endpoints,
process lifecycle, or workspace registration.

## Configuration File

Orbit edits only this target-node file:

```text
~/.codex/codex-app/config.json
```

The file is source-agnostic so later workspace support can reuse the same
configuration service. The first scope stores app project entries as remote SSH
connections.

## Apply Callback

After writing the file, Orbit applies the configuration with:

```text
codex://codex-app/apply-config
```

Apply callback failures are reported as warnings when the config file was
written successfully.

## Scope Boundaries

`codex-app` must not:

- Register workspaces or Codex-managed worktrees.
- Depend on the target node being roleless or carrying an agent role.
- Edit any Codex CLI configuration outside `~/.codex/codex-app/config.json`.
- Install, update, remove, or probe the terminal Codex CLI. That lifecycle
  belongs to [`codex-cli`](codex-cli.md).
- Create node grants, SSH keys, host keys, or WireGuard identity material.
