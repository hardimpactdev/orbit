# `orbit solo:scratchpad:list`

List Solo scratchpads.

## Usage

```bash
orbit solo:scratchpad:list [--node=<node>] [--json]
```

## Contract

`solo:scratchpad:list` is available only when the local Solo extension is enabled with `orbit extension:enable solo`. The command calls the gateway Solo proxy route `GET /api/solo/scratchpad/list`; the gateway must also have the Solo extension enabled. Use `--node=<node>` to target that node's configured node-local Solo API; when omitted, Orbit uses local `node:default`, then the caller node.

The gateway authorizes the caller with `solo:*` on the target node and records Orbit activity for the operation. Gateway targets use direct loopback; non-gateway targets use Agent push to target-local loopback. Solo ports and SSH transport are never exposed.

## Output

Use `--json` for the canonical Orbit JSON envelope with one top-level `success` or `error` key. Human output renders the primary returned Solo resource or list for operator use.

## Errors

These errors are stable for automation and human troubleshooting.

- `extension_disabled`: Solo is disabled locally or on the gateway.
- `authorization_failed`: The caller lacks the required permission on the target node.
- `solo_upstream_unavailable`: The configured Solo API on the node cannot be reached.
