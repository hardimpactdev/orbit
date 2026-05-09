# Node Commands

Manage the fleet: gateway, app, and control nodes. Spec: [`docs/commands/1_node/`](../../../docs/commands/1_node/).

## `orbit node:new [name]`

Register or provision a node.

```bash
orbit node:new [<name>] --role=gateway|app|control [--host=<host>]
               [--control-name=<name>] [--environment=development|production]
               [--tld=<tld>] [--ssh-user=<user>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` | — | Registry slug (unique). |
| `--role` | required | `gateway`, `app`, or `control`. |
| `--host` | — | SSH/bootstrap endpoint. **Required** for `gateway` and `app`. Forbidden for `control`. |
| `--control-name` | local short hostname | First-gateway bootstrap only — the initiating control node's name. |
| `--environment` | — | App nodes: `development` or `production`. |
| `--tld` | — | Development app-node TLD (no leading dot, e.g. `beast`). Required for development app nodes. |
| `--ssh-user` | `root` | Bootstrap-only SSH user; the managed steady-state user is created during provisioning. |
| `--json` | off | JSON output. |

By role:

- **`--role=control`**: mints WireGuard identity on the gateway and prints the config. Operator installs that config on the control machine, then runs `gateway:add` there. No SSH to the control machine.
- **`--role=app`**: provisions the host over SSH if needed, joins WireGuard, installs Orbit runtime. Development app nodes record the TLD and create the gateway DNS mapping for `*.tld`.
- **`--role=gateway`**: bootstraps or adopts the gateway. When invoked from a control node with no configured gateway, the command also onboards the initiating control node (mints WireGuard, trusts CA, stores endpoint) — that control node should **not** run `gateway:add` afterward.

Examples:

```bash
orbit node:new my-mac --role=control
orbit node:new beast --role=app --host=beast.lan --environment=development --tld=beast
orbit node:new prod-1 --role=app --host=203.0.113.20 --environment=production
orbit node:new gateway-1 --role=gateway --host=203.0.113.2 --control-name=my-mac
```

## `orbit node:list`

List nodes from the gateway registry.

```bash
orbit node:list [--role=gateway|app|control] [--environment=development|production] [--doctor] [--json]
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

`--tld` only applies to development app nodes. Setting it on a production node, gateway, or control fails with `node.field_role_incompatible`. Setting it to a TLD already in use by another active node fails with `node.tld_in_use`. Combine `--tld=test --environment=development` to switch a production app node to development with a TLD in one call.

## `orbit node:remove [name]`

Remove a node from the registry. Destructive.

```bash
orbit node:remove [<name>] [--force] [--json]
```

## `orbit node:default [name]`

Choose, show, set, or clear the local control node's default development app node. Saves typing `--node` everywhere.

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
