# `orbit workspace:list`

[Back to Workspace commands.](../README.md)

List workspaces registered for apps.

`workspace:list` provides a high-level summary of workspace configuration,
showing which workspaces belong to which apps, on which nodes, and their
registry lifecycle status. For live workspace drift and runtime artifact
verification, use [`doctor --family=workspace`](../workspace-doctor.md).
There is intentionally no `workspace:list --doctor` flag; workspace list
output stays a fast registry read.

## Usage

```bash
orbit workspace:list [--instance=<project.instance>] [--node=<slug>] [--json]
```

## Examples

```bash
orbit workspace:list
orbit workspace:list --instance=docs
orbit workspace:list --instance=happie.nmbp
orbit workspace:list --node=app-1
orbit workspace:list --instance=docs --node=app-1
orbit workspace:list --json
```

## Arguments and options

- `--instance`: filter by parent project slug or instance selector. Use dot
  notation such as `happie.nmbp` to target one concrete instance.
- `--node`: filter by owning node slug.
- `--json`: Output JSON.

When both `--instance` and `--node` are supplied, results are the intersection
(workspaces matching both filters).

## What Happens

Run `workspace:list` to view registered workspaces without connecting to nodes.

`workspace:list` reads the workspace registry from the gateway and applies the
requested filters:

1. Connects to the gateway API.
2. Reads workspace registry configuration scoped to what the caller is
   authorized to see.
3. Filters by app, instance, or node when options are supplied (combined
   with AND).
4. Returns a list of workspaces with their names, parent projects, selected app
   instances, effective host nodes, canonical URLs, and registry
   lifecycle statuses.

`workspace:list` does not:
- SSH into nodes.
- Probe workspace artifact health, runtime containers, or filesystem convergence (use
  [`doctor --family=workspace`](../workspace-doctor.md)).
- Mutate gateway configuration or node artifacts.

## Output

Both renderers use the same deterministic ordering: workspaces are sorted by
owning node name, then by parent project name, then by workspace name (alphabetical,
case-insensitive).

Human output presents that ordering as tables grouped by parent project within node.

JSON output returns a flat list of workspaces in the same order under the
machine-readable output. See the
[JSON renderer contract](technical/6.2_workspace-list_output-render_json.md) for
the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized to read the workspace registry.

## Related Commands

Use these commands to inspect or act on individual workspaces.

- [`workspace:new`](../1_workspace-new/workspace-new.md) — register a workspace
- [`workspace:setup`](../2_workspace-setup/workspace-setup.md) — apply workspace artifacts on the node
- [`workspace:show`](../4_workspace-show/workspace-show.md) — inspect a single workspace
- [`workspace:remove`](../5_workspace-remove/workspace-remove.md) — remove a workspace
- [`doctor --family=workspace`](../workspace-doctor.md) — verify and repair workspace drift
- [`project:list`](../../5_project/3_project-list/project-list.md) — list parent projects

## Technical Contract

See [`workspace:list` technical contract](technical/1_workspace-list.md).
