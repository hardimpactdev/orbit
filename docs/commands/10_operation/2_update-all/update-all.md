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

1. Resolve the caller role and authorize the fleet update.
2. Update the caller's local Orbit checkout.
3. Resolve active non-local managed Orbit installations from gateway node intent.
4. Update each selected remote installation through the gateway-owned node
   execution path.
5. Report every per-installation result, including partial failures.

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

- The CLI caller can reach the Orbit gateway, unless invoked directly on the
  gateway.
- The current node identity is authorized to update Orbit installations.
- The gateway can reach every selected non-local managed installation through
  its node execution path.
- Each selected installation has a writable Orbit checkout, Git remote,
  Composer, and PHP runtime capable of running migrations.

## Related Commands

- [`update`](../1_update/update.md) - update only the local Orbit checkout
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update:all` technical contract](technical/1_update-all.md).
