# Codex App Commands

Manage Codex App project entries on eligible macOS nodes. Spec:
[`apps/docs/content/domains/22_codex/`](../../../../apps/docs/content/domains/22_codex/).

## `orbit codex:app add|remove|list [project.instance]`

```bash
orbit codex:app add <project.instance> --node=<node> [--json]
orbit codex:app remove <project.instance> --node=<node> [--json]
orbit codex:app list --node=<node> [--json]
```

`add` and `remove` require a concrete dotted Orbit instance selector. Orbit
reads the source path and builds the SSH alias from that instance's serving
node. The Codex App target must be an active, visible, non-gateway macOS node.

`add` and `remove` edit only `~/.codex/codex-app/config.json` on the target and
then apply `codex://codex-app/apply-config`. `list` is read-only. None of the
actions register workspaces, change app runtime placement, or create roles,
grants, SSH keys, or WireGuard identity material.
