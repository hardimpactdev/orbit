# Node Commands

Manage the fleet: gateway nodes, client identities, grants, role assignments,
and workload nodes. Spec:
[`apps/docs/content/domains/1_node/`](../../../apps/docs/content/domains/1_node/).

## `orbit node:new [name]`

Register or provision a node.

```bash
orbit node:new [<name>] [--template=<template>] [--operator] [--roles=<roles>]
               [--host=<host>] [--operator-name=<name>] [--operator-tld=<tld>]
               [--tld=<tld>]
               [--user=<user>] [--gateway-endpoint=<endpoint>]
               [--ingress=<node>] [--redis-node=<node>]
               [--postgres-node=<node>] [--clickhouse-node=<node>]
               [--s3-data-path=<path>] [--host-key-fingerprint=<sha256>]
               [--self-grant=<mode>] [--self-grant-permissions=<perms>]
               [--grant-to=<node>]... [--grant-to-preset=<preset>]
               [--grant-to-permissions=<perms>]
               [--grant-from=<node>]... [--grant-from-preset=<preset>]
               [--grant-from-permissions=<perms>]
               [--agent-tool=<tool>]... [--json|--stream-json]
```

| Option | Default | Notes |
|---|---|---|
| `name` |  -  | Registry slug (unique). |
| `--template` |  -  | One of `operator`, `app-development`, `app-production`, `gateway`, `ingress`, `database`, `s3`, `websocket`, `metrics`, or `agent`. Templates expand to role sets. |
| `--operator` | off | Create a client identity with the operator permission preset and no workload roles. Operator is not a node role. |
| `--roles` |  -  | Comma-separated canonical public roles: `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`, `metrics`. |
| `--host` |  -  | SSH/bootstrap endpoint. Required for gateway bootstrap and host-capable workload roles. Forbidden for client identities with no roles. |
| `--operator-name` | local short hostname | First-gateway bootstrap only  -  the initiating client identity's name. |
| `--operator-tld` |  -  | Required first-gateway-bootstrap TLD for the separate initiating operator identity. |
| `--tld` |  -  | Required explicit unique node TLD (no leading dot) for every node identity. Wildcard development DNS mappings apply when a workload derives development routes from it. |
| `--user` | `root` | Bootstrap-only SSH user; the managed steady-state user is created during provisioning. |
| `--gateway-endpoint` | gateway public endpoint | WireGuard endpoint this node should use to reach the gateway. Useful for nodes in the same private provider network. |
| `--ingress` |  -  | Active ingress node for private `app-prod` placement. |
| `--redis-node` |  -  | Active database-role node that backs `websocket` Reverb scaling. |
| `--postgres-node` |  -  | Active database-role node for analytics PostgreSQL. |
| `--clickhouse-node` |  -  | Active database-role node for analytics ClickHouse. |
| `--s3-data-path` | `/srv/orbit/s3/data` | Host path mounted into SeaweedFS as `/data`. |
| `--host-key-fingerprint` |  -  | Expected SSH host key SHA256 fingerprint for bootstrap verification. |
| `--self-grant` |  -  | Self-grant mode for the new node. |
| `--self-grant-permissions` |  -  | Custom self-grant permissions when self-granting. |
| `--grant-to` |  -  | Grant the new node access to another node (repeatable). |
| `--grant-to-preset` |  -  | Preset permissions for `--grant-to` grants. |
| `--grant-to-permissions` |  -  | Custom permissions for `--grant-to` grants. |
| `--grant-from` |  -  | Grant another node access to the new node (repeatable). |
| `--grant-from-preset` |  -  | Preset permissions for `--grant-from` grants. |
| `--grant-from-permissions` |  -  | Custom permissions for `--grant-from` grants. |
| `--agent-tool` |  -  | Agent tool to install on `agent` nodes (repeatable). |
| `--json` | off | JSON output. Mutually exclusive with `--stream-json`. |
| `--stream-json` | off | Newline-delimited gateway progress frames for long-running agent runs. Mutually exclusive with `--json`. First-gateway bootstrap keeps its existing bootstrap output path. |

By role:

- **`--operator` / `--template=operator`**: mints a WireGuard identity on the
  gateway and prints the config. Install that config on the client machine, then
  run `gateway:add` there. No SSH to the client machine.
- **`--template=app-development`**: provisions an `app-dev` node with a colocated
  `database` role by default, joins WireGuard, installs Orbit runtime, records
  the TLD, and creates the gateway-owned development DNS mapping for `*.tld`.
- **`--template=app-production`**: provisions `app-prod`; it either colocates
  `ingress` or requires `--ingress` for private app-prod placement.
- **`--template=database`, `--template=agent`, `--template=ingress`,
  `--template=websocket`, `--template=s3`, `--template=metrics`**: provisions
  the corresponding workload role.
