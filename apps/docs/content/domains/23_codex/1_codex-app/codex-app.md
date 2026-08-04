# `orbit codex:app add|remove|list [project.instance]`

`codex:app` registers one concrete Orbit instance in Codex App on an
eligible target node. It is for Codex App's project list, not for app runtime

## Usage

```bash
orbit codex:app add <project.instance> --node=<node> [--json]
orbit codex:app remove <project.instance> --node=<node> [--json]
orbit codex:app list --node=<node> [--json]
```

## Examples

```bash
orbit codex:app add docs.development --node=mini
orbit codex:app remove docs.development --node=mini
orbit codex:app list --node=mini --json
```

## Options

- `add`: Add or update the selected instance's Codex App project entry on
  the target node.
- `remove`: Remove the selected instance's Codex App project entry from the
  target node.
- `list`: List Codex App project entries known in the target node config.
- `--node=<node>`: Target node for Codex App. The node must be active, visible,
  non-gateway, and have a platform that resolves to macOS.
- `--json`: Emit the canonical JSON envelope and do not prompt.

`add` and `remove` require a dotted instance selector. Orbit reads the
source path and builds the SSH alias from that Orbit instance's serving node;
it never falls back to placement on the project. An external-driver
instance has no Orbit source-node SSH placement and fails as unsupported before
the Codex App config is read or written.

## What It Changes

`codex:app` edits only this file on the target node:

```text
~/.codex/codex-app/config.json
```

After add or remove, Orbit applies:

```text
codex://codex-app/apply-config
```

The command does not register a workspace, register a Codex-managed worktree,

See [`codex:app` technical contract](technical/1_codex-app.md).
