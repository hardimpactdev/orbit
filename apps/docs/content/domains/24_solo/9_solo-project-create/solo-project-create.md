# `orbit solo:project:create`

Create a Solo project.

## Usage

```bash
orbit solo:project:create <name> [--json]
```

## Contract

`solo:project:create` is available only when the local Solo extension is enabled with `orbit extension:enable solo`. The command calls the gateway Solo proxy route `POST /api/solo/project/create`; the gateway must also have the Solo extension enabled before execution reaches the node-local Solo API.

The gateway authorizes the caller with `solo:project:create` and records Orbit activity for the operation. Solo upstream traffic targets the gateway node's configured loopback Solo API URL; Orbit does not expose Solo localhost ports directly to WireGuard.

## Output

Use `--json` for the canonical Orbit JSON envelope with one top-level `success` or `error` key. Human output renders the primary returned Solo resource or list for operator use.

## Errors

These errors are stable for automation and human troubleshooting.

- `extension_disabled`: Solo is disabled locally or on the gateway.
- `authorization_failed`: The caller lacks the required gateway permission.
- `solo_upstream_unavailable`: The configured Solo API on the node cannot be reached.
