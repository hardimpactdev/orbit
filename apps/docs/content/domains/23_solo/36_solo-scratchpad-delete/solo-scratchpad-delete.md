# `orbit solo:scratchpad:delete`

Delete a Solo scratchpad. Interactive human mode prompts unless `--force` is
supplied; non-interactive mode requires `--force`.

## Usage

```bash
orbit solo:scratchpad:delete <scratchpad> [--node=<node>] [--content=<content>] [--heading=<heading>] [--search=<search>] [--replace=<replace>] [--name=<name>] [--expected-revision=<expected-revision>] [--force] [--json]
```

## Contract

`solo:scratchpad:delete` is available only when the local Solo extension is enabled with `orbit extension:enable solo`. The command calls the gateway Solo proxy route `DELETE /api/solo/scratchpad/delete`; the gateway must also have the Solo extension enabled. Use `--node=<node>` to target that node's configured node-local Solo API; when omitted, Orbit uses local `node:default`, then the caller node.

The gateway authorizes the caller with `solo:scratchpad:write` on the target node and records Orbit activity for the operation. Gateway targets use direct loopback; non-gateway targets use Agent push to target-local loopback. Solo ports and SSH transport are never exposed.

## Output

Use `--json` for the canonical Orbit JSON envelope with one top-level `success` or `error` key. Human output renders the primary returned Solo resource or list for operator use.

## Errors

These errors are stable for automation and human troubleshooting.

- `extension_disabled`: Solo is disabled locally or on the gateway.
- `authorization_failed`: The caller lacks the required permission on the target node.
- `validation_failed`: Required destructive consent is missing or was declined (`meta.field=force`, `meta.reason=destructive_consent_required`).
- `solo_upstream_unavailable`: The configured Solo API on the node cannot be reached.
