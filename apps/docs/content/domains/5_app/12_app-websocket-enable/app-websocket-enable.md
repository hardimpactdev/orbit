# `orbit app:websocket enable`

[Back to App commands.](../README.md)

Enable WebSocket support for an app.

`app:websocket enable` creates or updates the app WebSocket binding for a registered
app and syncs public host routes and the Reverb runtime app configuration. The
binding stores per-app Reverb credentials, allowed origins derived from the app
domain, and the public WebSocket hosts you supply. When a binding exists, the
command updates it; when none exists, the command creates one and generates
fresh Reverb credentials.

## Usage

```bash
orbit app:websocket enable <app> [--host=<hostname>] [--json]
```

## Examples

```bash
orbit app:websocket enable docs
orbit app:websocket enable docs --host=ws.docs.example.com
orbit app:websocket enable docs --host=ws.docs.example.com --host=events.docs.example.com
orbit app:websocket enable docs --json
```

## Arguments and options

- `app`: app name or hostname. Required.
- `--host`: public WebSocket hostname to bind. Repeatable. Values must be plain
  hostnames, not URLs. Duplicate values and empty values are silently discarded.
- `--json`: Output JSON.

## What Happens

Run `app:websocket enable` to attach an app to the fleet WebSocket service.

1. Resolves the app by name or hostname.
2. Creates the app WebSocket binding when none exists, generating a
   `reverb_app_id` (equal to the app name), a random `reverb_app_key`, and a
   random `reverb_app_secret`. Updates the existing binding when one is present.
3. Sets `enabled=true`, records the supplied public hosts, and derives the
   `allowed_origins` list from the app domain (`https://<domain>`).
4. Syncs the WebSocket service route on the router node and registers public
   host routes for each supplied hostname.
5. Syncs the Reverb runtime app configuration so the running Reverb node
   accepts connections for this app immediately.

`app:websocket enable` requires an active router node and at least one active
WebSocket backend node to be present in the fleet; the command returns
`websocket.prerequisite_failed` when these fleet prerequisites are not met.

`app:websocket enable` does not:
- Generate new Reverb credentials when a binding already exists. Credentials are
  kept stable across re-enables.
- Remove public host routes absent from the supplied list. To replace the host
  list, disable the binding with
  [`app:websocket disable`](../13_app-websocket-disable/app-websocket-disable.md)
  and re-enable with the updated host list.

## Output

Human output describes the resulting binding with the internal host, public
hosts, and allowed origins.

JSON output returns the binding payload under the standard machine-readable
envelope. See the
[JSON renderer contract](technical/6.2_app-websocket-enable_output-render_json.md)
for the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:write` on the app's owning node.
- An active router node and at least one active WebSocket backend node exist in
  the fleet.
- The app exists in the gateway registry.

## Related Commands

Use these commands alongside `app:websocket enable` to manage WebSocket support
and inspect app configuration.

- [`app:websocket disable`](../13_app-websocket-disable/app-websocket-disable.md) — disable WebSocket support for an app
- [`app:websocket credentials`](../14_app-websocket-credentials/app-websocket-credentials.md) — show Reverb credentials for a WebSocket-enabled app
- [`app:show`](../4_app-show/app-show.md) — inspect the full app entity

## Technical Contract

See [`app:websocket enable` technical contract](technical/1_app-websocket-enable.md).
