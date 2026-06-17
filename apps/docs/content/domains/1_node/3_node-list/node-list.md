# `orbit node:list`

[Back to Nodes commands.](../README.md)

List nodes registered in the gateway.

Provides a fleet-wide overview of all nodes and their roles.
Useful for operators and CI scripts to audit infrastructure state and verify node
availability.

## Usage

```bash
orbit node:list [--role=<gateway|vpn|router|app-dev|app-prod|database|agent|ingress|websocket|s3|metrics>] [--json]
```

Run from any node with gateway visibility. No arguments are required.

## Examples

```bash
orbit node:list
orbit node:list --role=vpn
orbit node:list --role=router
orbit node:list --role=app-prod
orbit node:list --role=app-dev --json
```

## Arguments and options

This section defines each accepted filter and output flag for `node:list`.

### `--role`

Filters by effective role assignment. Accepts a single role value:
`gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`,
`agent`, `ingress`, `websocket`, `s3`, or `metrics`. Comma-separated input is rejected.

### `--json`

Outputs JSON.

## What Happens

Run `node:list` to see the gateway registry state visible to your current consuming node.

`node:list` reads gateway registry state visible to the current consuming node.
On the gateway, lists all nodes unless filters are provided. Does not probe hosts
as part of the list operation. Live node reality belongs to
`doctor --family=node`.

Only nodes visible to the authenticated caller are returned. Hidden nodes are
omitted entirely; `node:list` does not surface placeholder rows for nodes the
caller cannot access. An authorized caller with no visible nodes sees an
empty list. A caller that is not authorized to read the node registry at all
receives an authorization error.

## Output

Human output is a table sorted by effective role assignment. The first column is
an unlabeled health indicator: a green filled circle for active nodes and a red
filled circle for inactive nodes. The remaining columns are `NAME`, `PEER IP`,
`PLATFORM`, and `ROLES`.

`PEER IP` is the node's Orbit WireGuard address. The bootstrap or public
`host` metadata is never rendered as a peer IP. Nodes without a recorded
WireGuard address render `unknown` in human output.

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
