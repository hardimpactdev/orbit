# `orbit app:websocket credentials`

[Back to App commands.](../README.md)

Show WebSocket credentials for one concrete app instance.

`app:websocket credentials` reads the Reverb credentials stored on the selected
instance's WebSocket binding and returns them alongside that binding. The
command requires the instance to have an enabled WebSocket binding; it returns
`websocket.binding_missing` when the app is not enabled for WebSocket.

## Usage

```bash
orbit app:websocket credentials <app.instance> [--json]
```

## Examples

```bash
orbit app:websocket credentials docs.production
orbit app:websocket credentials docs.production --json
```

## Arguments and options

- `app.instance`: dotted app-instance selector. A bare app slug is shorthand
  only when exactly one eligible visible instance exists.
- `--json`: Select JSON output and non-interactive input only.

## What Happens

Run `app:websocket credentials` to retrieve the Reverb connection details for a
WebSocket-enabled app.

1. Resolves exactly one concrete app instance and its serving node.
2. Requires an existing and enabled WebSocket binding. Returns
   `websocket.binding_missing` when none exists or when the binding is not enabled.
3. Returns the full credentials payload: internal host, public hosts, allowed
   origins, and the Reverb `app_id`, `app_key`, and `app_secret`.

`app:websocket credentials` does not:
- Rotate or regenerate credentials.
- Mutate any binding state.

## Output

Human output presents the credentials payload with labeled fields including the
`reverb_app_secret`.

JSON output returns the credentials under the standard machine-readable
envelope. See the
[JSON renderer contract](technical/6.2_app-websocket-credentials_output-render_json.md)
for the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:credentials` on the selected instance's
  serving node.
- The selected app instance exists and has an enabled WebSocket binding.

## Related Commands

Use these commands alongside `app:websocket credentials` to enable or disable
WebSocket support for an app.

- [`app:websocket enable`](../12_app-websocket-enable/app-websocket-enable.md) — enable WebSocket support for an app
- [`app:websocket disable`](../13_app-websocket-disable/app-websocket-disable.md) — disable WebSocket support for an app

## Technical Contract

See [`app:websocket credentials` technical contract](technical/1_app-websocket-credentials.md).
