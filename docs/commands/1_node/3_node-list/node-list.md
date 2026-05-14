# `orbit node:list`

[Back to Nodes commands.](../README.md)

List nodes registered in the gateway.

Provides a fleet-wide overview of all nodes, their roles, and their environments.
Useful for operators and CI scripts to audit infrastructure state and verify node
availability.

## Usage

```bash
orbit node:list [--role=<gateway|app|control>] [--environment=<development|production>] [--doctor] [--json]
```

Run from any node with gateway visibility. No arguments are required.

## Examples

```bash
orbit node:list
orbit node:list --role=app
orbit node:list --environment=development
orbit node:list --doctor
orbit node:list --role=app --environment=development --json
```

## Arguments and options

- `--role`: filter by node role. Single value, one of `gateway`, `app`, or `control`. Comma-separated input is rejected.
- `--environment`: filter app nodes by environment. Single value, one of `development` or `production`. Comma-separated input is rejected.
- `--doctor`: include node doctor checks and summaries. This is explicit because it may perform live checks and take longer than a registry list.
- `--json`: Output JSON.

## What Happens

`node:list` reads gateway registry state visible to the current consuming node.
On the gateway, lists all nodes unless filters are provided. Does not probe hosts
as part of the default list operation. Live node reality belongs to
`doctor --family=node` or the explicit node-family-only `--doctor` summary flag.

Only nodes visible to the authenticated caller are returned. Hidden nodes are
omitted entirely; `node:list` does not surface placeholder rows for nodes the
caller cannot access. An authorized caller with no visible nodes sees an
empty list and exit zero. A caller that is not authorized to read the node
registry at all receives an authorization error.

When `--doctor` is supplied, runs node doctor checks as an explicit secondary
operation and includes the resulting summary. Doctor findings do not change
the exit code: the command exits zero whenever the registry read succeeds.
Use `orbit doctor --family=node [--json]` when exit-on-drift semantics are
required.

This flag is intentionally limited to `node:list`. App and workspace list
commands stay registry-only and direct live verification to their family doctor
commands.

## Output

Human output is a table grouped by role.

JSON output is a structured node array. See the
[JSON renderer contract](technical/6.2_node-list_output-render_json.md) for the
envelope shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible node registry configuration.

## Related Commands

Use these commands to inspect individual nodes or run deeper verification after listing.

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`node:remove`](../8_node-remove/node-remove.md) — remove a node from the fleet
- [`doctor --family=node`](../node-doctor.md) — verify and repair node drift

## Technical Contract

See [`node:list` technical contract](technical/1_node-list.md).
