# `orbit app:websocket disable`

[Back to App commands.](../README.md)

Disable WebSocket support for one concrete app instance.

`app:websocket disable` clears the selected instance binding's enabled state,
removes that binding's public host routes, and syncs the Reverb runtime app
configuration. The binding record and its Reverb credentials are retained so
the app can be re-enabled later without generating new credentials.

## Usage

```bash
orbit app:websocket disable <app.instance> [--json]
```

## Examples

```bash
orbit app:websocket disable docs.production
orbit app:websocket disable docs.production --json
```

## Arguments and options

- `app.instance`: dotted app-instance selector. A bare app slug is shorthand
  only when exactly one eligible visible instance exists.
- `--json`: Select JSON output and non-interactive input. It does not select a
  target or imply consent.

## What Happens

Run `app:websocket disable` to detach an app from the fleet WebSocket service.

1. Resolves exactly one concrete app instance and its serving node.
2. Requires an existing WebSocket binding for that instance; returns
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
- The current node identity holds `app:write` on the selected instance's
  serving node.
- The selected app instance exists and has an existing WebSocket binding.

## Related Commands

Use these commands alongside `app:websocket disable` to manage WebSocket support
and inspect app configuration.

- [`app:websocket enable`](../12_app-websocket-enable/app-websocket-enable.md) — enable WebSocket support for an app
- [`app:websocket credentials`](../14_app-websocket-credentials/app-websocket-credentials.md) — show Reverb credentials for a WebSocket-enabled app
- [`app:show`](../4_app-show/app-show.md) — inspect the full app entity

## Technical Contract

See [`app:websocket disable` technical contract](technical/1_app-websocket-disable.md).
