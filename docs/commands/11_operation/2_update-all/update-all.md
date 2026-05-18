# `orbit update:all`

[Back to Operation commands.](../README.md)

Update the local Orbit checkout and every managed Orbit installation selected
for a fleet update.

This is the fleet update command. It is useful after new Orbit code lands and
the operator needs all Orbit-capable nodes to run the same checkout version.
It updates Orbit installations only; it does not deploy apps or repair drift.

## Usage

```bash
orbit update:all [--json]
```

## Examples

```bash
orbit update:all
orbit update:all --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

`update:all` performs a gateway-authorized fleet update:

1. Ask the gateway to authorize the fleet update. The gateway identifies the calling peer over WireGuard and applies authorization; the CLI does not classify itself.
2. Update the caller-local checkout and gateway-local checkout.
3. After the gateway-local checkout succeeds, the gateway updates selected
   remote app-node installations in parallel, up to four targets at a time. The
   gateway is the only node that opens SSH connections to app nodes; the CLI
   never SSHes to other nodes itself.
4. Report every per-installation result, including partial failures.

`update:all` updates the local checkout, the gateway, and active app nodes.
**Operator nodes other than the caller are never remote update targets.** Each
operator node is an operator workstation and updates through `orbit update` on
that machine. When the gateway is the calling peer, the command therefore
updates the gateway checkout and selected app nodes only.

The command does not create nodes, deploy apps, change app runtime artifacts, or
repair unrelated family drift. Run doctor after the update when the operator
needs convergence verification.

## Output

Run `orbit update:all` to see per-node progress and a final summary of updated and failed nodes.

Human output shows per-node progress and a final summary. Rows for selected
targets whose update has not started yet show `Waiting`.

Use `--json` for machine-readable output. See the
[JSON renderer contract](technical/6.2_update-all_output-render_json.md) for
the exact shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer to update Orbit installations. App-node peers are rejected.
- The gateway can reach every selected app node through its node execution path (SSH via `RemoteShell`).
- Each selected installation has a writable Orbit checkout, Git remote,
  Composer, and PHP runtime capable of running migrations.

## Related Commands

Use these commands before or after running `orbit update:all`.

- [`update`](../1_update/update.md) - update only the local Orbit checkout
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update:all` technical contract](technical/1_update-all.md).
