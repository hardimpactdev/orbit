# `orbit manifest:remove`

[Back to Operation commands.](../README.md)

Clear the custom gateway release manifest URL.

Use this command after release-candidate testing to return the gateway to its
configured default release manifest source, normally GitHub Releases.

## Usage

```bash
orbit manifest:remove [--json]
```

## Examples

```bash
orbit manifest:remove
orbit manifest:remove --json
```

## Arguments and Options

- `--json`: Output JSON.

## What Happens

Use `manifest:remove` to ask the gateway to delete the stored custom manifest
URL. Future `orbit update:all` runs resolve the manifest from the configured
default URL.

The command does not start an update, remove candidate artifacts, or change
release assets in GitHub or the topology artifact store.

## Output

Human output shows that the override was removed and prints the effective URL.

Use `--json` to receive the selected default source in machine-readable form.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin
  authority (`*` on the active gateway node).

## Related Commands

Use these commands to switch the source again or consume it.

- [`manifest:update`](../6_manifest-update/manifest-update.md) - set a custom
  manifest URL
- [`update:all`](../2_update-all/update-all.md) - consume the selected manifest
  source during the fleet update

## Technical Contract

See [`manifest:remove` technical contract](technical/1_manifest-remove.md).
