# `orbit app:websocket disable`

[Back to App commands.](../README.md)

Disable WebSocket support for an app.

`app:websocket disable` clears the enabled state on the app WebSocket binding,
removes public host routes for the app, and syncs the Reverb runtime app
configuration. The binding record and its Reverb credentials are retained so
the app can be re-enabled later without generating new credentials.

## Usage

```bash
orbit app:websocket disable <app> [--json]
```

## Examples

```bash
orbit app:websocket disable docs
orbit app:websocket disable docs --json
```

## Arguments and options

- `app`: app name or hostname. Required.
- `--json`: Output JSON.

## What Happens

Run `app:websocket disable` to detach an app from the fleet WebSocket service.

1. Resolves the app by name or hostname.
2. Requires an existing WebSocket binding for the app; returns
   `websocket.binding_missing` when none exists.
3. Sets `enabled=false` and clears `public_hosts` to `[]` on the binding.
4. Syncs public host routes so the cleared host list takes effect on the router.
5. Syncs the Reverb runtime app configuration to remove this app from the
   running Reverb node.

`app:websocket disable` does not:
- Delete the binding record or the stored Reverb credentials. Credentials are
  kept so re-enabling restores the same app identity on the Reverb node.
- Require an active router or WebSocket backend node; route cleanup proceeds
  against the registered router.

## Output

Human output describes the resulting binding after the disable operation,
showing the cleared `public_hosts` and the `internal_host`.

JSON output returns the binding payload under the standard machine-readable
envelope. See the
[JSON renderer contract](technical/6.2_app-websocket-disable_output-render_json.md)
for the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:write` on the app's owning node.
- The app exists in the gateway registry and has an existing WebSocket binding.

## Related Commands

Use these commands alongside `app:websocket disable` to manage WebSocket support
and inspect app configuration.

- [`app:websocket enable`](../12_app-websocket-enable/app-websocket-enable.md) — enable WebSocket support for an app
- [`app:websocket credentials`](../14_app-websocket-credentials/app-websocket-credentials.md) — show Reverb credentials for a WebSocket-enabled app
- [`app:show`](../4_app-show/app-show.md) — inspect the full app entity

## Technical Contract

See [`app:websocket disable` technical contract](technical/1_app-websocket-disable.md).
