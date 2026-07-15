# `orbit solo:todo:delete`

Delete a Solo todo after explicit force consent.

## Usage

```bash
orbit solo:todo:delete <todo> [--node=<node>] [--project=<project>] [--title=<title>] [--body=<body>] [--force] [--json]
```

## Contract

`solo:todo:delete` is available only when the local Solo extension is enabled with `orbit extension:enable solo`. The command calls the gateway Solo proxy route `DELETE /api/solo/todo/delete`; the gateway must also have the Solo extension enabled. Use `--node=<node>` to target that node's configured node-local Solo API; when omitted, Orbit uses local `node:default`, then the caller node. Use `--project=<project>` to scope the todo lookup or mutation to one Solo project; when omitted, Solo applies its default project resolution.

The gateway authorizes the caller with `solo:todo:delete` on the target node and records Orbit activity for the operation. Gateway targets use direct loopback; non-gateway targets use Agent push to target-local loopback. Solo ports and SSH transport are never exposed.

## Output

Use `--json` for the canonical Orbit JSON envelope with one top-level `success` or `error` key. Human output renders the primary returned Solo resource or list for operator use.

## Errors

These errors are stable for automation and human troubleshooting.

- `extension_disabled`: Solo is disabled locally or on the gateway.
- `authorization_failed`: The caller lacks the required permission on the target node.
- `solo_upstream_unavailable`: The configured Solo API on the node cannot be reached.
