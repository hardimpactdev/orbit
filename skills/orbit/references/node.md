# Node Commands

Manage the fleet: gateway nodes, operator identities, and workload nodes. Spec: [`apps/docs/content/domains/1_node/`](../../../apps/docs/content/domains/1_node/).

## `orbit node:new [name]`

Register or provision a node.

```bash
orbit node:new [<name>] [--role=<role>]... [--host=<host>]
               [--operator-name=<name>] [--environment=development|production]
               [--tld=<tld>] [--user=<user>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` | — | Registry slug (unique). |
| `--role` | `operator` identity shape when omitted | Product roles: `gateway`, `operator`, `vpn`, `router`, `app-development`, `app-production`, `database`, `agent`, `ingress`, `websocket`, and `s3`. `app-dev`/`app-prod` are CLI aliases for the app roles. `websocket` and `s3` are documented next roles and may lag command implementation during active development. |
| `--host` | — | SSH/bootstrap endpoint. Required for `gateway` and host-capable workload roles. Forbidden for `operator`. |
| `--operator-name` | local short hostname | First-gateway bootstrap only — the initiating operator node's name. |
| `--environment` | — | App nodes: `development` or `production`. |
| `--tld` | — | Development app-node TLD (no leading dot, e.g. `beast`). Required for development app nodes. |
| `--user` | `root` | Bootstrap-only SSH user; the managed steady-state user is created during provisioning. |
| `--json` | off | JSON output. |

By role:

- **`--role=operator`**: mints a WireGuard identity on the gateway and prints the config. The operator installs that config on the operator machine, then runs `gateway:add` there. No SSH to the operator machine.
- **`--role=app-development` / `--role=app-dev`**: provisions the host over SSH if needed, joins WireGuard, installs Orbit runtime, records the TLD, and creates the gateway DNS mapping for `*.tld`.
- **`--role=app-production` / `--role=app-prod`**: provisions a production app host and places it behind an ingress node.
- **`--role=database`, `--role=agent`, `--role=ingress`**: provisions the corresponding workload role.
- **`--role=gateway`**: bootstraps or adopts the gateway. When invoked from an operator node with no configured gateway, the command also onboards the initiating operator node (mints WireGuard, trusts CA, stores endpoint) — that operator node should **not** run `gateway:add` afterward.

Examples:

```bash
orbit node:new my-mac --role=operator
orbit node:new beast --role=app-dev --host=beast.lan --tld=beast
orbit node:new prod-1 --role=app-prod --host=203.0.113.20 --ingress=edge-1
orbit node:new gateway-1 --role=gateway --host=203.0.113.2 --operator-name=my-mac
```

## `orbit node:list`

List nodes from the gateway registry.

```bash
orbit node:list [--role=gateway|operator|app-development|app-production|database|agent|ingress] [--environment=development|production] [--doctor] [--json]
```

`--doctor` includes live readiness / drift summaries (federated across nodes).

## `orbit node:show [name]`

Show one node's registry record.

```bash
orbit node:show [<name>] [--json]
```

## `orbit node:update [name]`

Update node registry metadata and role-owned settings.

```bash
orbit node:update [<name>] [--host=<host>] [--environment=<env>] [--tld=<tld>]
                  [--public-ipv4=<ip>] [--public-ipv6=<ip>] [--json]
```

`--public-ipv4` / `--public-ipv6` are explicit operator metadata used when verifying production app domain DNS. Orbit does **not** infer them from `--host`.

`--tld` only applies to development app nodes. Setting it on a production node, gateway, or operator fails with `node.field_role_incompatible`. Setting it to a TLD already in use by another active node fails with `node.tld_in_use`. Combine `--tld=test --environment=development` to switch a production app node to development with a TLD in one call.

## `orbit node:remove [name]`

Remove a node from the registry. Destructive.

```bash
orbit node:remove [<name>] [--force] [--json]
```

## `orbit node:default [name]`

Choose, show, set, or clear the local operator node's default development app node. Saves typing `--node` everywhere.

```bash
orbit node:default                # show current
orbit node:default <name>         # set
orbit node:default --clear        # clear
orbit node:default --json
```

App nodes don't use this — they resolve themselves.

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

## `orbit node:agent-ide [name] [adapter]`

Set the default Agent IDE adapter for a node. Apps inherit this unless overridden by `app:agent-ide`.

```bash
orbit node:agent-ide [<name>] [opencode|polyscope|none] [--json]
```

Per-tool credentials live in the `tools` family — use `orbit tool:show opencode-server` and `orbit tool:credentials opencode-server` for those.
