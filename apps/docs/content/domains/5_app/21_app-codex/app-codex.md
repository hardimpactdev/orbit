# `orbit app:codex add|remove|list [app]`

`app:codex` registers Orbit apps in Codex App on an eligible target node. It is
for Codex App's project list, not for app runtime configuration and not for the
app's Agent IDE adapter.

## Usage

```bash
orbit app:codex add <app> --node=<node> [--json]
orbit app:codex remove <app> --node=<node> [--json]
orbit app:codex list --node=<node> [--json]
```

## Examples

```bash
orbit app:codex add docs --node=mini
orbit app:codex remove docs --node=mini
orbit app:codex list --node=mini --json
```

## Options

- `add`: Add or update the app's Codex App project entry on the target node.
- `remove`: Remove the app's Codex App project entry from the target node.
- `list`: List Codex App project entries known in the target node config.
- `--node=<node>`: Target node for Codex App. The node must be active, visible,
  non-gateway, and have a platform that resolves to macOS.
- `--json`: Emit the canonical JSON envelope and do not prompt.

## What It Changes

`app:codex` edits only this file on the target node:

```text
~/.codex/codex-app/config.json
```

After add or remove, Orbit applies:

```text
codex://codex-app/apply-config
```

The command does not register a workspace, register a Codex-managed worktree,
change `app:agent-ide`, or mutate app runtime configuration.

See [`app:codex` technical contract](technical/1_app-codex.md).
