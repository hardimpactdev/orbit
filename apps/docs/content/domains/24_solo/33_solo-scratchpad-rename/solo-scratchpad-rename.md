# `orbit solo:scratchpad:rename`

Rename a Solo scratchpad with optional revision guard.

## Usage

```bash
orbit solo:scratchpad:rename <scratchpad> [--node=<node>] [--content=<content>] [--heading=<heading>] [--search=<search>] [--replace=<replace>] [--name=<name>] [--expected-revision=<expected-revision>] [--force] [--json]
```

## Contract

`solo:scratchpad:rename` is available only when the local Solo extension is enabled with `orbit extension:enable solo`. The command calls the gateway Solo proxy route `PATCH /api/solo/scratchpad/rename`; the gateway must also have the Solo extension enabled. Use `--node=<node>` to target that node's configured node-local Solo API; when omitted, Orbit targets the gateway node.

The gateway authorizes the caller with `solo:scratchpad:write` on the target node and records Orbit activity for the operation. Solo upstream traffic targets the requested node's configured loopback Solo API URL, or the gateway node when `--node` is omitted; Orbit does not expose Solo localhost ports directly to WireGuard.

## Output

Use `--json` for the canonical Orbit JSON envelope with one top-level `success` or `error` key. Human output renders the primary returned Solo resource or list for operator use.

## Errors

These errors are stable for automation and human troubleshooting.

- `extension_disabled`: Solo is disabled locally or on the gateway.
- `authorization_failed`: The caller lacks the required permission on the target node.
- `solo_upstream_unavailable`: The configured Solo API on the node cannot be reached.
