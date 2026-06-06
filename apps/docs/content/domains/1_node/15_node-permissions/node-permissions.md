# `orbit node:permissions [consuming_node] [serving_node]`

[Back to Nodes commands.](../README.md)

View, update, or upsert the scoped permission set stored on a node access
grant.

Use `node:permissions` when you need to inspect or change what one consuming
node may do on one serving node after the grant edge already exists.
`node:grant` creates the initial edge and its initial permissions, and
`node:permissions` owns every later read or write of that permission set. The
command may also create a missing grant edge when you submit a valid
non-empty permission set through interactive selection, `--preset`,
`--permissions`, or `--add`.

## Usage

Run this command to view or change the permission set on a grant.

```bash
orbit node:permissions [consuming_node] [serving_node] [--preset=<preset>] [--permissions=<list>] [--add=<list>] [--remove=<list>] [--json]
orbit node:permissions
```

You must choose at most one of `--preset`, `--permissions`, `--add`, and
`--remove`. Each of `--permissions`, `--add`, and `--remove` accepts a
comma-separated permission list.

## Examples

```bash
orbit node:permissions agent-1 app-1 --json
orbit node:permissions agent-1 app-1 --preset=operator --json
orbit node:permissions agent-1 app-1 --permissions=tool:read,doctor:verify --json
orbit node:permissions agent-1 app-1 --add=tool:update --json
orbit node:permissions agent-1 app-1 --remove=tool:update --json
```

## Arguments and options

- `consuming_node`: node whose grant to the serving node is being viewed or
  changed. Must match an existing active node record in gateway configuration.
- `serving_node`: node that the consuming node may operate on. Must match an
  existing active node record in gateway configuration.
- `--preset`: replace the permission set with the normalized expansion of the
  named preset.
- `--permissions`: replace the permission set with the normalized expansion of
  this comma-separated list.
- `--add`: merge these comma-separated permissions into the current set, then
  normalize. May create a missing grant by treating the current set as empty.
- `--remove`: remove these comma-separated permissions from the current set,
  then normalize. Requires an existing grant.
- `--json`: Output JSON.

## Modes

`node:permissions` has one read mode and four mutating modes. Exactly one
mutating flag may be supplied; combining modes fails before side effects.

| Mode | Selected by | Behavior on existing grant | Behavior on missing grant |
| --- | --- | --- | --- |
| Read | no mode flag supplied | Return the current normalized permission set. | Fail with `node.grant_not_found`. |
| Replace by preset | `--preset` | Replace the permission set with the normalized preset. | Create the grant when the normalized result is non-empty. |
| Replace by list | `--permissions` | Replace the permission set with the normalized list. | Create the grant when the normalized result is non-empty. |
| Additive merge | `--add` | Merge the listed permissions into the current set, then normalize. | Treat the current set as empty, then merge, normalize, and create the grant when the result is non-empty. |
| Subtractive remove | `--remove` | Subtract the listed permissions from the current set, then normalize. | Fail with `node.grant_not_found`. |

Mutation responses report whether the grant was `created` or `updated`. When
the normalized result equals the existing set, the command reports
`updated` with no diff. Redundant permissions submitted to `--permissions`,
`--add`, or `--preset` are normalized away; the command surfaces a warning
that lists the removed permissions so you can adjust your automation.

## Interactive mode

Run without `--preset`, `--permissions`, `--add`, or `--remove` in an
interactive terminal to drive the command through Laravel Prompts:

```text
Select consuming node
Select serving node
Select permissions
```

The permission selector is a multiselect preselected with the current grant
permissions when an edge exists, or with no permissions when the edge is
missing. Submitting the multiselect replaces the permission set with exactly
the selected normalized permissions. Submitting a non-empty set against a
missing grant edge creates the edge.

## What Happens

Run `node:permissions` to view or change the scoped permission set on a node
access grant.

1. Resolve the `consuming_node` and `serving_node` arguments and validate
   that both match active node records.
2. Authorize the call. Read mode requires the caller's grant to the gateway to
   include `node:read` or `*`; write modes require `node:permissions` or `*`.
   Callers without the required permission receive `authorization_failed`.
3. Compute the normalized target permission set from the requested mode.
4. Apply the change to the grant. Reads return the current permissions
   without mutation. Mutations write the normalized set and report whether
   the grant was created or updated.

`node:permissions` does not:

- Edit grants unless the caller holds `node:permissions` or `*` on a grant to
  the gateway.
- Create a grant in read mode or in `--remove` mode.
- Repair drift owned by `doctor --family=node`.
- Mutate node host state.

## Output

You will see the resolved consuming and serving node names, the selected
mode, the new normalized permission set, and whether the grant was created
or updated.

Add `--json` for a machine-readable payload. See the
[JSON renderer contract](technical/6.2_node-permissions_output-render_json.md)
for the exact payload shape.

## Requirements

- Must run on the gateway host or from a configured client.
- Read mode requires `node:read` or `*` on the caller's grant to the gateway.
- Write modes require `node:permissions` or `*` on the caller's grant to the
  gateway.
- Both target nodes must exist in gateway configuration.
- The mutually exclusive modes `--preset`, `--permissions`, `--add`, and
  `--remove` cannot be combined.
- Read mode and `--remove` require an existing grant edge; the gateway fails
  with `node.grant_not_found` when the edge is absent.

## Related Commands

Use these commands to manage grants and inspect node state.

- [`node:grant`](../5_node-grant/node-grant.md) — create a node access grant
  with initial permissions
- [`node:revoke`](../6_node-revoke/node-revoke.md) — remove a node access grant
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`doctor --family=node`](../node-doctor.md) — verify node drift, including
  grant permission integrity

## Technical Contract

See [`node:permissions` technical contract](technical/1_node-permissions.md).
