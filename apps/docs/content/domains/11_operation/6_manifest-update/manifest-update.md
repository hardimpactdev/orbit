# `orbit manifest:update <url>`

[Back to Operation commands.](../README.md)

Set the gateway release manifest URL used by `orbit update:all`.

This command points the gateway at a custom release manifest source, such as a
stable release-candidate channel in the topology artifact store. It lets a
gateway live-test candidate assets before a GitHub release exists. The setting
is stored on the gateway and remains active until `manifest:remove` clears it.

## Usage

```bash
orbit manifest:update <url> [--json]
```

## Examples

```bash
orbit manifest:update https://artifacts.example.com/channels/live-test/orbit-release-manifest.json
orbit manifest:update https://artifacts.example.com/channels/live-test/orbit-release-manifest.json --json
```

## Arguments and Options

- `url`: HTTP or HTTPS release manifest URL.
- `--json`: Output JSON.

## What Happens

Use `manifest:update` to send the URL to the gateway. The gateway validates
that it is an HTTP or HTTPS URL, stores it as the custom manifest source, and
returns the effective manifest source. Future `orbit update:all` runs resolve
the manifest from this custom URL during `Checking for updates`.

The command does not fetch, validate, or install the manifest contents. The
next `update:all` run performs manifest download, schema validation, immutable
plan persistence, and artifact hash checks before side effects.

## Output

Human output shows the selected source and effective URL.

Use `--json` to receive the same selected manifest source in machine-readable
form.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin
  authority (`*` on the active gateway node).
- The supplied URL is reachable by the gateway when `update:all` later resolves
  the manifest.

## Related Commands

Use these commands to apply or undo the selected source.

- [`manifest:remove`](../7_manifest-remove/manifest-remove.md) - clear the
  custom manifest URL
- [`update:all`](../2_update-all/update-all.md) - consume the selected manifest
  source during the fleet update

## Technical Contract

See [`manifest:update` technical contract](technical/1_manifest-update.md).