- **`--template=gateway`**: bootstraps or adopts the gateway with coupled
  `gateway`, `vpn`, and `router` assignments. During first-gateway bootstrap it
  also onboards the initiating client identity; do not run `gateway:add`
  afterward on that same initiating machine.

Examples:

```bash
orbit node:new my-mac --operator --tld=my-mac
orbit node:new beast --template=app-development --host=beast.lan --tld=beast
orbit node:new prod-1 --template=app-production --host=203.0.113.20 --tld=prod-1 --ingress=edge-1
orbit node:new storage-1 --template=s3 --host=10.0.0.20 --tld=storage-1 --s3-data-path=/srv/orbit/s3/data
orbit node:new gateway-1 --template=gateway --host=203.0.113.2 --tld=gateway --operator-name=my-mac --operator-tld=my-mac
```

## `orbit node:list`

List nodes from the gateway registry.

```bash
orbit node:list [--role=gateway|vpn|router|app-dev|app-prod|database|agent|ingress|websocket|s3|metrics] [--json]
```

`node:list` reads gateway registry state only. The `PEER IP`/WireGuard address
comes from `addresses.wireguard`; it never falls back to `host`.

## `orbit node:show [name]`

Show one node's registry record.

```bash
orbit node:show [<name>] [--json]
```

## `orbit node:update [name]`

Update node registry metadata and explicit managed intent.

```bash
orbit node:update [<name>] [--host=<host>] [--user=<runtime-user>] [--tld=<tld>]
                  [--managed|--no-managed]
                  [--gateway-endpoint=<endpoint>]
                  [--public-ipv4=<ip>] [--public-ipv6=<ip>] [--json]
```

`--public-ipv4` / `--public-ipv6` are explicit operator metadata used when verifying production app domain DNS. Orbit does **not** infer them from `--host`.

`--gateway-endpoint` updates the endpoint stored for the target node's
WireGuard peer and rewrites the peer config Orbit stores for that node. It does
not restart the node's WireGuard interface.

`--tld` updates the mandatory node-level TLD for any active node, including
gateway and operator targets. Setting it to a TLD already in use by another
active node fails with `node.tld_in_use`. Gateway VPN DNS publishes
`orbit.<node-tld>` to the node's WireGuard address.

`--managed` opts a roleless supported node into Agent execution intent;
`--no-managed` clears that explicit opt-in. Workload-role nodes derive intent
from their active role assignments. Gateway nodes remain ineligible.

## `orbit node:remove [name]`

Remove a node from the registry. Destructive.

```bash
orbit node:remove [<name>] [--force] [--json]
```

## `orbit node:default [name]`

Choose, show, set, or clear the local default development node. Saves typing
`--node` for development-flavored commands.

```bash
orbit node:default                # show current
orbit node:default <name>         # set
orbit node:default --clear        # clear
orbit node:default --json
```

Workload nodes can usually resolve themselves from local context, but explicit
`--node` always wins.

## `orbit node:grant <consumer> <server>`

Grant the `consumer` node access to the `server` node. Authorization is node-grant based; non-gateway callers need a grant to the serving node that owns the resource they're touching.

```bash
orbit node:grant <consuming_node> <serving_node> [--json]
```

## `orbit node:revoke [consumer] [server]`

Revoke a previously granted node-to-node access.

```bash
orbit node:revoke [<consuming_node> [<serving_node>]] [--force] [--json]
```

## `orbit node:permissions [consumer] [server]`

Inspect or change the permission set on a node access grant.

```bash
orbit node:permissions [<consuming_node> [<serving_node>]]
                       [--preset=<preset>] [--permissions=<list>]
                       [--add=<list>] [--remove=<list>] [--json]
```

Presets include `agent-self`, `operator`, `read-only`, `developer`, `admin`,
and `gateway-admin`. Use explicit permissions when a preset is too broad.

## `orbit node role:add | list | remove`

Manage assignable hosted roles on existing nodes.

```bash
orbit node role:list [<node>] [--json]
orbit node role:add <node> <role> [--redis-node=<node>]
                    [--s3-data-path=<path>] [--json]
orbit node role:remove <node> <role> [--force] [--purge-data] [--json]
```

Only hosted roles that the product marks assignable can be mutated here.
Gateway-coupled `gateway`, `vpn`, and `router` are bootstrap-owned. `agent` is
only selectable during `node:new`. If synchronous role convergence leaves the
assignment in `error`, role add fails and the assignment remains repairable
through node doctor.

## `orbit node:agent-ide [name] [adapter]`

Set the default Agent IDE adapter for a node. Apps inherit this unless overridden by `app:agent-ide`.

```bash
orbit node:agent-ide [<name>] [opencode|polyscope|none] [--json]
```

Per-tool credentials live in the `tools` family  -  use `orbit tool:show opencode-server` and `orbit tool:credentials opencode-server` for those.
