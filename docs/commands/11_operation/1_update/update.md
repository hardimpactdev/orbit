# `orbit update`

[Back to Operation commands.](../README.md)

Update the Orbit checkout on the machine where the command is invoked.

This command is the local update path. It pulls the current source tree,
installs dependencies, and applies local Orbit migrations for this checkout. It
does not update other nodes and does not repair fleet drift.

## Usage

```bash
orbit update [--json]
```

## Examples

```bash
orbit update
orbit update --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

`update` runs the local Orbit update sequence:

1. Verify the local checkout can be updated.
2. Pull the configured Git remote with fast-forward-only semantics.
3. Install Composer dependencies.
4. Run Orbit database migrations for the local checkout.
5. Report the local update result.

The command affects only the current Orbit installation. On a gateway host, local migrations may change the gateway database schema as part of the normal Laravel migration path, but `update` does not create or modify fleet configuration.

Use [`update:all`](../2_update-all/update-all.md) when the operator needs to
roll out the same Orbit code update across the fleet.

## Output

Run `orbit update` to see a live progress tree and a final success or failure result.

Human output shows a live progress tree for the local update sequence and
reports whether the local checkout updated successfully.

```text
┌ Update Orbit
○ Pull source
○ Install dependencies
○ Run migrations
└ Local Orbit checkout updated
```

As each step runs, the command updates that line in place. Completed steps are
shown as completed, and a failed step remains visible with captured Git,
Composer, or migration output printed below the tree.

JSON output reports the local update result and any failure metadata using the
shared command envelope.

## Requirements

- The local Orbit checkout is writable.
- The checkout has a configured Git remote.
- Composer is available on the local machine.
- The local PHP runtime can run Orbit migrations.

## Related Commands

Use these commands before or after running `orbit update`.

- [`update:all`](../2_update-all/update-all.md) - update the local checkout and
  every managed Orbit installation
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update` technical contract](technical/1_update.md).
