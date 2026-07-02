# `orbit solo:agent-tool:list`

List Solo agent tools.

## Usage

```bash
orbit solo:agent-tool:list [--node=<node>] [--json]
```

## Contract

`solo:agent-tool:list` is available only when the local Solo extension is enabled with `orbit extension:enable solo`. The command calls the gateway Solo proxy route `GET /api/solo/agent-tool/list`; the gateway must also have the Solo extension enabled. Use `--node=<node>` to target that node's configured node-local Solo API; when omitted, Orbit targets the gateway node.

The gateway authorizes the caller with `solo:*` on the target node and records Orbit activity for the operation. Solo upstream traffic targets the requested node's configured loopback Solo API URL, or the gateway node when `--node` is omitted; Orbit does not expose Solo localhost ports directly to WireGuard.

## Output

Use `--json` for the canonical Orbit JSON envelope with one top-level `success` or `error` key. Human output renders the primary returned Solo resource or list for operator use.

## Errors

These errors are stable for automation and human troubleshooting.

- `extension_disabled`: Solo is disabled locally or on the gateway.
- `authorization_failed`: The caller lacks the required permission on the target node.
- `solo_upstream_unavailable`: The configured Solo API on the node cannot be reached.
