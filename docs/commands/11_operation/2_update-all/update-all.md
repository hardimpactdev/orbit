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

## Arguments And Options

- `--json`: Output JSON.

## What Happens

`update:all` performs a gateway-authorized fleet update:

1. Resolve the caller role and authorize the fleet update. App-node callers are
   rejected before any side effects.
2. Update the caller's local Orbit checkout.
3. Resolve active non-local managed Orbit installations from gateway node
   intent. A control caller reads this intent from the Gateway API, never from
   any local node table. A gateway caller reads it from local gateway state.
4. Update each selected remote installation through the gateway-owned node
   execution path. The gateway is the only node that opens SSH connections to
   app nodes; control callers never SSH to app nodes themselves.
5. Report every per-installation result, including partial failures.

`update:all` updates the local checkout, the gateway, and active app nodes.
**Control nodes other than the caller are never remote update targets.** Each
control node is an operator workstation and updates through `orbit update` on
that machine. When invoked on the gateway, the command therefore updates the
gateway checkout and selected app nodes only.

The command does not create nodes, deploy apps, change app runtime artifacts, or
repair unrelated family drift. Run doctor after the update when the operator
needs convergence verification.

## Output

Human output shows a per-installation progress tree and reports each updated or
failed target.

JSON output reports all per-installation results. When any target fails, the
command returns a JSON error envelope with successful and failed target results
included as command-specific error data.

## Requirements

- The caller is a control node or the gateway. App-node callers are rejected.
- The CLI caller can reach the Orbit gateway, unless invoked directly on the
  gateway.
- The current node identity is authorized to update Orbit installations.
- The gateway can reach every selected app node through its gateway-owned node
  execution path (SSH via `RemoteShell`).
- Each selected installation has a writable Orbit checkout, Git remote,
  Composer, and PHP runtime capable of running migrations.

## Related Commands

- [`update`](../1_update/update.md) - update only the local Orbit checkout
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update:all` technical contract](technical/1_update-all.md).
